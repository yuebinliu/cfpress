<?php
/**
 * Plugin Name: CF Static Export Pro
 * Plugin URI: https://github.com/yourname/cf-static-export
 * Description: 高性能WordPress静态化方案 - 直接导出到Cloudflare R2 + D1
 * Version: 2.5.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: cf-static-export
 *
 * 2.5.0 更新：
 * - 列表每页文章数可配置（设置页 cf_posts_per_page，默认取 WP posts_per_page）
 * - taxonomy 表升级复合主键 (type, slug)；post_taxonomy 带 taxonomy_type，修复同名 category/tag slug 串数据
 * - ensure_d1_schema 幂等检测并迁移旧结构；新增“全量导出重建”按钮（重写结构化 categories/tags）
 * - Worker slug 匹配大小写容错
 * - 主题静态资源随 Worker 打包为 assets（assets-first：/wp-content/themes/... 由边缘免费直出，
 *   不再逐请求读 R2；部署前自动复制激活主题到 worker/static，提供“同步主题资源”按钮）
 * - 清理死代码、新增 uninstall.php
 */
if (!defined('ABSPATH')) {
    exit;
}

// ============================================
// 常量定义
// ============================================
define('CFSE_VERSION', '2.5.0');
define('CFSE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CFSE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CFSE_LOG_FILE', WP_CONTENT_DIR . '/cf-static-export.log');
define('CFSE_DEBUG_DIR', WP_CONTENT_DIR . '/cf-debug');
define('CFSE_WORKER_DIR', CFSE_PLUGIN_DIR . 'worker/src/');
// 本地静态导出目录（「生成到本地」的落盘点；结构与 R2 key 同构）
define('CFSE_LOCAL_DIR', WP_CONTENT_DIR . '/cfse-static');
// 「Worker 端诞生」的 wp_id 判据（见 ROADMAP I.1）：WP 侧自增 ID 远小于它，
// Worker 侧为 Date.now()*100+rand（≈1.7e14）⇒ 用于生成「待拉取清单」/标识来源。
define('CFSE_WORKER_ID_MIN', 100000000000);

// ============================================
// PHP版本检测
// ============================================
if (version_compare(PHP_VERSION, '7.0.0', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>CF Static Export Pro</strong> 需要 PHP 7.0 或更高版本，当前版本：' . esc_html(PHP_VERSION) . '，请升级PHP后再启用此插件。</p></div>';
    });
}

// ============================================
// 环境检测
// ============================================
if (!extension_loaded('curl')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>CF Static Export Pro</strong> 需要PHP cURL扩展，请开启cURL后再启用此插件。</p></div>';
    });
}

// ============================================
// 加载模板编辑器模块
// ============================================
$editor_path = CFSE_PLUGIN_DIR . 'views/template-editor.php';
if (is_admin() && file_exists($editor_path)) {
    include_once $editor_path;
    add_action('admin_menu', 'cfse_add_template_editor_menu');
    cfse_register_template_editor_hooks();
}

// ============================================
// 主类
// ============================================
class CF_Static_Export_Pro {

    private static $instance = null;
    private static $deleted_post_ids = array();
    // 默认值：内部抓取请求会在 load_config() 之前早退，而 log() 会读这两个键，
    // 先给上默认值可避免 PHP8 的 undefined array key warning（行为仍是"不写日志"）。
    private $config = array('enable_logging' => false, 'debug_mode' => false);


    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 内部抓取请求（插件自己抓自己的前台）：早退 —— 不挂后台/导出钩子、不读配置、不写文件。
        // 判据优先用请求头：URL 上不再带参数，避免参数被 WP 用「当前 URL」构造的链接回显进静态产物。
        // 保留 query 形态兼容（views/ 里的风格抓取仍在用 query）。
        $cfse_internal_req = (isset($_SERVER['HTTP_X_CF_EXPORT_INTERNAL']) && $_SERVER['HTTP_X_CF_EXPORT_INTERNAL'] === '1')
            || (isset($_GET['_cfse_internal']) && $_GET['_cfse_internal'] === '1');
        if ($cfse_internal_req) {
            // 抓取请求同样要渲染 md：否则「前台抓取」路径落盘的 md 文章是未渲染的原稿。
            add_filter('the_content', array($this, 'render_md_content'), 9);
            return;
        }
        $this->init_hooks();
        $this->load_config();
        $this->register_cron_jobs();
        $this->ensure_worker_files();
    }

    // ============================================
    // 确保 Worker 文件存在
    // ============================================
    private function ensure_worker_files() {
        $files = array(
            'routes.js' => CFSE_WORKER_DIR . 'routes.js',
            'templates.js' => CFSE_WORKER_DIR . 'templates.js',
        );
        foreach ($files as $name => $path) {
            if (!file_exists($path)) {
                $this->generate_initial_file($name);
            }
        }
    }

    private function generate_initial_file($name) {
        $content = $name === 'routes.js' 
            ? '// 路由规则 - 将由插件自动生成' . "\n" . '// 请点击"同步路由规则"生成完整内容' . "\n" . 'export async function handleRequest(request, env) {' . "\n" . '    return new Response(\'请先点击"同步路由规则"生成路由配置\', { status: 500 });' . "\n" . '}'
            : '// 模板文件 - 将由插件自动生成' . "\n" . '// 请点击"同步模板"生成完整内容' . "\n" . 'export function renderHomepage() {' . "\n" . '    return \'<h1>请先点击"同步模板"生成模板配置</h1>\';' . "\n" . '}';
        @file_put_contents(CFSE_WORKER_DIR . $name, $content);
    }

    // ============================================
    // 钩子初始化
    // ============================================
	private function init_hooks() {
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('save_post', array($this, 'export_post'), 10, 3);
		add_action('add_meta_boxes', array($this, 'add_doc_format_metabox'), 10, 2);
		add_action('admin_post_cfse_align_worker', array($this, 'handle_align_worker'));
		add_action('wp_ajax_cfse_worker_status', array($this, 'ajax_worker_status'));
		add_action('wp_ajax_cfse_test_targets', array($this, 'ajax_test_targets'));
		add_action('wp_ajax_cfse_publish_site', array($this, 'ajax_publish_site'));
		add_action('admin_post_cfse_deploy_git', array($this, 'handle_deploy_git'));
		// 优先级 5：必须早于导出钩子（save_post 优先级 10），否则本次导出读到的是旧标记
		add_action('save_post', array($this, 'save_doc_format_meta'), 5, 3);
		add_filter('the_content', array($this, 'render_md_content'), 9);
		add_action('before_delete_post', array($this, 'delete_post'));
		add_action('trash_post', array($this, 'delete_post'));
		add_action('untrash_post', array($this, 'export_post'));
		add_action('post_submitbox_misc_actions', array($this, 'add_manual_export_button'));
		add_action('admin_post_cf_manual_export', array($this, 'handle_manual_export'));
		add_action('admin_post_cf_bulk_export', array($this, 'handle_bulk_export'));
		add_action('admin_post_cf_generate_local', array($this, 'handle_generate_local'));
		add_action('admin_post_cf_publish_remote', array($this, 'handle_publish_remote'));
		add_action('admin_post_cf_pull_worker', array($this, 'handle_pull_worker'));
		add_action('admin_post_cf_clear_cache', array($this, 'handle_clear_cache'));
		add_action('admin_post_cf_sync_routes', array($this, 'handle_sync_routes'));
		add_action('admin_post_cf_sync_templates', array($this, 'handle_sync_templates'));
		add_action('admin_post_cf_seed_admin', array($this, 'handle_seed_admin'));
		add_action('admin_post_cf_deploy_worker', array($this, 'handle_deploy_worker'));
		add_action('admin_post_cf_stage_theme_assets', array($this, 'handle_stage_theme_assets'));
		add_action('admin_post_cf_sync_style_assets', array($this, 'handle_sync_style_assets'));
		add_action('wp_ajax_cf_test_connection', array($this, 'test_connection'));
		add_action('wp_ajax_cf_get_stats', array($this, 'get_stats_ajax'));
		add_filter('manage_posts_columns', array($this, 'add_export_column'));
		add_action('manage_posts_custom_column', array($this, 'render_export_column'), 10, 2);
		register_deactivation_hook(__FILE__, array($this, 'deactivate'));

		// 【FIX】已移除旧 wp_ajax_cf_analyze_theme / wp_ajax_cf_load_theme_template 钩子
	}
    // ============================================
    // 配置加载
    // ============================================
    private function load_config() {
        // 多托管配置：先算出目标列表，再由「首个 cfse-worker 目标」派生 site_url / auth_key。
        // 这样 d1_batch / purge 等仍只读 site_url/auth_key 的代码无需改动，行为与之前一致。
        $targets = $this->get_hosting_targets();
        $primary = array();
        foreach ($targets as $t) {
            if (isset($t['type']) && $t['type'] === 'cfse-worker') { $primary = $t; break; }
        }
        $primary_url = !empty($primary['endpoint']) ? $primary['endpoint'] : get_option('cf_site_url', get_site_url());
        $primary_key = isset($primary['auth_key']) ? $primary['auth_key'] : get_option('cf_auth_key', '');
        $this->config = array(
            'account_id' => get_option('cf_account_id', ''),
            'api_token' => get_option('cf_api_token', ''),
            'r2_bucket' => get_option('cf_r2_bucket', ''),
            'd1_database' => get_option('cf_d1_database', ''),
            'site_url' => rtrim($primary_url, '/'),
            'hosting_targets' => $targets,
            'export_mode' => get_option('cf_export_mode', 'auto'),
            'cache_ttl' => max(0, (int) get_option('cf_cache_ttl', 3600)),
            'clean_scripts' => (bool) get_option('cf_clean_dynamic_scripts', true),
            'batch_size' => max(1, (int) get_option('cf_batch_size', 50)),
            'export_concurrency' => max(1, (int) get_option('cf_export_concurrency', 4)),
            'max_retries' => max(1, (int) get_option('cf_max_retries', 3)),
            'enable_logging' => (bool) get_option('cf_enable_logging', true),
            'enable_compression' => (bool) get_option('cf_enable_compression', true),
            'debug_mode' => (bool) get_option('cf_debug_mode', false),
            'purge_sidebar' => (bool) get_option('cf_purge_sidebar', true),
            'purge_lists' => (bool) get_option('cf_purge_lists', false),
            // 缓存策略（纯 TTL 语义；Worker 端读取消费）：0 = 直查 D1 不缓存
            'cache_ttl_list' => max(0, (int) get_option('cf_cache_ttl_list', 0)),
            'cache_ttl_sidebar' => max(0, (int) get_option('cf_cache_ttl_sidebar', 0)),
            'cache_ttl_site' => max(0, (int) get_option('cf_cache_ttl_site', 604800)),
            'cache_ttl_feed' => max(0, (int) get_option('cf_cache_ttl_feed', 14400)),
            'feed_count' => min(100, max(1, (int) get_option('cf_feed_count', 20))),
            'auth_key' => $primary_key,
            'posts_per_page' => max(1, (int) get_option('cf_posts_per_page', 10)),
            // 编辑页是否自动比对 Worker 版本（默认关；打开后打开编辑页即查询，关则用面板里的「🔍 检查」按钮）
            'worker_auto_check' => (bool) get_option('cf_worker_auto_check', true),
            // 发布行为（三项独立、默认全不勾；③ 勾选时隐含 ②）：
            //   ① 同步到 D1（文章 + 草稿都写云端库）② 生成静态文件（本地 cfse-static/）③ 上传到远端（R2 + 清缓存）
            // 旧键（YY 批的 cf_sync_drafts / cf_auto_generate_single / cf_auto_publish_remote）作为默认值兼容迁移
            'sync_d1' => (bool) get_option('cf_sync_d1', get_option('cf_sync_drafts', false)),
            'gen_static' => (bool) get_option('cf_gen_static', get_option('cf_auto_generate_single', false)),
            'publish_remote' => (bool) get_option('cf_publish_remote', get_option('cf_auto_publish_remote', false)),
        );
    }
public function get_config() {
    return $this->config;
}

    // ============================================
    // 定时任务
    // ============================================
    private function register_cron_jobs() {
        add_action('cf_auto_export_cron', array($this, 'handle_auto_export'));
        if (!wp_next_scheduled('cf_auto_export_cron')) {
            wp_schedule_event(time(), 'daily', 'cf_auto_export_cron');
        }
    }

    // ============================================
    // 管理菜单
    // ============================================
    /**
     * 校验并序列化「托管目标」表单：cf_hosting_targets[N][field] → JSON 字符串。
     * 只接受已实现的类型（cfse-worker / local）；旧的 site_url + auth_key 仍作为兜底。
     */
    public function sanitize_hosting_targets($value) {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) $value = $decoded;
        }
        if (!is_array($value)) return '';
        $templates = $this->get_hosting_templates();
        $raw = $value;   // 归一化前的输入：一致性校验与"自动修正回显"要靠它发现用户显式填的冲突（§12.4）
        $out = array();
        foreach ($value as $t) {
            if (!is_array($t)) continue;
            // 1) 模板解析（§C1）：template 优先；仅有旧 type 时按 legacy_type 反查；未知模板丢弃（与运行期跳过一致）
            $tplId = isset($t['template']) ? (string) $t['template'] : '';
            $legacy = isset($t['type']) ? (string) $t['type'] : '';
            if ($tplId === '' && $legacy !== '') {
                foreach ($templates as $cand => $tp) {
                    if (isset($tp['legacy_type']) && $tp['legacy_type'] === $legacy) { $tplId = $cand; break; }
                }
            }
            if ($tplId === '' || !isset($templates[$tplId])) continue;
            $tpl = $templates[$tplId];
            $name = isset($t['name']) ? sanitize_text_field($t['name']) : '';
            $id = isset($t['id']) && $t['id'] !== '' ? sanitize_key($t['id']) : sanitize_title($name);
            if ($id === '') $id = 't' . count($out);
            // 2) 能力归一化（§12.7）：模板不支持 ⇒ false；未声明 ⇒ 模板默认；已声明 ⇒ 取值（受上限约束）
            $capsIn = (isset($t['capabilities']) && is_array($t['capabilities'])) ? $t['capabilities'] : array();
            $defCaps = isset($tpl['defaults']['capabilities']) ? $tpl['defaults']['capabilities'] : array();
            $caps = array();
            foreach ($tpl['capabilities'] as $ck => $limit) {
                $caps[$ck] = $limit
                    ? (array_key_exists($ck, $capsIn) ? !empty($capsIn[$ck]) : !empty($defCaps[$ck]))
                    : false;
            }
            // 3) 意图归一化：白名单 ∩ 模板可承接（模板不支持的路线不保存）
            $onIntent = $this->normalize_intent((isset($t['intent']) && is_array($t['intent'])) ? $t['intent'] : $tpl['defaults']['intent']);
            $intent = array();
            foreach (array('md', 'static-list', 'static-only', 'backup') as $ik) {
                if (in_array($ik, $onIntent, true) && in_array($ik, $tpl['intents'], true)) $intent[] = $ik;
            }
            $entry = array(
                'id' => $id,
                'name' => $name,
                'template' => $tplId,
                'type' => $tpl['legacy_type'],   // C3 双写：以模板为准重算 ⇒ 正常保存不可能产生不一致
                'enabled' => !empty($t['enabled']),
                'intent' => $intent,
                'path_prefix' => isset($t['path_prefix']) ? sanitize_text_field($t['path_prefix']) : '',
                'capabilities' => $caps,
            );
            if ($tplId === 'cloudflare') {
                $entry['endpoint'] = isset($t['endpoint']) ? esc_url_raw(trim($t['endpoint'])) : '';
                $entry['auth_key'] = isset($t['auth_key']) ? sanitize_text_field($t['auth_key']) : '';
                $entry['public_url'] = isset($t['public_url']) ? esc_url_raw(trim($t['public_url'])) : '';
            } elseif ($tplId === 's3') {
                $entry['endpoint'] = isset($t['endpoint']) ? esc_url_raw(trim($t['endpoint'])) : '';
                $entry['bucket'] = isset($t['bucket']) ? sanitize_text_field(trim($t['bucket'])) : '';
                $entry['region'] = isset($t['region']) && trim($t['region']) !== '' ? sanitize_text_field(trim($t['region'])) : 'auto';
                $entry['access_key'] = isset($t['access_key']) ? sanitize_text_field(trim($t['access_key'])) : '';
                $entry['secret_key'] = isset($t['secret_key']) ? sanitize_text_field(trim($t['secret_key'])) : '';
                $mode = isset($t['path_style']) ? (string) $t['path_style'] : 'auto';
                $entry['path_style'] = in_array($mode, array('auto', 'path', 'virtual'), true) ? $mode : 'auto';
                $entry['public_url'] = isset($t['public_url']) ? esc_url_raw(trim($t['public_url'])) : '';
            } elseif ($tplId === 'github') {
                $entry['repo'] = isset($t['repo']) ? sanitize_text_field(trim($t['repo'])) : '';
                $entry['token'] = isset($t['token']) ? sanitize_text_field(trim($t['token'])) : '';
                $br = isset($t['branch']) ? sanitize_text_field(trim($t['branch'])) : '';
                $entry['branch'] = $br !== '' ? $br : 'gh-pages';
                $entry['public_url'] = isset($t['public_url']) ? esc_url_raw(trim($t['public_url'])) : '';
            } elseif ($tplId === 'netlify') {
                $entry['site'] = isset($t['site']) ? sanitize_text_field(trim($t['site'])) : '';
                $entry['token'] = isset($t['token']) ? sanitize_text_field(trim($t['token'])) : '';
                $entry['public_url'] = isset($t['public_url']) ? esc_url_raw(trim($t['public_url'])) : '';
            } elseif ($tplId === 'vercel') {
                $entry['project'] = isset($t['project']) ? sanitize_text_field(trim($t['project'])) : '';
                $entry['token'] = isset($t['token']) ? sanitize_text_field(trim($t['token'])) : '';
                $entry['public_url'] = isset($t['public_url']) ? esc_url_raw(trim($t['public_url'])) : '';
            } elseif ($tplId === 'git') {
                $ab = isset($t['api_base']) && trim($t['api_base']) !== '' ? esc_url_raw(trim($t['api_base'])) : 'https://api.github.com';
                $entry['api_base'] = rtrim($ab, '/');
                $entry['repo'] = isset($t['repo']) ? sanitize_text_field(trim($t['repo'])) : '';
                $entry['token'] = isset($t['token']) ? sanitize_text_field(trim($t['token'])) : '';
                $br = isset($t['branch']) ? sanitize_text_field(trim($t['branch'])) : '';
                $entry['branch'] = $br !== '' ? $br : 'gh-pages';
                $entry['public_url'] = isset($t['public_url']) ? esc_url_raw(trim($t['public_url'])) : '';
            } elseif ($tplId === 'gitlab') {
                $entry['project'] = isset($t['project']) ? sanitize_text_field(trim($t['project'])) : '';
                $entry['token'] = isset($t['token']) ? sanitize_text_field(trim($t['token'])) : '';
                $br = isset($t['branch']) ? sanitize_text_field(trim($t['branch'])) : '';
                $entry['branch'] = $br !== '' ? $br : 'gh-pages';
                $entry['public_url'] = isset($t['public_url']) ? esc_url_raw(trim($t['public_url'])) : '';
            } elseif ($tplId === 'ftp') {
                $entry['host'] = isset($t['host']) ? sanitize_text_field(trim($t['host'])) : '';
                $entry['port'] = isset($t['port']) && intval($t['port']) > 0 ? intval($t['port']) : 21;
                $entry['user'] = isset($t['user']) ? sanitize_text_field(trim($t['user'])) : '';
                $entry['pass'] = isset($t['pass']) ? (string) $t['pass'] : '';
                $entry['dir'] = isset($t['dir']) ? trim((string) $t['dir']) : '';
                $entry['ftps'] = !empty($t['ftps']);
                $entry['public_url'] = isset($t['public_url']) ? esc_url_raw(trim($t['public_url'])) : '';
            } elseif ($tplId === 'webdav') {
                $entry['endpoint'] = isset($t['endpoint']) ? esc_url_raw(trim($t['endpoint'])) : '';
                $entry['user'] = isset($t['user']) ? sanitize_text_field(trim($t['user'])) : '';
                $entry['pass'] = isset($t['pass']) ? (string) $t['pass'] : '';
                $entry['public_url'] = isset($t['public_url']) ? esc_url_raw(trim($t['public_url'])) : '';
            } else { // local
                $entry['dir'] = isset($t['dir']) ? sanitize_text_field(trim($t['dir'])) : '';
                // local 也可配 public_url（本地目录被 web 服务托管时，日志里就能给出可访问地址）
                $entry['public_url'] = isset($t['public_url']) ? esc_url_raw(trim($t['public_url'])) : '';
            }
            $out[] = $entry;
        }
        // 4) 冷路径校验 + 回显（§12.4：自动修正必须可见；警告就地提示）
        $vr = $this->validate_hosting_targets(true, $raw);
        $notice = $this->render_adjustments_notice($vr['adjustments']);
        if ($notice !== '') {
            add_settings_error('cf_hosting_targets', 'cfse_adjusted', $notice, 'warning');
        }
        foreach ($vr['warnings'] as $w) {
            add_settings_error('cf_hosting_targets', 'cfse_warn_' . md5($w['key'] . $w['id']), '⚠ ' . $w['message'], 'warning');
        }
        return wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 设置页：托管目标表格（已有目标逐行渲染；新增行由页面内 JS 生成） */
    public function render_hosting_targets_table() {
        // 渲染必须包含「未启用」的目标：否则取消勾选后它就从界面上消失，再保存即被删除。
        $targets = $this->get_hosting_targets(true);
        // 冷路径校验（§12）：行内警告 + 集合级提示
        $vr = $this->validate_hosting_targets(false);
        $byId = array(); $global = array();
        foreach ($vr['warnings'] as $w) {
            if ($w['id'] === '(全部目标)') $global[] = $w; else $byId[$w['id']][] = $w;
        }
        ?>
        <div style="overflow-x:auto;">
        <table class="widefat striped" id="cfse-targets" style="max-width:100%;">
            <thead><tr>
                <th style="width:46px;">启用</th>
                <th style="width:140px;">名称</th>
                <th style="width:170px;">模板</th>
                <th>端点 / 目录（按模板）</th>
                <th style="width:130px;">Auth Key</th>
                <th style="width:90px;">路径前缀</th>
                <th style="width:170px;">能力</th>
                <th style="width:170px;">意图</th>
                <th style="width:46px;"></th>
            </tr></thead>
            <tbody id="cfse-targets-body">
            <?php foreach ($targets as $i => $t) {
                $tid = isset($t['id']) ? $t['id'] : '';
                $this->render_hosting_target_row($i, $t, isset($byId[$tid]) ? $byId[$tid] : array());
            } ?>
            </tbody>
        </table>
        </div>
        <?php if (!empty($global)) { ?>
            <div style="margin-top:8px;background:#fff8e5;border-left:3px solid #dba617;padding:8px 12px;">
                <?php foreach ($global as $w) { ?>
                    <div style="margin:2px 0;">⚠ <?php echo esc_html($w['message']); ?></div>
                <?php } ?>
            </div>
        <?php }
    }

    /** 单行（字段名 cf_hosting_targets[N][xxx]，与 sanitize 对应） */
    private function render_hosting_target_row($i, $t, $warns = array()) {
        $templates = $this->get_hosting_templates();
        $tplId = isset($t['template']) ? $t['template'] : (isset($t['type']) ? $t['type'] : 'cloudflare');
        if (!isset($templates[$tplId])) $tplId = 'cloudflare';
        $tpl = $templates[$tplId];
        $caps = isset($t['capabilities']) && is_array($t['capabilities']) ? $t['capabilities'] : $tpl['defaults']['capabilities'];
        $intent = isset($t['intent']) && is_array($t['intent']) ? $t['intent'] : $tpl['defaults']['intent'];
        $n = 'cf_hosting_targets[' . (int) $i . ']';
        ?>
        <tr class="cfse-target-row">
            <td><input type="checkbox" name="<?php echo esc_attr($n); ?>[enabled]" value="1" <?php checked(!empty($t['enabled'])); ?> /></td>
            <td>
                <input type="text" name="<?php echo esc_attr($n); ?>[name]" value="<?php echo esc_attr(isset($t['name']) ? $t['name'] : ''); ?>" style="width:100%;" placeholder="例如：主站" />
                <input type="hidden" name="<?php echo esc_attr($n); ?>[id]" value="<?php echo esc_attr(isset($t['id']) ? $t['id'] : ''); ?>" />
            </td>
            <td>
                <select name="<?php echo esc_attr($n); ?>[template]" class="cfse-target-template" style="width:100%;">
                    <?php foreach ($templates as $tid => $tp) { ?>
                        <option value="<?php echo esc_attr($tid); ?>" <?php selected($tplId, $tid); ?>><?php echo esc_html($tp['name']); ?></option>
                    <?php } ?>
                </select>
            </td>
            <td>
                <input type="text" class="cfse-f-endpoint" name="<?php echo esc_attr($n); ?>[endpoint]" value="<?php echo esc_attr(isset($t['endpoint']) ? $t['endpoint'] : ''); ?>" style="width:100%;" placeholder="https://你的站点" />
                <input type="text" class="cfse-f-dir" name="<?php echo esc_attr($n); ?>[dir]" value="<?php echo esc_attr(isset($t['dir']) ? $t['dir'] : ''); ?>" style="width:100%;margin-top:2px;" placeholder="留空 = <?php echo esc_attr(CFSE_LOCAL_DIR); ?>" />
                <div class="cfse-f-s3" style="margin-top:4px;display:none;">
                    <input type="text" name="<?php echo esc_attr($n); ?>[bucket]" value="<?php echo esc_attr(isset($t['bucket']) ? $t['bucket'] : ''); ?>" style="width:49%;" placeholder="bucket 名" />
                    <input type="text" name="<?php echo esc_attr($n); ?>[region]" value="<?php echo esc_attr(isset($t['region']) ? $t['region'] : 'auto'); ?>" style="width:49%;" placeholder="region（R2 填 auto）" />
                    <input type="text" name="<?php echo esc_attr($n); ?>[access_key]" value="<?php echo esc_attr(isset($t['access_key']) ? $t['access_key'] : ''); ?>" style="width:100%;margin-top:2px;" placeholder="Access Key（R2 为 32 位）" />
                    <input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[secret_key]" value="<?php echo esc_attr(isset($t['secret_key']) ? $t['secret_key'] : ''); ?>" style="width:100%;margin-top:2px;" placeholder="Secret Key（不会自动填充）" />
                    <input type="text" name="<?php echo esc_attr($n); ?>[public_url]" value="<?php echo esc_attr(isset($t['public_url']) ? $t['public_url'] : ''); ?>" style="width:100%;margin-top:2px;" placeholder="public_url：站点访问地址（如 https://cdn.example.com）" />
                    <select name="<?php echo esc_attr($n); ?>[path_style]" style="width:100%;margin-top:2px;">
                        <option value="auto" <?php selected(isset($t['path_style']) ? $t['path_style'] : 'auto', 'auto'); ?>>寻址方式：自动（按 endpoint 判断）</option>
                        <option value="path" <?php selected(isset($t['path_style']) ? $t['path_style'] : 'auto', 'path'); ?>>path-style（/bucket/key）</option>
                        <option value="virtual" <?php selected(isset($t['path_style']) ? $t['path_style'] : 'auto', 'virtual'); ?>>virtual-host（bucket.host/key）</option>
                    </select>
                </div>
                <div class="cfse-f-github" style="margin-top:4px;display:none;">
                    <input type="text" name="<?php echo esc_attr($n); ?>[repo]" value="<?php echo esc_attr(isset($t['repo']) ? $t['repo'] : ''); ?>" style="width:100%;" placeholder="仓库 owner/name（如 yuebinliu/cfse-pages-test）" />
                    <input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[token]" value="<?php echo esc_attr(isset($t['token']) ? $t['token'] : ''); ?>" style="width:100%;margin-top:2px;" placeholder="GitHub Token（需 repo 权限；建议细粒度 Contents: RW）" />
                    <input type="text" name="<?php echo esc_attr($n); ?>[branch]" value="<?php echo esc_attr(isset($t['branch']) ? $t['branch'] : 'gh-pages'); ?>" style="width:49%;margin-top:2px;" placeholder="分支（默认 gh-pages）" />
                    <span class="description" style="font-size:11px;">部署时创建/更新该分支（<b>不动默认分支</b>）；路径前缀下的旧文件会被清理</span>
                </div>
                <div class="cfse-f-netlify" style="margin-top:4px;display:none;">
                    <input type="text" name="<?php echo esc_attr($n); ?>[site]" value="<?php echo esc_attr(isset($t['site']) ? $t['site'] : ''); ?>" style="width:100%;" placeholder="Netlify Site ID（或站点名）" />
                    <input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[token]" value="<?php echo esc_attr(isset($t['token']) ? $t['token'] : ''); ?>" style="width:100%;margin-top:2px;" placeholder="Netlify Personal Access Token" />
                    <span class="description" style="font-size:11px;">digest 部署（sha1 增量）；注意 Netlify 部署限速 <b>3/分、100/天</b></span>
                </div>
                <div class="cfse-f-vercel" style="margin-top:4px;display:none;">
                    <input type="text" name="<?php echo esc_attr($n); ?>[project]" value="<?php echo esc_attr(isset($t['project']) ? $t['project'] : ''); ?>" style="width:100%;" placeholder="Vercel 项目名（project）" />
                    <input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[token]" value="<?php echo esc_attr(isset($t['token']) ? $t['token'] : ''); ?>" style="width:100%;margin-top:2px;" placeholder="Vercel Token" />
                    <span class="description" style="font-size:11px;">v13 deployments + v2/files（sha1 增量），部署后轮询至 READY</span>
                </div>
                <div class="cfse-f-git" style="margin-top:4px;display:none;">
                    <input type="text" name="<?php echo esc_attr($n); ?>[api_base]" value="<?php echo esc_attr(isset($t['api_base']) ? $t['api_base'] : 'https://api.github.com'); ?>" style="width:100%;" placeholder="API base（默认 https://api.github.com；Gitee：https://gitee.com/api/v5）" />
                    <input type="text" name="<?php echo esc_attr($n); ?>[repo]" value="<?php echo esc_attr(isset($t['repo']) ? $t['repo'] : ''); ?>" style="width:100%;margin-top:2px;" placeholder="仓库 owner/name" />
                    <input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[token]" value="<?php echo esc_attr(isset($t['token']) ? $t['token'] : ''); ?>" style="width:100%;margin-top:2px;" placeholder="Token" />
                    <input type="text" name="<?php echo esc_attr($n); ?>[branch]" value="<?php echo esc_attr(isset($t['branch']) ? $t['branch'] : 'gh-pages'); ?>" style="width:49%;margin-top:2px;" placeholder="分支（默认 gh-pages）" />
                </div>
                <div class="cfse-f-gitlab" style="margin-top:4px;display:none;">
                    <input type="text" name="<?php echo esc_attr($n); ?>[project]" value="<?php echo esc_attr(isset($t['project']) ? $t['project'] : ''); ?>" style="width:100%;" placeholder="GitLab 项目（group/project 或数字 ID）" />
                    <input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[token]" value="<?php echo esc_attr(isset($t['token']) ? $t['token'] : ''); ?>" style="width:100%;margin-top:2px;" placeholder="GitLab Token（api 权限）" />
                    <input type="text" name="<?php echo esc_attr($n); ?>[branch]" value="<?php echo esc_attr(isset($t['branch']) ? $t['branch'] : 'gh-pages'); ?>" style="width:49%;margin-top:2px;" placeholder="分支（默认 gh-pages）" />
                    <span class="description" style="font-size:11px;">commits API 的 actions[] <b>一次提交多文件</b>；Pages 需在项目里开启</span>
                </div>
                <div class="cfse-f-ftp" style="margin-top:4px;display:none;">
                    <input type="text" name="<?php echo esc_attr($n); ?>[host]" value="<?php echo esc_attr(isset($t['host']) ? $t['host'] : ''); ?>" style="width:49%;" placeholder="FTP 主机" />
                    <input type="text" name="<?php echo esc_attr($n); ?>[port]" value="<?php echo esc_attr(isset($t['port']) ? $t['port'] : 21); ?>" style="width:22%;margin-left:2%;" placeholder="端口(21)" />
                    <label style="margin-left:6px;font-size:12px;"><input type="checkbox" name="<?php echo esc_attr($n); ?>[ftps]" value="1" <?php checked(!empty($t['ftps'])); ?> /> FTPS(显式 TLS)</label>
                    <input type="text" name="<?php echo esc_attr($n); ?>[user]" value="<?php echo esc_attr(isset($t['user']) ? $t['user'] : ''); ?>" style="width:49%;margin-top:2px;" placeholder="用户名" />
                    <input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[pass]" value="<?php echo esc_attr(isset($t['pass']) ? $t['pass'] : ''); ?>" style="width:49%;margin-left:2%;margin-top:2px;" placeholder="密码" />
                    <input type="text" name="<?php echo esc_attr($n); ?>[dir]" value="<?php echo esc_attr(isset($t['dir']) ? $t['dir'] : ''); ?>" style="width:100%;margin-top:2px;" placeholder="远端根目录（如 /wwwroot 或 /public_html）" />
                    <span class="description" style="font-size:11px;">需要 PHP 的 ftp 扩展。<b>语义：增量覆盖</b> —— 只上传、<b>不删除</b>远端多余文件（旧文章/改名后的旧文件会残留，旧 URL 仍可访问）；需要严格同步请用 github/gitlab/netlify/vercel。</span>
                </div>
                <div class="cfse-f-webdav" style="margin-top:4px;display:none;">
                    <input type="text" name="<?php echo esc_attr($n); ?>[endpoint]" value="<?php echo esc_attr(isset($t['endpoint']) ? $t['endpoint'] : ''); ?>" style="width:100%;" placeholder="WebDAV 地址（如 https://dav.jianguoyun.com/dav/站点目录）" />
                    <input type="text" name="<?php echo esc_attr($n); ?>[user]" value="<?php echo esc_attr(isset($t['user']) ? $t['user'] : ''); ?>" style="width:49%;margin-top:2px;" placeholder="用户名" />
                    <input type="password" autocomplete="new-password" spellcheck="false" name="<?php echo esc_attr($n); ?>[pass]" value="<?php echo esc_attr(isset($t['pass']) ? $t['pass'] : ''); ?>" style="width:49%;margin-left:2%;margin-top:2px;" placeholder="密码（坚果云用应用密码）" />
                    <span class="description" style="font-size:11px;">MKCOL 建目录 + PUT 上传；坚果云请用「应用密码」。<b>语义：增量覆盖</b> —— 只上传、<b>不删除</b>远端多余文件（清理的 PROPFIND 递归删除尚未实现）。</span>
                </div>
            </td>
            <td><input type="text" class="cfse-f-key" name="<?php echo esc_attr($n); ?>[auth_key]" value="<?php echo esc_attr(isset($t['auth_key']) ? $t['auth_key'] : ''); ?>" style="width:100%;" /></td>
            <td><input type="text" name="<?php echo esc_attr($n); ?>[path_prefix]" value="<?php echo esc_attr(isset($t['path_prefix']) ? $t['path_prefix'] : ''); ?>" style="width:100%;" placeholder="（暂未使用）" /></td>
            <td style="white-space:nowrap;font-size:12px;">
                <?php foreach (array('r2' => '静态文件', 'd1' => '文章数据', 'render' => '渲染(render)', 'cache' => '清缓存') as $ck => $clabel) { ?>
                    <label<?php if (empty($tpl['capabilities'][$ck])) echo ' style="opacity:.45;" title="该模板不支持此能力"'; ?>>
                        <input type="checkbox" name="<?php echo esc_attr($n); ?>[capabilities][<?php echo esc_attr($ck); ?>]" value="1" <?php checked(!empty($caps[$ck])); ?><?php disabled(empty($tpl['capabilities'][$ck])); ?> /> <?php echo esc_html($clabel); ?>
                    </label><br/>
                <?php } ?>
            </td>
            <td style="white-space:nowrap;font-size:12px;">
                <?php foreach (array('md' => '服务 md', 'static-list' => '动态列表', 'static-only' => '静态 HTML', 'backup' => '备份') as $ik => $ilabel) { ?>
                    <label><input type="checkbox" name="<?php echo esc_attr($n); ?>[intent][<?php echo esc_attr($ik); ?>]" value="1" <?php checked(in_array($ik, $intent, true)); ?> /> <?php echo esc_html($ilabel); ?></label><br/>
                <?php } ?>
            </td>
            <td><button type="button" class="button cfse-del-target" title="删除该目标">✕</button></td>
        </tr>
        <?php if (!empty($warns)) { ?>
        <tr class="cfse-row-warn"><td colspan="9" style="background:#fff8e5;border-left:3px solid #dba617;padding:8px 12px;">
            <?php foreach ($warns as $w) { ?>
                <div style="margin:2px 0;">⚠ <?php echo esc_html($w['message']); ?>
                <?php if (!empty($w['fix']) && is_array($w['fix'])) { ?>
                    <button type="button" class="button button-small cfse-fix-btn" data-fix="<?php echo esc_attr(implode(',', $w['fix'])); ?>" style="margin-left:6px;">以模板为准修正 type</button>
                <?php } ?></div>
            <?php } ?>
        </td></tr>
        <?php } ?>
        <?php
    }

    public function add_admin_menu() {
        $hook = add_options_page(
            'CF静态导出Pro',
            'CF静态导出',
            'manage_options',
            'cf-static-export-pro',
            array($this, 'settings_page')
        );
        add_action("load-{$hook}", array($this, 'enqueue_admin_assets'));
    }

    public function register_settings() {
        $fields = array(
            'cf_account_id' => array('sanitize' => 'sanitize_text_field'),
            'cf_api_token' => array('sanitize' => 'sanitize_text_field'),
            'cf_r2_bucket' => array('sanitize' => 'sanitize_text_field'),
            'cf_d1_database' => array('sanitize' => 'sanitize_text_field'),
            'cf_site_url' => array('sanitize' => 'esc_url_raw'),
            'cf_export_mode' => array('sanitize' => 'sanitize_text_field', 'default' => 'auto'),
            'cf_cache_ttl' => array('sanitize' => 'intval', 'default' => 3600),
            'cf_clean_dynamic_scripts' => array('sanitize' => 'boolval', 'default' => true),
            'cf_batch_size' => array('sanitize' => 'intval', 'default' => 50),
            'cf_export_concurrency' => array('sanitize' => 'intval', 'default' => 4),
            'cf_max_retries' => array('sanitize' => 'intval', 'default' => 3),
            'cf_enable_logging' => array('sanitize' => 'boolval', 'default' => true),
            'cf_enable_compression' => array('sanitize' => 'boolval', 'default' => true),
            'cf_debug_mode' => array('sanitize' => 'boolval', 'default' => false),
            // 发布文章后即时清除缓存（WP 端导出时调用 Worker /purge-cache；默认 sidebar 开/列表页关）
            'cf_purge_sidebar' => array('sanitize' => 'boolval', 'default' => true),
            'cf_purge_lists' => array('sanitize' => 'boolval', 'default' => false),
            // 缓存策略（纯 TTL 语义；0=直查 D1 不缓存）
            'cf_cache_ttl_list' => array('sanitize' => 'intval', 'default' => 0),
            'cf_cache_ttl_sidebar' => array('sanitize' => 'intval', 'default' => 0),
            'cf_cache_ttl_site' => array('sanitize' => 'intval', 'default' => 604800),
            'cf_cache_ttl_feed' => array('sanitize' => 'intval', 'default' => 14400),
            'cf_feed_count' => array('sanitize' => 'intval', 'default' => 20),
            'cf_auth_key' => array('sanitize' => 'sanitize_text_field', 'default' => ''),
            // 列表每页文章数（默认取 WP「设置→阅读」的 posts_per_page，即 10）
            'cf_posts_per_page' => array('sanitize' => 'intval', 'default' => 10),
            'cf_worker_auto_check' => array('sanitize' => 'boolval', 'default' => true),
            'cf_sync_d1' => array('sanitize' => 'boolval', 'default' => 0),
            // 多托管目标（JSON 字符串；sanitize 负责把表单数组转成 JSON）
            'cf_hosting_targets' => array('sanitize' => array($this, 'sanitize_hosting_targets'), 'default' => ''),
            'cf_gen_static' => array('sanitize' => 'boolval', 'default' => 0),
            'cf_publish_remote' => array('sanitize' => 'boolval', 'default' => 0),
        );
        foreach ($fields as $key => $args) {
            // WP 的 register_setting() 第三个参数认的是 sanitize_callback —— 此前误写成 'sanitize'
            // （未知键被忽略），导致所有 cf_* 的 sanitize 从未生效：cf_hosting_targets 的表单数组
            // 被直接 maybe_serialize() 存成 PHP 序列化，而读取端只认 JSON 字符串 ⇒ 解析为空 ⇒
            // 目标列表退回兜底默认值（表现为「保存后消失」）。
            if (isset($args['sanitize'])) {
                $args['sanitize_callback'] = $args['sanitize'];
                unset($args['sanitize']);
            }
            register_setting('cf_static_export_pro', $key, $args);
        }
        // 任一设置保存后：把站点信息 + 缓存策略同步到 D1 settings（Worker 端读取）
        add_action('update_option_cf_cache_ttl_list', array($this, 'sync_site_settings'), 10, 0);
        add_action('update_option_cf_cache_ttl_sidebar', array($this, 'sync_site_settings'), 10, 0);
        add_action('update_option_cf_cache_ttl_site', array($this, 'sync_site_settings'), 10, 0);
        add_action('update_option_cf_cache_ttl_feed', array($this, 'sync_site_settings'), 10, 0);
        add_action('update_option_cf_feed_count', array($this, 'sync_site_settings'), 10, 0);
        add_action('update_option_cf_purge_lists', array($this, 'sync_site_settings'), 10, 0);
    }

    public function enqueue_admin_assets() {
        wp_enqueue_style('cfse-admin', CFSE_PLUGIN_URL . 'assets/admin.css', array(), CFSE_VERSION);
        wp_enqueue_script('cfse-admin', CFSE_PLUGIN_URL . 'assets/admin.js', array('jquery'), CFSE_VERSION, true);
        wp_localize_script('cfse-admin', 'cfse_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cfse_ajax_nonce'),
        ));
    }

    // ============================================
    // 设置页面
    // ============================================
    public function settings_page() {
        $stats = $this->get_export_stats();
        $routes_exists = file_exists(CFSE_WORKER_DIR . 'routes.js');
        $templates_exists = file_exists(CFSE_WORKER_DIR . 'templates.js');
        ?>
        <div class="wrap cfse-settings">
            <h1>☁️ CF静态导出Pro <span class="version">v<?php echo esc_html(CFSE_VERSION); ?></span></h1>

            <?php if (isset($_GET['exported']) && isset($_GET['total'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>✅ 批量导出完成！成功导出 <strong><?php echo intval($_GET['exported']); ?></strong> / <?php echo intval($_GET['total']); ?> 篇文章</p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['routes_synced']) && $_GET['routes_synced']): ?>
                <div class="notice notice-success is-dismissible"><p>✅ 路由规则已同步到本地 <code>worker/src/routes.js</code></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['templates_synced']) && $_GET['templates_synced']): ?>
                <div class="notice notice-success is-dismissible"><p>✅ 模板已同步到本地 <code>worker/src/templates.js</code></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['deployed']) && $_GET['deployed']): ?>
                <div class="notice notice-success is-dismissible"><p>✅ Worker 部署成功！（含主题资源 assets）</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['assets_staged']) && $_GET['assets_staged']): ?>
                <div class="notice notice-success is-dismissible"><p>✅ 主题资源已复制到 worker/static，点「🚀 初始化部署」随 Worker 上传。</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['style_assets_synced'])): $__sas = (int) $_GET['style_assets_synced']; ?>
                <div class="notice notice-<?php echo $__sas > 0 ? 'success' : 'warning'; ?> is-dismissible"><p><?php echo $__sas > 0 ? '✅ 已从 R2 同步 ' . $__sas . ' 个风格资源到 worker/static/style/，本地 wrangler deploy 后走 assets 直出。' : '⚠️ 未同步到风格资源（可能尚未上传过，或获取清单失败，详见日志）。'; ?></p></div>
            <?php endif; ?>

            <div class="cfse-grid">
                <div class="cfse-card">
                    <h2>⚙️ 基础配置</h2>
                    <form method="post" action="options.php">
                        <?php settings_fields('cf_static_export_pro'); ?>
                        <table class="form-table">
                            <tr><th><label for="cf_account_id">Account ID</label></th>
                                <td><input type="text" id="cf_account_id" name="cf_account_id" value="<?php echo esc_attr($this->config['account_id']); ?>" class="regular-text" />
                                    <p class="description">Cloudflare仪表盘右侧获取</p></td></tr>
                            <tr><th><label for="cf_api_token">API Token</label></th>
                                <td><input type="password" id="cf_api_token" name="cf_api_token" value="<?php echo esc_attr($this->config['api_token']); ?>" class="regular-text" />
                                    <p class="description">需要 D1:Edit, R2:Edit, Account:Read 权限</p>
                                    <button type="button" class="button" id="cf-test-connection">🔌 测试连接</button>
                                    <span id="cf-test-result"></span></td></tr>
                            <tr><th><label for="cf_r2_bucket">R2 Bucket</label></th>
                                <td><input type="text" id="cf_r2_bucket" name="cf_r2_bucket" value="<?php echo esc_attr($this->config['r2_bucket']); ?>" class="regular-text" /></td></tr>
                            <tr><th><label for="cf_d1_database">D1 Database ID</label></th>
                                <td><input type="text" id="cf_d1_database" name="cf_d1_database" value="<?php echo esc_attr($this->config['d1_database']); ?>" class="regular-text" /></td></tr>
                            <tr><th><label for="cf_site_url">静态站点URL</label></th>
                                <td><input type="url" id="cf_site_url" name="cf_site_url" value="<?php echo esc_attr($this->config['site_url']); ?>" class="regular-text" />
                                    <p class="description">Worker 所在域名</p></td></tr>
                            <tr><th><label for="cf_auth_key">Worker Auth Key</label></th>
                                <td><input type="password" id="cf_auth_key" name="cf_auth_key" value="<?php echo esc_attr($this->config['auth_key']); ?>" class="regular-text" />
                                    <p class="description">与 Worker 中 AUTH_KEY_SECRET 相同</p></td></tr>
                            <tr><th><label for="cf_export_mode">导出模式</label></th>
                                <td><select id="cf_export_mode" name="cf_export_mode">
                                        <option value="template" <?php selected($this->config['export_mode'], 'template'); ?>>内部模板渲染</option>
                                        <option value="fetch" <?php selected($this->config['export_mode'], 'fetch'); ?>>前台抓取</option>
                                        <option value="auto" <?php selected($this->config['export_mode'], 'auto'); ?>>自动降级（推荐）</option>
                                    </select></td></tr>
                            <tr><th><label for="cf_cache_ttl">单页缓存头</label></th>
                                <td><input type="number" id="cf_cache_ttl" name="cf_cache_ttl" value="<?php echo esc_attr($this->config['cache_ttl']); ?>" style="width:100px" min="0" /> 秒
                                    <p class="description">导出单页时写进 HTML 的 <code>&lt;meta http-equiv="Cache-Control"&gt;</code> —— 表示<b>浏览器 / CDN 可以缓存这一页多久</b>（默认 3600）。
                                    <b>它不是</b>下面「自托管 CMS 运行时」里的 KV 缓存（那套只管列表/侧边栏/站点信息/Feed）。
                                    三点注意：① 值写死在 HTML 里，<b>改完必须重新生成/导出单页才生效</b>；② 走 Worker 访问时以 <b>Worker 的响应头</b>为准（HTTP 头优先于 meta，此值基本不起作用）；
                                    ③ <b>纯静态档</b>（local / s3 / ftp / webdav，没有 Worker 加响应头）才主要依赖它。</p></td></tr>
                            <tr><th><label for="cf_batch_size">批量大小</label></th>
                                <td><input type="number" id="cf_batch_size" name="cf_batch_size" value="<?php echo esc_attr($this->config['batch_size']); ?>" style="width:100px" min="1" />
                                    <p class="description">每批导出文章数（影响导出耗时，不影响静态站分页）</p></td></tr>
                            <tr><th><label for="cf_posts_per_page">列表每页文章数</label></th>
                                <td><input type="number" id="cf_posts_per_page" name="cf_posts_per_page" value="<?php echo esc_attr($this->config['posts_per_page']); ?>" style="width:100px" min="1" />
                                    <p class="description">首页/分类/标签/归档每页显示多少篇，用于计算总页数与分页导航（默认与 WP「设置→阅读」一致 = 10）。保存后需点一次「🔄 同步路由规则」生效。</p></td></tr>
                            <tr><th><label for="cf_max_retries">上传重试次数</label></th>
                                <td><input type="number" id="cf_max_retries" name="cf_max_retries" value="<?php echo esc_attr($this->config['max_retries']); ?>" style="width:100px" min="1" /></td></tr>
                            <tr><th><label for="cf_export_concurrency">全量导出并发数</label></th>
                                <td><input type="number" id="cf_export_concurrency" name="cf_export_concurrency" value="<?php echo esc_attr($this->config['export_concurrency']); ?>" style="width:100px" min="1" max="8" />
                                    <p class="description">「🔄 全量导出」时并行进程数（1~8，默认 4）。并发越高全量越快，同时占用更多服务器资源；设为 1 即逐篇串行。R2/D1 写入为幂等覆盖，并发安全。</p></td></tr>
                            <tr><th>发布行为</th>
                                <td>
                                    <p class="description">控制「在 WP 侧保存 / 发布文章」时自动做什么。<b>三项独立、默认全不勾</b> —— 都不勾时 WP 侧保存文章不动远端（内容由 Worker 后台或第三方编辑器如 Obsidian 管理）。</p>
                                    <label><input type="checkbox" name="cf_sync_d1" value="1" <?php checked($this->config['sync_d1']); ?> /> ① <b>同步到 D1</b>（<b>文章和草稿</b>都写入云端数据库；列表页实时渲染与 Worker 后台读它。草稿只写 <code>status=draft</code>，不产单页）</label><br/>
                                    <label><input type="checkbox" name="cf_gen_static" value="1" id="cf-gen-static" <?php checked($this->config['gen_static']); ?> /> ② <b>生成静态文件</b>（渲染落盘到 <code>cfse-static/</code>，结构与 R2 同构；「纯静态托管」用它 —— 整目录搬到任意对象存储即成站）</label><br/>
                                    <label><input type="checkbox" name="cf_publish_remote" value="1" id="cf-pub-remote" <?php checked($this->config['publish_remote']); ?> onchange="if(this.checked){var g=document.getElementById('cf-gen-static'); if(g) g.checked=true;}" /> ③ <b>上传到远端</b>（R2 单页 + 清缓存；<b>勾选它等于同时启用 ②</b>）</label>
                                    <p class="description" style="margin-top:4px;">说明：<b>md 文章只写 D1、不上传 R2 单页</b> —— 单页由 Worker 渲染（R2 未命中时按需渲染并回填）。只勾 ③ 而没勾 ① 时，Worker 端列表/后台看不到该篇（列表读 D1）。
                                </td></tr>
                            <tr><th>导出与渲染</th>
                                <td>
                                    <p class="description">这两个用法都有效（无论你发布到自托管 CMS 还是纯静态托管）。</p>
                                    <label><input type="checkbox" name="cf_clean_dynamic_scripts" value="1" <?php checked($this->config['clean_scripts']); ?> /> 移除动态脚本</label><br/>
                                    <label><input type="checkbox" name="cf_enable_logging" value="1" <?php checked($this->config['enable_logging']); ?> /> 启用日志</label><br/>
                                    <label><input type="checkbox" name="cf_enable_compression" value="1" <?php checked($this->config['enable_compression']); ?> /> 启用Gzip压缩</label><br/>
                                    <label><input type="checkbox" name="cf_debug_mode" value="1" <?php checked($this->config['debug_mode']); ?> /> 调试模式</label>
                                </td></tr>
                            <tr><th>⚙️ 自托管 CMS 运行时<br/><span style="font-weight:400;font-size:11px;color:#646970;">仅 cfse-worker 目标</span></th>
                                <td>
                                    <p class="description" style="background:#f0f6fc;border-left:3px solid #72aee6;padding:6px 8px;">
                                        下面这些<b>只对「自托管 CMS（cfse-worker 目标）」生效</b> —— 它们控制 Worker 端的 KV 缓存与两端版本比对。
                                        <b>纯静态档（local / s3 / ftp / webdav）不使用</b>：没有 KV、也没有 Worker 接口可调，配了也不会被执行。
                                    </p>
                                    <label><input type="checkbox" name="cf_purge_sidebar" value="1" <?php checked($this->config['purge_sidebar']); ?> /> 发布后即时刷新侧边栏（成本极小，建议开启）</label><br/>
                                    <label><input type="checkbox" name="cf_purge_lists" value="1" <?php checked($this->config['purge_lists']); ?> /> 发布后即时刷新列表页第一页缓存（首页/分类/标签/归档；高更新大站建议关闭，靠缓存自然过期）</label><br/>
                                    <span class="description" style="font-size:11px;margin-left:22px;">↑ 这两项只在<b>对应 TTL &gt; 0</b>（即该部分确实在缓存）时才有意义 —— TTL=0 本就是每请求直查 D1，无需刷新。</span><br/>
                                    <label><input type="checkbox" name="cf_worker_auto_check" value="1" <?php checked($this->config['worker_auto_check']); ?> /> 编辑页自动比对 Worker 版本（默认开：打开编辑页即只读查询两端版本时间，是否需要覆盖由你在面板里决定）</label>
                                    <div style="margin-top:10px;border-top:1px solid #f0f0f1;padding-top:8px;">
                                        <p class="description"><b>缓存策略</b>（Worker 动态页的 KV 缓存）—— 纯 TTL 语义：<b>0 = 直查 D1 不缓存</b>（最省 KV 写入额度，推荐默认）。常见组合：全 D1 = 列表 0 / 侧边栏 0；混合 = 列表 0 / 侧边栏 86400；全 KV = 列表 3600 / 侧边栏 86400（仅高流量建议，注意 KV 免费写额度 1000/天）。<b>运行时调整请在 Worker 后台「设置 → 缓存策略」操作</b>（保存即生效，以最后保存的一侧为准）。
                                        注意：<b>单页永远直读 R2、不走这套缓存</b>（单页的浏览器缓存由上面的「单页缓存头」控制）。</p>
                                        <div style="margin-top:6px;">
                                            <label>列表页 TTL <input type="number" name="cf_cache_ttl_list" value="<?php echo esc_attr($this->config['cache_ttl_list']); ?>" style="width:90px" min="0" /> 秒</label>
                                            <label style="margin-left:12px;">侧边栏 TTL <input type="number" name="cf_cache_ttl_sidebar" value="<?php echo esc_attr($this->config['cache_ttl_sidebar']); ?>" style="width:90px" min="0" /> 秒</label>
                                            <label style="margin-left:12px;">站点信息 TTL <input type="number" name="cf_cache_ttl_site" value="<?php echo esc_attr($this->config['cache_ttl_site']); ?>" style="width:90px" min="0" /> 秒</label>
                                            <label style="margin-left:12px;">Feed TTL <input type="number" name="cf_cache_ttl_feed" value="<?php echo esc_attr($this->config['cache_ttl_feed']); ?>" style="width:90px" min="0" /> 秒</label>
                                            <label style="margin-left:12px;">Feed 条数 <input type="number" name="cf_feed_count" value="<?php echo esc_attr($this->config['feed_count']); ?>" style="width:70px" min="1" max="100" /></label>
                                        </div>
                                    </div>
                                </td></tr>
                        </table>
                        <div class="cfse-card" style="margin:16px 0 0;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px;">
                            <h2 style="margin-top:0;">🎯 托管目标</h2>
                            <p class="description">先选<b>模板</b>（= 托管方，决定能力上限：Cloudflare 支持 R2/D1/渲染/清缓存，S3 与本地目录只支持写文件），
                                再在模板允许范围内勾<b>能力</b>与<b>意图</b>。例如「只想全静态」就选 Cloudflare 模板、只勾「静态文件」——这是完全正常的配置，不会报警告。
                                没有目标具备「文章数据」能力时写库会被跳过（不报错）。目标列表留空时，用上面的「站点 URL + Auth Key」当作默认 Worker 目标兜底。</p>
                            <?php $this->render_hosting_targets_table(); ?>
                            <p style="margin-top:8px;">
                                <button type="button" class="button" id="cfse-add-target">＋ 添加目标</button>
                                <button type="button" class="button" id="cfse-test-targets">🔌 测试连通性</button>
                                <span id="cfse-test-result" class="description" style="margin-left:8px;"></span>
                                <span class="description" style="margin-left:8px;">（测试用的是<b>已保存</b>的配置，改动后请先保存）</span>
                            </p>
                            <script>
                            (function(){
                              var body = document.getElementById('cfse-targets-body');
                              if (!body) return;
                              var idx = body.querySelectorAll('tr.cfse-target-row').length;
                              // 字段联动：按「模板」显隐字段组（B7②）
                              //   cloudflare ⇒ endpoint + auth_key
                              //   s3         ⇒ endpoint + s3 组（隐藏 auth_key）
                              //   local      ⇒ dir（隐藏 endpoint / auth_key / s3）
                              function syncRow(tr){
                                var tpl = tr.querySelector('.cfse-target-template');
                                var ep = tr.querySelector('.cfse-f-endpoint');
                                var dir = tr.querySelector('.cfse-f-dir');
                                var key = tr.querySelector('.cfse-f-key');
                                var s3 = tr.querySelector('.cfse-f-s3');
                                var v = tpl ? tpl.value : 'cloudflare';
                                var isLocal = (v === 'local');
                                var isS3 = (v === 's3');
                                var isGithub = (v === 'github');
                                if (ep) ep.style.display = (isLocal || isGithub) ? 'none' : '';
                                if (key && key.parentNode) key.parentNode.style.display = (isLocal || isS3 || isGithub) ? 'none' : '';
                                if (dir) dir.style.display = isLocal ? '' : 'none';
                                if (s3) s3.style.display = isS3 ? '' : 'none';
                                var gh = tr.querySelector('.cfse-f-github');
                                if (gh) gh.style.display = isGithub ? '' : 'none';
                                var deployKinds = { 'netlify': '.cfse-f-netlify', 'vercel': '.cfse-f-vercel', 'git': '.cfse-f-git', 'gitlab': '.cfse-f-gitlab', 'ftp': '.cfse-f-ftp', 'webdav': '.cfse-f-webdav' };
                                Object.keys(deployKinds).forEach(function (k) {
                                  var el = tr.querySelector(deployKinds[k]);
                                  if (el) el.style.display = (v === k) ? '' : 'none';
                                });
                              }
                              function bind(tr){
                                var tpl = tr.querySelector('.cfse-target-template');
                                if (tpl) tpl.addEventListener('change', function(){ syncRow(tr); });
                                var del = tr.querySelector('.cfse-del-target');
                                if (del) del.addEventListener('click', function(){ tr.parentNode.removeChild(tr); });
                                syncRow(tr);
                              }
                              Array.prototype.forEach.call(body.querySelectorAll('tr.cfse-target-row'), bind);
                              var addBtn = document.getElementById('cfse-add-target');
                              if (addBtn) addBtn.addEventListener('click', function(){
                                var tmp = document.createElement('tbody');
                                tmp.innerHTML = '<tr class="cfse-target-row">' +
                                  '<td><input type="checkbox" name="cf_hosting_targets[' + idx + '][enabled]" value="1" checked /></td>' +
                                  '<td><input type="text" name="cf_hosting_targets[' + idx + '][name]" style="width:100%;" placeholder="例如：备份站" /><input type="hidden" name="cf_hosting_targets[' + idx + '][id]" value="" /></td>' +
                                  '<td><select name="cf_hosting_targets[' + idx + '][template]" class="cfse-target-template" style="width:100%;"><option value="cloudflare">Cloudflare（Worker + R2 + D1）</option><option value="s3">S3 兼容对象存储</option><option value="local">本地目录</option><option value="github">GitHub Pages（git 推送）</option><option value="git">通用 git（Gitee/Gitea）</option><option value="gitlab">GitLab Pages</option><option value="netlify">Netlify</option><option value="vercel">Vercel</option><option value="ftp">FTP / FTPS</option><option value="webdav">WebDAV（坚果云等）</option></select></td>' +
                                  '<td><input type="text" class="cfse-f-endpoint" name="cf_hosting_targets[' + idx + '][endpoint]" style="width:100%;" placeholder="https://你的站点" /><input type="text" class="cfse-f-dir" name="cf_hosting_targets[' + idx + '][dir]" style="width:100%;margin-top:2px;" placeholder="留空 = 默认本地目录" />' +
                                    '<div class="cfse-f-s3" style="margin-top:4px;display:none;">' +
                                      '<input type="text" name="cf_hosting_targets[' + idx + '][bucket]" style="width:49%;" placeholder="bucket 名" />' +
                                      '<input type="text" name="cf_hosting_targets[' + idx + '][region]" style="width:49%;" placeholder="region（R2 填 auto）" value="auto" />' +
                                      '<input type="text" name="cf_hosting_targets[' + idx + '][access_key]" style="width:100%;margin-top:2px;" placeholder="Access Key（R2 为 32 位）" />' +
                                      '<input type="password" autocomplete="new-password" spellcheck="false" name="cf_hosting_targets[' + idx + '][secret_key]" style="width:100%;margin-top:2px;" placeholder="Secret Key（不会自动填充）" />' +
                                      '<input type="text" name="cf_hosting_targets[' + idx + '][public_url]" style="width:100%;margin-top:2px;" placeholder="public_url：站点访问地址" />' +
                                      '<select name="cf_hosting_targets[' + idx + '][path_style]" style="width:100%;margin-top:2px;"><option value="auto">寻址方式：自动</option><option value="path">path-style</option><option value="virtual">virtual-host</option></select>' +
                                    '</div></td>' +
                                  '<span class="cfse-f-github" style="display:none;">' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][repo]" style="width:100%;margin-top:2px;" placeholder="仓库 owner/name" />' +
                                    '<input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][token]" style="width:100%;margin-top:2px;" placeholder="GitHub Token（需 repo 权限）" />' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][branch]" style="width:49%;margin-top:2px;" placeholder="分支（默认 gh-pages）" />' +
                                  '</span>' +
                                  '<span class="cfse-f-netlify" style="display:none;margin-top:2px;">' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][site]" style="width:100%;" placeholder="Netlify Site ID（或站点名）" />' +
                                    '<input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][token]" style="width:100%;margin-top:2px;" placeholder="Netlify Token" />' +
                                  '</span>' +
                                  '<span class="cfse-f-vercel" style="display:none;margin-top:2px;">' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][project]" style="width:100%;" placeholder="Vercel 项目名" />' +
                                    '<input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][token]" style="width:100%;margin-top:2px;" placeholder="Vercel Token" />' +
                                  '</span>' +
                                  '<span class="cfse-f-git" style="display:none;margin-top:2px;">' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][api_base]" style="width:100%;" placeholder="API base（默认 https://api.github.com）" />' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][repo]" style="width:100%;margin-top:2px;" placeholder="仓库 owner/name" />' +
                                    '<input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][token]" style="width:100%;margin-top:2px;" placeholder="Token" />' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][branch]" style="width:49%;margin-top:2px;" placeholder="分支（默认 gh-pages）" />' +
                                  '</span>' +
                                  '<span class="cfse-f-gitlab" style="display:none;margin-top:2px;">' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][project]" style="width:100%;" placeholder="GitLab 项目（group/project）" />' +
                                    '<input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][token]" style="width:100%;margin-top:2px;" placeholder="GitLab Token" />' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][branch]" style="width:49%;margin-top:2px;" placeholder="分支（默认 gh-pages）" />' +
                                  '</span>' +
                                  '<span class="cfse-f-ftp" style="display:none;margin-top:2px;">' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][host]" style="width:49%;" placeholder="FTP 主机" />' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][port]" style="width:22%;margin-left:2%;" placeholder="端口 21" value="21" />' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][user]" style="width:49%;margin-top:2px;" placeholder="用户名" />' +
                                    '<input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][pass]" style="width:49%;margin-left:2%;margin-top:2px;" placeholder="密码" />' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][dir]" style="width:100%;margin-top:2px;" placeholder="远端根目录（如 /wwwroot）" />' +
                                  '</span>' +
                                  '<span class="cfse-f-webdav" style="display:none;margin-top:2px;">' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][endpoint]" style="width:100%;" placeholder="WebDAV 地址" />' +
                                    '<input type="text" name="cf_hosting_targets[' + idx + '][user]" style="width:49%;margin-top:2px;" placeholder="用户名" />' +
                                    '<input type="password" autocomplete="new-password" name="cf_hosting_targets[' + idx + '][pass]" style="width:49%;margin-left:2%;margin-top:2px;" placeholder="密码" />' +
                                  '</span>' +
                                  '<td><input type="text" class="cfse-f-key" name="cf_hosting_targets[' + idx + '][auth_key]" style="width:100%;" /></td>' +
                                  '<td><input type="text" name="cf_hosting_targets[' + idx + '][path_prefix]" style="width:100%;" /></td>' +
                                  '<td style="white-space:nowrap;font-size:12px;">' +
                                    '<label><input type="checkbox" name="cf_hosting_targets[' + idx + '][capabilities][r2]" value="1" checked /> 静态文件</label><br/>' +
                                    '<label><input type="checkbox" name="cf_hosting_targets[' + idx + '][capabilities][d1]" value="1" checked /> 文章数据</label><br/>' +
                                    '<label><input type="checkbox" name="cf_hosting_targets[' + idx + '][capabilities][render]" value="1" checked /> 渲染(render)</label><br/>' +
                                    '<label><input type="checkbox" name="cf_hosting_targets[' + idx + '][capabilities][cache]" value="1" checked /> 清缓存</label>' +
                                  '</td>' +
                                  '<td style="white-space:nowrap;font-size:12px;">' +
                                    '<label><input type="checkbox" name="cf_hosting_targets[' + idx + '][intent][md]" value="1" checked /> 服务 md</label><br/>' +
                                    '<label><input type="checkbox" name="cf_hosting_targets[' + idx + '][intent][static-list]" value="1" checked /> 动态列表</label><br/>' +
                                    '<label><input type="checkbox" name="cf_hosting_targets[' + idx + '][intent][static-only]" value="1" /> 静态 HTML</label><br/>' +
                                    '<label><input type="checkbox" name="cf_hosting_targets[' + idx + '][intent][backup]" value="1" /> 备份</label>' +
                                  '</td>' +
                                  '<td><button type="button" class="button cfse-del-target">✕</button></td>' +
                                '</tr>';
                                var tr = tmp.firstElementChild;
                                body.appendChild(tr);
                                bind(tr);
                                idx++;
                              });
                              var testBtn = document.getElementById('cfse-test-targets');
                              if (testBtn) testBtn.addEventListener('click', function(){
                                var out = document.getElementById('cfse-test-result');
                                out.textContent = '测试中…';
                                jQuery.post(ajaxurl, { action: 'cfse_test_targets', _ajax_nonce: '<?php echo esc_js(wp_create_nonce('cfse_test_targets')); ?>' }, function(r){
                                  if (!r || !r.success) { out.textContent = '测试失败'; return; }
                                  out.innerHTML = (r.data.results || []).map(function(x){
                                    return (x.ok ? '✅ ' : '❌ ') + x.name + '：' + x.detail;
                                  }).join('　|　');
                                }).fail(function(){ out.textContent = '请求失败'; });
                              });
                            })();
                            </script>

                        </div>
                        <?php submit_button('💾 保存配置'); ?>
                    </form>
                </div>

                <div class="cfse-card">
                    <h2>📊 状态面板</h2>
                    <div class="cfse-stats">
                        <div class="stat-item"><span class="stat-label">总文章</span><span class="stat-value"><?php echo esc_html($stats['total_posts']); ?></span></div>
                        <div class="stat-item"><span class="stat-label">已导出</span><span class="stat-value"><?php echo esc_html($stats['exported_posts']); ?></span></div>
                        <div class="stat-item"><span class="stat-label">待导出</span><span class="stat-value"><?php echo esc_html($stats['pending_posts']); ?></span></div>
                        <div class="stat-item"><span class="stat-label">最后导出</span><span class="stat-value"><?php echo esc_html($stats['last_export']); ?></span></div>
                    </div>
                    <div class="cfse-progress">
                        <div class="progress-bar" style="width: <?php echo esc_attr($stats['progress']); ?>%;"><?php echo esc_html(round($stats['progress'], 1)); ?>%</div>
                    </div>

                    <div class="cfse-actions">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_bulk_export')); ?>" style="display:inline;">
                            <?php wp_nonce_field('cf_bulk_export'); ?>
                            <button type="submit" class="button button-primary" onclick="return confirm('将全量导出全部文章并重建 D1 分类/标签元数据（含已导出的），确认继续？')">🔄 全量导出（重建元数据）</button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_clear_cache')); ?>" style="display:inline;">
                            <?php wp_nonce_field('cf_clear_cache'); ?>
                            <button type="submit" class="button" onclick="return confirm('确认清除所有缓存？')">🗑️ 清除缓存</button>
                        </form>
                    </div>

                    <div class="cfse-card" style="margin-top:16px;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:12px;">
                        <h3 style="margin-top:0;">📦 内容发布（生成 / 上传 分离）</h3>
                        <p class="description">
                            两步走：先「生成到本地」（只渲染并落盘，不联网、不写 D1），检查无误后再「上传到远端」（上传 R2 + 写 D1 + 清缓存）。<br/>
                            本地目录：<code><?php echo esc_html(CFSE_LOCAL_DIR); ?></code>（结构与 R2 key 同构）。纯静态托管（无 D1/无运行时渲染）只用到第一步：把该目录整体搬到任意对象存储即可。
                        </p>
                        <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_generate_local')); ?>" style="display:inline;">
                                <?php wp_nonce_field('cf_generate_local'); ?>
                                <button type="submit" class="button" onclick="return confirm('将全部已发布文章生成到本地目录（不上传、不写 D1），确认继续？')">📥 生成到本地</button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_publish_remote')); ?>" style="display:inline;">
                                <?php wp_nonce_field('cf_publish_remote'); ?>
                                <button type="submit" class="button button-primary" onclick="return confirm('读取本地已生成的 HTML 并上传到远端（上传 R2 + 写 D1 + 清缓存），确认继续？')">🚀 上传到远端</button>
                            </form>
                        </div>
                        <?php if (isset($_GET['local_generated'])): ?>
                            <div class="notice notice-success is-dismissible" style="margin:10px 0 0;"><p>✅ 生成到本地完成：成功 <?php echo intval($_GET['local_generated']); ?> / <?php echo isset($_GET['total']) ? intval($_GET['total']) : 0; ?> 篇。</p></div>
                        <?php elseif (isset($_GET['published'])): ?>
                            <div class="notice notice-success is-dismissible" style="margin:10px 0 0;"><p>🚀 上传到远端完成：成功 <?php echo intval($_GET['published']); ?> / <?php echo isset($_GET['total']) ? intval($_GET['total']) : 0; ?> 篇（本地无产物的篇会被跳过，见日志）。</p></div>
                        <?php endif; ?>
                    </div>

                    <div class="cfse-card" style="margin-top:16px;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:12px;">
                        <h3 style="margin-top:0;">🌐 发布整站到托管商（目录级出口）</h3>
                        <?php
                        $cfse_r2_names = array();
                        foreach ($this->get_hosting_targets() as $cfse_t) {
                            if (!empty($cfse_t['capabilities']['r2'])) {
                                $cfse_r2_names[] = (isset($cfse_t['name']) && $cfse_t['name'] !== '')
                                    ? $cfse_t['name']
                                    : (isset($cfse_t['id']) ? $cfse_t['id'] : '?');
                            }
                        }
                        ?>
                        <p class="description">
                            把一个<b>完整的静态产物目录</b>整体发布到具备「静态文件」能力的目标（首页/列表页/资源/图片一并发布）。<br/>
                            与上面的「📦 内容发布」的区别：那是<b>逐篇文章</b>的生成/上传；这里是<b>整个目录树</b>——用于把外部生成器的产物直接托管出去。
                        </p>
                        <p style="margin:8px 0 4px;">
                            <label style="font-size:12px;">产物目录：<input type="text" id="cfse-site-dir" class="regular-text" style="width:70%;" value="<?php echo esc_attr(CFSE_LOCAL_DIR); ?>" /></label>
                        </p>
                        <p class="description" style="font-size:11px;">
                            仅允许 <code><?php echo esc_html(CFSE_LOCAL_DIR); ?></code> 与 <code><?php echo esc_html(WP_CONTENT_DIR); ?></code> 下的子目录（<b>不含 plugins / themes / mu-plugins 源码目录</b>）。
                            Simply Static 的产物通常在 <code>wp-content/uploads/simply-static/temp-files/&lt;最近一次&gt;/</code>。
                        </p>
                        <p style="margin:6px 0;font-size:12px;">
                            当前生效目标（具备静态文件能力）：<b><?php echo $cfse_r2_names ? esc_html(implode('、', $cfse_r2_names)) : '（无，请先在「🎯 托管目标」中配置）'; ?></b>
                        </p>
                        <div style="margin-top:10px;">
                            <button type="button" class="button button-primary" id="cfse-publish-site-btn">🚀 发布整站</button>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cfse_deploy_git')); ?>" style="display:inline;margin-left:8px;">
                                <?php wp_nonce_field('cfse_deploy_git'); ?>
                                <input type="hidden" name="cfse_git_dir" value="<?php echo esc_attr(CFSE_LOCAL_DIR); ?>" />
                                <button type="submit" class="button" onclick="return confirm('把该目录部署到所有启用中的「部署型」目标（GitHub Pages / GitLab / Netlify / Vercel / 通用 git / FTP / WebDAV）？仓库类目标不动默认分支。')">📦 部署到托管商</button>
                            </form>
                            <span id="cfse-publish-site-status" style="margin-left:10px;font-size:12px;color:#50575e;"></span>
                        </div>
                        <div id="cfse-publish-site-detail" style="margin-top:8px;font-size:12px;color:#50575e;"></div>
                        <script>
                        (function(){
                          var btn = document.getElementById('cfse-publish-site-btn');
                          if (!btn) return;
                          var nonce = '<?php echo esc_js(wp_create_nonce('cfse_publish_site')); ?>';
                          btn.addEventListener('click', function(){
                            var dirEl = document.getElementById('cfse-site-dir');
                            var st = document.getElementById('cfse-publish-site-status');
                            var dt = document.getElementById('cfse-publish-site-detail');
                            var dir = dirEl ? dirEl.value : '';
                            if (!dir) { alert('请填写产物目录'); return; }
                            if (!confirm('将把该目录整体发布到已启用的托管目标（同名文件会被覆盖），确认继续？')) return;
                            btn.disabled = true;
                            var offset = 0, okAll = 0, failAll = 0, bytesAll = 0, total = 0, first = true;
                            function step(){
                              var body = new URLSearchParams();
                              body.append('action', 'cfse_publish_site');
                              body.append('_ajax_nonce', nonce);
                              body.append('dir', dir);
                              body.append('offset', String(offset));
                              body.append('limit', '200');
                              if (first) body.append('reset', '1');
                              st.textContent = '发布中… ' + offset + (total ? (' / ' + total) : '');
                              fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
                                .then(function(r){ return r.json(); })
                                .then(function(j){
                                  if (!j || !j.success) {
                                    btn.disabled = false;
                                    st.textContent = '❌ ' + ((j && j.data && j.data.error) ? j.data.error : '请求失败');
                                    return;
                                  }
                                  var d = j.data; first = false;
                                  total = d.total; okAll += d.ok; failAll += d.failed; bytesAll += d.bytes;
                                  offset = d.next_offset;
                                  dt.textContent = '目标目录：' + d.dir
                                    + ((d.errors && d.errors.length) ? (' ｜ 错误示例：' + d.errors.join(' ; ')) : '');
                                  if (d.done) {
                                    btn.disabled = false;
                                    st.textContent = '✅ 完成：' + okAll + ' 成功 / ' + failAll + ' 失败 / 共 ' + total
                                      + ' 个文件，' + Math.round(bytesAll / 1024) + ' KB';
                                  } else { step(); }
                                })
                                .catch(function(e){ btn.disabled = false; st.textContent = '❌ ' + e; });
                            }
                            step();
                          });
                        })();
                        </script>
                    </div>

                    <div class="cfse-card" style="margin-top:16px;background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:12px;">
                        <h3 style="margin-top:0;">☁️ 与 Worker 同步（拉取）</h3>
                        <p class="description">
                            把「Worker 端诞生」的文章（<code>wp_id</code> 为时间戳形态 —— 即 Worker 后台 / 第三方编辑器新建的）拉回 WP 编辑。<br/>
                            按<b>远端 ID 记录</b>（meta <code>_cfse_remote_wp_id</code>）幂等：已拉过的篇会<b>跳过</b>，不会重复建文、也不会覆盖 WP 现有内容。
                            拉回后该篇即由 <b>WP 托管</b>（此后 Worker 侧的改动会被下次导出覆盖）。分类/标签按 slug 复用或新建。
                        </p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_pull_worker')); ?>" style="margin-top:8px;">
                            <?php wp_nonce_field('cf_pull_worker'); ?>
                            <label style="display:block;margin-bottom:6px;font-size:12px;">
                                <input type="checkbox" name="cfse_pull_overwrite" value="1" />
                                覆盖 WP 中已存在的同 ID 文章（<b>以 Worker 版本为准</b>）—— 用于把 Worker 端的最新改动对齐回来；不勾则只新增、已存在的跳过
                            </label>
                            <button type="submit" class="button" onclick="return confirm('从 Worker 拉取 Worker 端诞生的文章到 WordPress，确认继续？')">📥 从 Worker 拉取文章</button>
                        </form>
                        <?php if (isset($_GET['pulled'])): ?>
                            <div class="notice notice-success is-dismissible" style="margin:10px 0 0;"><p>☁️ 拉取完成：共 <?php echo isset($_GET['pull_total']) ? intval($_GET['pull_total']) : 0; ?> 篇，新增 <?php echo intval($_GET['pulled']); ?> 篇，对齐更新 <?php echo isset($_GET['pull_updated']) ? intval($_GET['pull_updated']) : 0; ?> 篇，跳过 <?php echo isset($_GET['pull_skipped']) ? intval($_GET['pull_skipped']) : 0; ?> 篇。</p></div>
                        <?php endif; ?>
                    </div>

        <div style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">
            <h3>👤 Worker 后台账号</h3>
            <p class="description">
                为 Worker 管理后台创建登录用户（PBKDF2-SHA256 存入 D1 <code>users</code> 表）。用户名默认取本站第一个管理员；
                密码仅本次提交时使用、不落 WordPress。可在 Worker 后台登录后修改。
            </p>
            <?php $seed_admin = get_users(array('role' => 'administrator', 'number' => 1)); $seed_u = !empty($seed_admin) ? $seed_admin[0] : null; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_seed_admin')); ?>" style="margin-top:6px;">
                <?php wp_nonce_field('cf_seed_admin'); ?>
                <label>用户名 <input type="text" name="cf_admin_username" value="<?php echo esc_attr($seed_u ? $seed_u->user_login : 'admin'); ?>" /></label>
                &nbsp;
                <label>初始密码 <input type="password" name="cf_admin_password" autocomplete="new-password" required /></label>
                &nbsp;
                <label>邮箱 <input type="email" name="cf_admin_email" value="<?php echo esc_attr($seed_u ? $seed_u->user_email : ''); ?>" /></label>
                <button type="submit" class="button button-primary">👤 写入 Worker 后台用户</button>
            </form>
            <?php if (isset($_GET['seeded']) && $_GET['seeded'] === '1'): ?>
                <div class="notice notice-success is-dismissible" style="margin:8px 0 0;"><p>✅ Worker 后台用户已写入 D1（需「🚀 初始化部署」后，Worker 的 /api/login 才能用新用户登录）。</p></div>
            <?php elseif (isset($_GET['seeded']) && $_GET['seeded'] === '0'): ?>
                <div class="notice notice-error is-dismissible" style="margin:8px 0 0;"><p>❌ 写入失败：用户名无效或密码少于 8 位。</p></div>
            <?php endif; ?>
        </div>

        <div style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">
                        <h3>🔧 Worker 代码管理</h3>
                        <p class="description">
                            本地 Worker 代码位于 <code><?php echo esc_html(CFSE_WORKER_DIR); ?></code>
                            <?php if ($routes_exists): ?>✅ routes.js<?php else: ?>❌ routes.js<?php endif; ?>
                            <?php if ($templates_exists): ?>✅ templates.js<?php else: ?>❌ templates.js<?php endif; ?>
                        </p>
                        <div style="margin: 10px 0; display: flex; gap: 10px; flex-wrap: wrap;">
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_sync_routes')); ?>" style="display:inline;">
                                <?php wp_nonce_field('cf_sync_routes'); ?>
                                <button type="submit" class="button">🔄 同步路由规则</button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_sync_templates')); ?>" style="display:inline;">
                                <?php wp_nonce_field('cf_sync_templates'); ?>
                                <button type="submit" class="button">🎨 同步模板</button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_stage_theme_assets')); ?>" style="display:inline;">
                                <?php wp_nonce_field('cf_stage_theme_assets'); ?>
                                <button type="submit" class="button">🎨 同步主题资源</button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_sync_style_assets')); ?>" style="display:inline;">
                                <?php wp_nonce_field('cf_sync_style_assets'); ?>
                                <button type="submit" class="button">🧩 同步风格资源</button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php?action=cf_deploy_worker')); ?>" style="display:inline;">
                                <?php wp_nonce_field('cf_deploy_worker'); ?>
                                <button type="submit" class="button button-primary">🚀 初始化部署</button>
                            </form>
                        </div>
                        <p class="description" style="font-size: 12px; color: #999;">
                            💡 模板请通过「🎨 模板编辑器」修改（保存后自动生成 templates.js）；编辑后点"初始化部署"生效。
                            <a href="<?php echo esc_url(admin_url('options-general.php?page=cf-template-editor')); ?>" class="button button-small" style="margin-left: 10px;">🎨 模板编辑器</a>
                        </p>
                    </div>

                    <div class="cfse-logs">
                        <h3>📋 最近日志</h3>
                        <pre><?php echo esc_html($this->get_recent_logs(10)); ?></pre>
                    </div>
                </div>
            </div>
        </div>
        <style>
        .cfse-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(280px, 360px); gap: 20px; margin-top: 20px; align-items: start; }
        .cfse-grid > .cfse-card { min-width: 0; }
        .cfse-grid .cfse-card code, .cfse-grid .stat-value { overflow-wrap: anywhere; word-break: break-word; }
        .cfse-card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .cfse-card h2 { margin-top: 0; border-bottom: 2px solid #f0f0f0; padding-bottom: 10px; }
        .cfse-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .stat-item { background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center; }
        .stat-label { display: block; color: #666; font-size: 12px; text-transform: uppercase; }
        .stat-value { display: block; font-size: 24px; font-weight: bold; margin-top: 5px; }
        .cfse-progress { margin: 15px 0; background: #f0f0f0; border-radius: 20px; overflow: hidden; }
        .progress-bar { background: linear-gradient(90deg, #4CAF50, #8BC34A); color: #fff; padding: 8px 15px; text-align: right; font-size: 12px; transition: width 0.3s; }
        .cfse-actions { display: flex; gap: 10px; margin: 15px 0; flex-wrap: wrap; }
        .cfse-logs pre { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 6px; overflow: auto; max-height: 200px; font-size: 12px; line-height: 1.5; }
        .version { font-size: 14px; color: #666; font-weight: normal; }
        @media (max-width: 1200px) { .cfse-grid { grid-template-columns: 1fr; } }
        </style>
        <?php
    }

    // ============================================
    // 统计和日志
    // ============================================
    private function get_export_stats() {
        $post_counts = wp_count_posts('post');
        $total_posts = isset($post_counts->publish) ? (int) $post_counts->publish : 0;
        $exported = (int) get_option('cf_exported_count', 0);
        $last_export = get_option('cf_last_export_time', '从未');
        return array(
            'total_posts' => $total_posts,
            'exported_posts' => min($exported, $total_posts),
            'pending_posts' => max(0, $total_posts - $exported),
            'last_export' => $last_export === '从未' ? '从未' : human_time_diff(strtotime($last_export)) . '前',
            'progress' => $total_posts > 0 ? ($exported / $total_posts * 100) : 0,
        );
    }

    private function get_recent_logs($lines = 10) {
        if (!file_exists(CFSE_LOG_FILE)) return '暂无日志';
        $logs = @file(CFSE_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($logs === false) return '无法读取日志文件';
        $recent = array_slice($logs, -$lines);
        return implode("\n", $recent);
    }

    public function log($message, $level = 'INFO') {
        if (!$this->config['enable_logging']) return;
        $timestamp = current_time('Y-m-d H:i:s');
        @file_put_contents(CFSE_LOG_FILE, "[{$timestamp}] [{$level}] {$message}\n", FILE_APPEND | LOCK_EX);
    }

    // ============================================
    // 核心：获取文章的实际存储路径
    // ============================================
/**
 * 修复文章存储路径生成
 */
private function get_post_storage_path($post) {
    $permalink = get_permalink($post);
    $home_url = home_url('/');
    $relative = str_replace($home_url, '/', $permalink);
    $relative = trim($relative, '/');
    
    if (empty($relative)) {
        return 'index';
    }
    
    // 提取slug
    $parts = explode('/', $relative);
    $slug = end($parts);
    
    // 根据Permalink结构生成路径
    $structure = get_option('permalink_structure');
    $pattern = $this->permalink_to_storage_pattern($structure);
    
    // 提取日期信息
    $year = get_the_date('Y', $post);
    $month = get_the_date('m', $post);
    $day = get_the_date('d', $post);
    
    $path = $pattern;
    $path = str_replace('{year}', $year, $path);
    $path = str_replace('{month}', $month, $path);
    $path = str_replace('{day}', $day, $path);
    $path = str_replace('{slug}', $slug, $path);
    $path = str_replace('{id}', $post->ID, $path);
    
    return $path;
}

    // ============================================
    // 核心导出逻辑
    // ============================================
    public function export_post($post_id, $post = null, $update = null) {
        // 拉取 Worker 文章期间跳过回写（内容本就取自 Worker，回写是无谓开销）
        if (!empty($GLOBALS['cfse_skip_export'])) return;
        if (!empty($GLOBALS['cfse_in_export'])) {
            $this->log("文章 {$post_id} 跳过：已在导出流程中", 'DEBUG');
            return;
        }
        $GLOBALS['cfse_in_export'] = true;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (wp_is_post_autosave($post_id)) return;
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            $GLOBALS['cfse_in_export'] = false;
            return;
        }
        if (get_post_meta($post_id, '_cfse_probe', true) === '1') {
            $this->log("文章 {$post_id} 为外观探针文章，跳过导出", 'DEBUG');
            $GLOBALS['cfse_in_export'] = false;
            return;
        }
        // 发布行为三开关（设置页「发布行为」）：① 同步到 D1 ② 生成静态文件 ③ 上传到远端（③ 隐含 ②）
        $sync_d1 = !empty($this->config['sync_d1']);
        $pub_remote = !empty($this->config['publish_remote']);
        $gen_static = !empty($this->config['gen_static']) || $pub_remote;
        if ($post->post_status === 'draft') {
            if (!$sync_d1) {
                $this->log("文章 {$post_id} 为草稿，未勾选「同步到 D1」，跳过", 'DEBUG');
                $GLOBALS['cfse_in_export'] = false;
                return;
            }
        } elseif ($post->post_status !== 'publish') {
            $GLOBALS['cfse_in_export'] = false;
            return;
        } elseif (!$sync_d1 && !$gen_static && !$pub_remote) {
            $this->log("文章 {$post_id} 未勾选任何发布行为（同步到 D1 / 生成静态文件 / 上传到远端），跳过", 'DEBUG');
            $GLOBALS['cfse_in_export'] = false;
            return;
        }
        if (!extension_loaded('curl')) {
            $this->log("cURL扩展未安装", 'ERROR');
            $GLOBALS['cfse_in_export'] = false;
            return false;
        }
        $lock_key = "cf_export_lock_{$post_id}";
        if (get_transient($lock_key)) {
            $this->log("文章 {$post_id} 正在导出中，跳过", 'WARNING');
            $GLOBALS['cfse_in_export'] = false;
            return;
        }
        set_transient($lock_key, true, 60);
        try {
            $this->log("开始导出文章 {$post_id}: {$post->post_title}");
            $start_time = microtime(true);
            $produced_single = false;
            if ($post->post_status === 'draft') {
                $result = $this->sync_draft_to_d1($post);
            } else {
                $result = $this->export_post_by_switches($post, $sync_d1, $gen_static, $pub_remote);
                // 「已导出」= 真的产出了静态单页（② 生成静态文件 或 ③ 上传到远端）；只勾 ① 不算
                $produced_single = ($gen_static || $pub_remote);
            }
            $duration = round(microtime(true) - $start_time, 2);
            if ($result) {
                update_option('cf_last_export_time', current_time('mysql'));
                if ($produced_single) {
                    $this->log("文章 {$post_id} 导出成功，耗时 {$duration}s", 'INFO');
                    $this->increment_exported_count();
                    update_post_meta($post_id, '_cf_exported', time());
                    delete_post_meta($post_id, '_cfse_synced_d1');
                } else {
                    // 仅同步到 D1（文章/草稿入库，但没产出静态单页）
                    $this->log("文章 {$post_id} 已同步到 D1（未生成静态单页），耗时 {$duration}s", 'INFO');
                    update_post_meta($post_id, '_cfse_synced_d1', time());
                }
            } else {
                $this->log("文章 {$post_id} 导出失败", 'ERROR');
            }
            return $result;
        } catch (Throwable $e) {
            $this->log("文章 {$post_id} 导出异常: " . $e->getMessage(), 'ERROR');
            return false;
        } finally {
            delete_transient($lock_key);
            $GLOBALS['cfse_in_export'] = false;
        }
    }

    /**
     * 阶段 1：组装文章元数据（分类/标签 + D1 行数据），不生成 HTML、不联网。
     */
    private function build_post_meta($post) {
        // 分类/标签取结构化数据：name + slug（供条目模板渲染分类/标签行）
        $categories = $this->get_post_terms($post->ID, 'category');
        $tags = $this->get_post_terms($post->ID, 'post_tag');

        // 内容格式：由文章 meta 决定（WP 后台「自定义字段」手填，或 tool/index.php 的「MD」模式写入
        // content_format=md）；字段名与 D1 posts.content_format 同名。缺失即 html —— 零误判，历史文章行为不变。
        $content_format = $this->get_content_format($post->ID);

        // 摘要：md 原稿先渲染成 HTML 再取纯文本，避免摘要里出现 ## 之类的标记
        $excerpt = $post->post_excerpt
            ? ($content_format === 'md' ? $this->md_to_plain($post->post_excerpt) : $post->post_excerpt)
            : $this->extract_excerpt($content_format === 'md' ? $this->md_to_plain($post->post_content) : $post->post_content, 200);

        $post_data = array(
            // D1 的主键 wp_id：Worker 端诞生的篇用它原来的远端 ID（记在 meta），否则用 WP 自身 ID
            'wp_id' => ($this->get_remote_wp_id($post->ID) ?: $post->ID),
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'content_format' => $content_format,
            'excerpt' => $excerpt,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'categories' => wp_json_encode($categories, JSON_UNESCAPED_UNICODE),
            'tags' => wp_json_encode($tags, JSON_UNESCAPED_UNICODE),
            'featured_image' => get_the_post_thumbnail_url($post->ID, 'full') ?: '',
            'status' => ($post->post_status === 'draft' ? 'draft' : 'published')
        );

        return array(
            'post' => $post,
            'post_data' => $post_data,
            'categories' => $categories,
            'tags' => $tags,
        );
    }

    /**
     * 阶段 1 + 生成：元数据 + 渲染 HTML + 存储路径（不联网）。
     */
    private function build_post_artifact($post) {
        $artifact = $this->build_post_meta($post);
        $html = $this->generate_post_html($post);
        if (!$html) throw new Exception('HTML生成失败');
        $this->log("HTML 生成成功，大小: " . strlen($html) . " 字节", 'DEBUG');
        $artifact['html'] = $html;
        // get_post_storage_path() 已返回含 index.html 的完整文件路径（如 made/index.html）
        $artifact['path'] = $this->get_post_storage_path($post);
        return $artifact;
    }

    /**
     * 阶段 1b：把产物写到本地导出目录（纯静态托管/离线检查用；不上传、不写 D1）。
     * 目录结构与 R2 key 同构：WP_CONTENT_DIR/cfse-static/<storagePath>
     */
    private function write_artifact_local($artifact) {
        $target = CFSE_LOCAL_DIR . '/' . ltrim($artifact['path'], '/');
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new Exception('本地目录创建失败: ' . $dir);
        }
        $guard = CFSE_LOCAL_DIR . '/index.php';
        if (!file_exists($guard)) @file_put_contents($guard, "<?php // Silence is golden.\n");
        if (@file_put_contents($target, $artifact['html']) === false) {
            throw new Exception('本地写入失败: ' . $target);
        }
        $this->log("本地生成: {$target} (" . strlen($artifact['html']) . " 字节)", 'INFO');
        return $target;
    }

    /**
     * 该篇的「家」是否在 Worker 端（归属判据 = wp_id 形态，见 CFSE_WORKER_ID_MIN）。
     * 这类文章由 Worker 侧 writeSinglePage() 渲染单页并维护 D1，WP 导出不得覆盖。
     */
    private function is_worker_owned_post($post) {
        $remote = $this->get_remote_wp_id($post->ID);
        return ($remote && $remote >= CFSE_WORKER_ID_MIN);
    }

    /**
     * 阶段 2：发布到远端（上传 R2 + 同步媒体 + 写 D1 + 清缓存）。
     *
     * @param array       $artifact    build_post_artifact()/build_post_meta() 的返回值（后者须自带 path）
     * @param string|null $html_source 非 null 时用它作为正文（「上传到远端」= 用本地已生成的 HTML）
     */
    private function publish_artifact($artifact, $html_source = null, $write_d1 = true) {
        $post = $artifact['post'];
        $html = $html_source === null ? (isset($artifact['html']) ? $artifact['html'] : '') : $html_source;
        if ($html === '') throw new Exception('正文为空，无法发布');

        // Worker 端诞生的文章（md 编辑器的家）：跳过 R2 上传与 D1 覆盖 —— 否则会把 Worker 渲染的
        // md 单页换成 WP 前台 HTML，并把 D1 里的 md 原稿冲掉（A 节候选 1 的硬保证）
        if ($this->is_worker_owned_post($post)) {
            $this->log("文章 {$post->ID} 由 Worker 端托管（远端 wp_id=" . $this->get_remote_wp_id($post->ID) . "），跳过 R2 上传与 D1 覆盖", 'INFO');
            return true;
        }

        $content_type = 'text/html; charset=utf-8';
        $content_encoding = null;
        if ($this->config['enable_compression']) {
            $html = gzencode($html, 6);
            $content_encoding = 'gzip';
        }
        $r2_path = $artifact['path'];

        // md 文章：只写 D1，不上传 R2 单页 —— 单页由 Worker 端渲染（发布/重建时写，或路由未命中时按需渲染）。
        // 这样「WP 只当 CMS 后台」也能工作：数据进 D1，单页交给 Worker，两边不会互相覆盖。
        $art_fmt = isset($artifact['post_data']['content_format'])
            ? $artifact['post_data']['content_format']
            : $this->get_content_format($post->ID);
        if ($art_fmt === 'md') {
            // md 文章：不上传 R2，并清除已存在的旧单页（交给 Worker 按需渲染最新内容）
            $this->invalidate_md_single_page($post);
        } else {
            $uploaded = false;
            for ($i = 0; $i < $this->config['max_retries']; $i++) {
                if ($this->upload_to_r2_via_worker($r2_path, $html, $content_type, $content_encoding)) {
                    $uploaded = true;
                    break;
                }
                $this->log("R2上传重试 " . ($i + 1) . "/{$this->config['max_retries']}", 'WARNING');
                usleep(500000);
            }
            if (!$uploaded) throw new Exception('R2上传失败');
        }

        $this->sync_media_files($post->post_content);

        if ($write_d1) {
            $written = false;
            for ($i = 0; $i < $this->config['max_retries']; $i++) {
                if ($this->write_to_d1($artifact['post_data'], $artifact['categories'], $artifact['tags'])) {
                    $written = true;
                    break;
                }
                $this->log("D1写入重试 " . ($i + 1) . "/{$this->config['max_retries']}", 'WARNING');
                usleep(500000);
            }
            if (!$written) throw new Exception('D1写入失败');
        } else {
            $this->log("文章 {$post->ID} 未勾选「同步到 D1」，跳过写库（仅上传 R2）", 'INFO');
        }

        // 发布成功即按设置页开关清除相关缓存（sidebar 默认清；列表页第一页默认关，靠缓存自然过期）
        $this->purge_page_caches();

        return true;
    }

    /**
     * md 文章的单页由 Worker 渲染：数据一变，就必须**清除已存在的 R2 单页**，
     * 否则访问到的是上一次渲染的旧内容（D1 已更新、页面却没变）。
     * 清掉后由 Worker 的「单页按需渲染」用最新 D1 内容现场渲染并回填。
     */
    private function invalidate_md_single_page($post) {
        if ($this->get_content_format($post->ID) !== 'md') return;
        $path = $this->get_post_storage_path($post);
        $this->delete_from_r2(array($path, $path . '.gz'));
        $this->log("文章 {$post->ID} 为 md：已清除旧单页（下次访问由 Worker 按需渲染最新内容）", 'INFO');
    }

    /**
     * 按设置页三项开关执行（只渲染一次 HTML）：
     *   ① $sync_d1     写 D1（数据入库；列表页/Worker 后台读它）
     *   ② $gen_static  渲染落盘到 CFSE_LOCAL_DIR
     *   ③ $pub_remote  上传 R2（md 文章在 publish_artifact 内部跳过 R2）+ 清缓存；写不写 D1 由 ① 决定
     */
    private function export_post_by_switches($post, $sync_d1, $gen_static, $pub_remote) {
        $artifact = null;
        if ($gen_static || $pub_remote) {
            $artifact = $this->build_post_artifact($post);
            if ($gen_static) $this->write_artifact_local($artifact);
        }
        if ($pub_remote) {
            return $this->publish_artifact($artifact, null, $sync_d1);
        }
        if ($sync_d1) {
            $meta = $this->build_post_meta($post);
            $ok = $this->write_to_d1($meta['post_data'], $meta['categories'], $meta['tags']);
            $this->log("文章 {$post->ID} 仅同步到 D1：" . ($ok ? '成功' : '失败'), $ok ? 'INFO' : 'ERROR');
            if ($ok) $this->invalidate_md_single_page($post);   // md：清了旧单页，访问时才能看到新内容
            return $ok;
        }
        return true;
    }

    /**
     * 草稿同步：只写 D1（status=draft），不生成单页、不上传 R2。
     * 用途：让 WP 侧的草稿在 Worker 后台也能看到/管理（前台列表只显示 published，不受影响）。
     */
    private function sync_draft_to_d1($post) {
        $meta = $this->build_post_meta($post);
        $meta['post_data']['status'] = 'draft';
        if (empty($meta['post_data']['slug'])) {
            $meta['post_data']['slug'] = 'draft-' . $post->ID;
            $this->log("草稿 {$post->ID} 尚无 slug，使用占位 slug '{$meta['post_data']['slug']}'", 'DEBUG');
        }
        $ok = $this->write_to_d1($meta['post_data'], $meta['categories'], $meta['tags']);
        $this->log("草稿 {$post->ID} 同步到 D1（status=draft）：" . ($ok ? '成功' : '失败'), $ok ? 'INFO' : 'ERROR');
        return $ok;
    }

    /**
     * 默认链路（save_post 自动导出 / 全量导出）：生成 + 发布，行为与拆分前完全一致。
     */
    private function do_export_post($post) {
        return $this->publish_artifact($this->build_post_artifact($post));
    }

    /**
     * 「生成到本地」：只生成并落盘，不联网（纯静态托管的前半步）。
     * public —— 供 tools/bulk-export-worker.php 子进程调用。
     */
    public function generate_post_local($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') {
            $this->log("文章 {$post_id} 跳过本地生成（非已发布文章）", 'DEBUG');
            return false;
        }
        try {
            $this->write_artifact_local($this->build_post_artifact($post));
            return true;
        } catch (Throwable $e) {
            $this->log("文章 {$post_id} 本地生成失败: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * 「上传到远端」：读取本地已生成的 HTML 并发布（上传 R2 + 写 D1 + 清缓存）。
     * 本地无该篇产物时跳过并返回 false —— 提示「请先生成到本地」。
     * public —— 供 tools/bulk-export-worker.php 子进程调用。
     */
    public function publish_post_local($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') {
            $this->log("文章 {$post_id} 跳过上传（非已发布文章）", 'DEBUG');
            return false;
        }
        $path = $this->get_post_storage_path($post);
        $local = CFSE_LOCAL_DIR . '/' . ltrim($path, '/');
        if (!is_readable($local)) {
            $this->log("文章 {$post_id} 本地产物不存在，跳过上传（请先「生成到本地」）: {$local}", 'WARNING');
            return false;
        }
        $html = (string) @file_get_contents($local);
        if ($html === '') {
            $this->log("文章 {$post_id} 本地产物为空，跳过上传: {$local}", 'ERROR');
            return false;
        }
        try {
            // 元数据（D1 行）以 WP 当前状态为准，正文用本地已生成的 HTML
            $artifact = $this->build_post_meta($post);
            $artifact['path'] = $path;
            return $this->publish_artifact($artifact, $html);
        } catch (Throwable $e) {
            $this->log("文章 {$post_id} 上传失败: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    // ============================================
    // Markdown 渲染（md 文章；与 Worker 端 marked 对齐）
    // ============================================
    // ============================================
    // 文章编辑页「📝 文档格式」面板（Markdown 原稿标记）
    // ============================================
    public function add_doc_format_metabox($post_type = '', $post = null) {
        add_meta_box('cfse_doc_format', '📝 文档格式', array($this, 'render_doc_format_metabox'), 'post', 'side', 'high');
        // 「Worker 端诞生」的篇（wp_id 为时间戳形态，见 ROADMAP I.1）额外给一个同步面板
        $remote_id = ($post && isset($post->ID)) ? $this->get_remote_wp_id($post->ID) : 0;
        if ($remote_id || ($post && isset($post->ID) && (int) $post->ID >= CFSE_WORKER_ID_MIN)) {
            add_meta_box('cfse_worker_sync', '☁️ Worker 同步', array($this, 'render_worker_sync_metabox'), 'post', 'side', 'default');
        }
    }

    public function render_doc_format_metabox($post) {
        $is_md = ($this->get_content_format($post->ID) === 'md');
        wp_nonce_field('cfse_doc_format_save', 'cfse_doc_format_nonce');
        echo '<label style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" name="cfse_content_format_md" value="1" ' . checked($is_md, true, false) . ' /> <span>存为 MD 格式</span></label>';
        echo '<details style="margin-top:8px;">'
            . '<summary style="cursor:pointer;color:#2271b1;font-size:12px;">'
            . '<span class="dashicons dashicons-editor-help" style="font-size:15px;width:15px;height:15px;vertical-align:text-bottom;"></span> 这是什么？</summary>'
            . '<div class="description" style="margin:6px 0 0;padding-left:8px;border-left:3px solid #c3c4c7;font-size:12px;line-height:1.7;">'
            . '本文正文是 <b>Markdown 原稿</b>时勾选。勾选后，静态站的列表 / 订阅 / 单页都会按 Markdown 排版渲染。<br/>'
            . '<b>建议</b>：md 原稿请用编辑器右上角的「文本」标签页粘贴 —— 「可视」模式保存会重排正文。'
            . '</div></details>';
    }

    /**
     * 「☁️ Worker 同步」面板（只对 Worker 端诞生的篇显示）：
     * 异步查两端版本时间（不拖慢编辑页）+ 一键对齐本篇。
     */
    public function render_worker_sync_metabox($post) {
        $pid = (int) $post->ID;
        $auto = !empty($this->config['worker_auto_check']);
        // 用链接而不是 <form>：WP 编辑页的主表单包裹整个编辑区，内层 <form> 会被浏览器忽略
        $align_url = wp_nonce_url(admin_url('admin-post.php?action=cfse_align_worker&post_id=' . $pid), 'cfse_align_worker_' . $pid);
        echo '<p class="description">本篇由 <b>Worker 端诞生</b>，可把 Worker 的最新版本对齐过来。</p>';
        echo '<p style="margin:8px 0;"><button type="button" class="button" id="cfse-check-btn">🔍 检查 Worker 端版本</button></p>';
        echo '<div id="cfse-worker-status" style="margin:8px 0;padding:8px;background:#f6f7f7;border-radius:3px;font-size:12px;line-height:1.7;color:#646970;">'
            . ($auto ? '正在查询…' : '点上面的按钮查询两端版本时间。') . '</div>';
        echo '<p id="cfse-align-wrap" style="margin:8px 0;display:none;">'
            . '<a href="' . esc_url($align_url) . '" class="button button-primary" id="cfse-align-btn">对齐本篇</a>'
            . ' <span class="dashicons dashicons-editor-help" style="color:#787c82;vertical-align:text-bottom;" title="用 Worker 端的标题 / 正文 / 别名 / 分类标签覆盖本篇（会覆盖 WP 侧对该篇的修改）"></span>'
            . '</p>';
        if (isset($_GET['cfse_aligned'])) {
            echo $_GET['cfse_aligned'] === '1'
                ? '<p style="margin:8px 0 0;color:#2271b1;"><b>✅ 已对齐到 Worker 版本</b></p>'
                : '<p style="margin:8px 0 0;color:#b32d2e;"><b>❌ 对齐失败</b>（未能从 Worker 取到该篇，见日志）</p>';
        }
        $ajax_nonce = wp_create_nonce('cfse_worker_status');
        // 按钮只在「远端更新」时出现；本地较新 / 一致 都不给覆盖入口（避免误覆盖）
        echo '<script>jQuery(function($){'
            . 'var $s=$("#cfse-worker-status"),$w=$("#cfse-align-wrap");'
            . 'function cfseCheck(){'
            . '$w.hide();$s.html("查询中…");'
            . '$.post(ajaxurl,{action:"cfse_worker_status",post_id:' . $pid . ',_ajax_nonce:"' . esc_js($ajax_nonce) . '"},function(r){'
            . 'if(!r||!r.ok){$s.html("（查询不到 Worker 版本：该篇可能尚未同步到 Worker，或接口不可用）");return;}'
            . 'var h="Worker 版本：" + r.worker_modified + "<br/>本篇版本：" + r.local_modified;'
            . 'if(r.need_sync){h += "<br/><b style=\'color:#b32d2e;\'>⚠︎ 远端更新，建议对齐</b>";$w.show();}'
            . 'else if(r.local_newer){h += "<br/><b style=\'color:#996800;\'>本地较新</b> —— 若以 Worker 版本覆盖，会丢失本地修改。";}'
            . 'else{h += "<br/><span style=\'color:#1d6f42;\'>✔ 两端一致</span>";}'
            . '$s.html(h);});}'
            . '$("#cfse-check-btn").on("click",cfseCheck);'
            . 'if(' . ($auto ? 'true' : 'false') . '){cfseCheck();}'
            . '});</script>';
    }

    /**
     * 保存「文档格式」标记。
     * 优先级 5：必须早于导出钩子（save_post 优先级 10），否则本次自动导出读到的还是旧标记。
     * 无本面板 nonce / 无权限时直接返回 —— tool/index.php 发布、快速编辑、REST 保存都不带这个 nonce，
     * 因此不会误删由其它途径写入的 content_format 标记。
     */
    public function save_doc_format_meta($post_id, $post = null, $update = null) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!isset($_POST['cfse_doc_format_nonce']) || !wp_verify_nonce($_POST['cfse_doc_format_nonce'], 'cfse_doc_format_save')) return;
        if (!$post) $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!empty($_POST['cfse_content_format_md'])) {
            update_post_meta($post_id, 'content_format', 'md');
        } else {
            delete_post_meta($post_id, 'content_format');
        }
    }

    /**
     * 读取文章内容格式，返回值只有 'md' / 'html'。
     * 优先可见字段 content_format（与 D1 posts.content_format 同名；WP 后台编辑页的「自定义字段」
     * 面板里可手动添加/修改），兼容批2 初版写过的隐藏字段 _cfse_content_format。
     */
    private function get_content_format($post_id) {
        $value = get_post_meta($post_id, 'content_format', true);
        if ($value === '' || $value === null || $value === false) {
            $value = get_post_meta($post_id, '_cfse_content_format', true); // 兼容旧标记
        }
        return (strtolower(trim((string) $value)) === 'md') ? 'md' : 'html';
    }

    /**
     * 用 vendored Parsedown 渲染 markdown（safe mode 防 XSS）。
     * 类名冲突保护：站点其它插件/主题若已加载 Parsedown，则复用其实现（API 相同）。
     */
    private function render_markdown($md) {
        if (!class_exists('Parsedown')) {
            $file = CFSE_PLUGIN_DIR . 'vendor/parsedown/Parsedown.php';
            if (file_exists($file)) require_once $file;
        }
        if (!class_exists('Parsedown')) {
            $this->log('未找到 Parsedown，md 内容按原文输出', 'WARNING');
            return $md;
        }
        try {
            $parsedown = new Parsedown();
            $parsedown->setSafeMode(true);
            return $parsedown->text($md);
        } catch (Throwable $e) {
            $this->log('Parsedown 渲染失败: ' . $e->getMessage(), 'ERROR');
            return $md;
        }
    }

    /** md → 纯文本（渲染后去标签，供摘要/列表用） */
    private function md_to_plain($md) {
        $text = strip_tags($this->render_markdown($md));
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * the_content 过滤器：meta=_cfse_content_format 为 md 的文章在渲染时转成 HTML。
     * 生效范围 = WP 前台 + 主题模板渲染（导出的一级渲染 render_with_template 也走这条），
     * 于是「生成的单页 HTML」与 Worker 端（marked）渲染结果一致。
     * 注意：不要用 is_admin() 短路 —— 后台「全量导出 / 生成到本地」正是在 admin 上下文里渲染模板。
     */
    public function render_md_content($content) {
        $id = get_the_ID();
        if (!$id || $this->get_content_format($id) !== 'md') return $content;
        return $this->render_markdown($content);
    }

    /**
     * 按模式执行单篇导出动作：auto=生成+上传（默认）、generate=仅生成到本地、publish=上传本地产物。
     */
    private function run_export_mode($post_id, $mode) {
        if ($mode === 'generate') return $this->generate_post_local($post_id);
        if ($mode === 'publish') return $this->publish_post_local($post_id);
        return (bool) $this->export_post($post_id);
    }

    /**
     * 请求 Worker 清除缓存（/purge-cache），keys 按设置页开关组装：
     *   - cf_purge_sidebar：sidebar:data
     *   - cf_purge_lists：首页第 1 页 + 各分类/标签第 1 页 + 归档最近 8 个月及年份第 1 页
     */
    private function purge_page_caches() {
        $site_url = isset($this->config['site_url']) ? rtrim($this->config['site_url'], '/') : '';
        $auth = isset($this->config['auth_key']) ? $this->config['auth_key'] : '';
        if ($site_url === '' || $auth === '') return;
        // 纯 TTL 语义：对应缓存未启用（TTL=0）时不产生清理请求（省 KV 写操作）
        $clear_sidebar = !empty($this->config['purge_sidebar']) && $this->config['cache_ttl_sidebar'] > 0;
        $clear_lists = !empty($this->config['purge_lists']) && $this->config['cache_ttl_list'] > 0;
        if (!$clear_sidebar && !$clear_lists) return;

        $keys = array();
        if ($clear_sidebar) {
            $keys[] = 'sidebar:data';
        }
        if ($clear_lists) {
            $keys[] = 'home:page:1';
            $tax_map = array('category' => 'category', 'post_tag' => 'tag');
            foreach ($tax_map as $tax => $route_prefix) {
                $terms = get_terms(array('taxonomy' => $tax, 'hide_empty' => true, 'fields' => 'slugs'));
                if (is_wp_error($terms)) continue;
                foreach ((array) $terms as $slug) {
                    $keys[] = $route_prefix . ':' . $slug . ':page:1';
                }
            }
            global $wpdb;
            $months = $wpdb->get_col("SELECT DISTINCT DATE_FORMAT(post_date, '%Y-%m') AS ym FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ym DESC LIMIT 8");
            foreach ((array) $months as $ym) {
                if (strlen($ym) !== 7) continue;
                $yy = substr($ym, 0, 4);
                $mm = substr($ym, 5, 2);
                $keys[] = "archive:{$yy}:{$mm}:page:1";
                $keys[] = "archive:{$yy}:all:page:1";
            }
        }
        $keys = array_values(array_unique($keys));
        if (!$keys) return;
        // 多托管：对所有「具备缓存能力」的目标清缓存；纯静态档（local）没有缓存层 ⇒ 跳过
        $res = $this->for_each_target('cache', function ($t) use ($keys) {
            return $this->target_purge($t, $keys);
        });
        if ($res['tried'] === 0) {
            $this->log('没有启用「缓存」能力的托管目标，跳过清缓存（静态档无需清缓存）', 'DEBUG');
            return;
        }
        $this->log('已请求清除页面缓存 ' . count($keys) . ' 个 key → ' . wp_json_encode($res['results']), 'DEBUG');
    }

    // ============================================
    // HTML生成策略
    // ============================================
    private function generate_post_html($post) {
        $mode = $this->config['export_mode'];
        $html = null;
        if ($mode === 'template' || $mode === 'auto') {
            $html = $this->render_with_template($post);
            if ($this->is_valid_html($html) && $this->html_has_title($html, $post)) {
                $this->log('使用内部模板渲染', 'DEBUG');
                return $this->post_process_html($html);
            }
        }
        if ($mode === 'fetch' || $mode === 'auto') {
            $html = $this->fetch_from_frontend($post);
            if ($this->is_valid_html($html)) {
                $this->log('使用前台抓取', 'DEBUG');
                return $this->post_process_html($html);
            }
        }
        $this->log('使用硬编码模板（降级）', 'WARNING');
        return $this->generate_fallback_html($post);
    }

    private function render_with_template($input_post) {
        global $wp_query, $post, $more;
        $original_global_post = $post;
        $original_query = $wp_query;
        $original_more = $more;
        $original_wp_the_query = isset($GLOBALS['wp_the_query']) ? $GLOBALS['wp_the_query'] : null;
        if (!$input_post || !isset($input_post->ID)) {
            $this->log('render_with_template: 传入的$post无效', 'WARNING');
            return null;
        }
        $original_user_id = get_current_user_id();
        wp_set_current_user(0);
        try {
            $post = get_post($input_post->ID);
            if (!$post) return null;
            setup_postdata($post);
            $wp_query = new WP_Query(array('p' => $input_post->ID, 'post_type' => 'post'));
            $wp_query->is_single = true;
            $wp_query->is_page = false;
            $wp_query->posts = array($post);
            $wp_query->post_count = 1;
            $wp_query->current_post = -1;
            $wp_query->in_the_loop = false;
            $GLOBALS['wp_the_query'] = $wp_query;
            $more = 1;
            $template = locate_template(array('single.php', 'singular.php', 'page.php', 'index.php'));
            // WordPress 的 load_template() 参数 $load_once 默认 true，get_header()/get_footer()/
            // get_sidebar() 内部也传 true（只有 get_template_part() 是 false）：同一 PHP 进程内
            // 第二次渲染同一模板不会执行，只会得到 0 字节或缺少 header/footer 的残片。
            // 这里提前识别，省掉一次注定失败的渲染，并让降级原因在日志里可见。
            if ($template && in_array($template, get_included_files(), true)) {
                $this->log('本进程已渲染过 ' . basename($template) . '（WP load-once 限制），跳过内部渲染，改用前台抓取', 'DEBUG');
                return null;
            }
            ob_start();
            if ($template) {
                load_template($template);
            } else {
                get_header();
                ?><main class="container"><article><h1><?php the_title(); ?></h1><div class="content"><?php the_content(); ?></div></article></main><?php get_footer();
            }
            $html = ob_get_clean();
            wp_reset_postdata();
            return $html;
        } catch (Throwable $e) {
            $this->log('模板渲染异常: ' . $e->getMessage(), 'ERROR');
            return null;
        } finally {
            wp_set_current_user($original_user_id);
            $post = $original_global_post;
            $wp_query = $original_query;
            $more = $original_more;
            if ($original_wp_the_query !== null) $GLOBALS['wp_the_query'] = $original_wp_the_query;
            wp_reset_postdata();
        }
    }

    private function fetch_from_frontend($post) {
        // 开关走请求头（见 __construct 的早退判据），URL 不带参数 —— 否则该参数会被
        // WP 用「当前 URL」构造的链接（如评论区的 cancel-comment-reply-link）回显进静态产物。
        $post_url = get_permalink($post);
        if (!$post_url) return null;
        $response = wp_remote_get($post_url, array('timeout' => 30, 'sslverify' => false, 'headers' => array('User-Agent' => 'CF-Static-Export/2.1', 'X-CF-Export-Internal' => '1')));
        if (is_wp_error($response)) {
            $this->log('前台抓取失败：' . $response->get_error_message() . '（' . $post_url . '）', 'WARNING');
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 400) {
            $this->log('前台抓取返回 HTTP ' . $code . '（' . $post_url . '）', 'WARNING');
            return null;
        }
        if (!$this->is_valid_html($body)) {
            $this->log('前台抓取内容无效：' . strlen($body) . ' 字节（' . $post_url . '）', 'WARNING');
            return null;
        }
        return $body;
    }

    /**
     * 把 HTML 里残留的源站地址统一改写成静态站地址（$this->config['site_url']）。
     * str_replace(home_url(), ...) 只能命中字面形态，抓不到 WordPress 输出里真实存在的：
     *   - 协议相对地址    //192.168.2.2                （<link rel="dns-prefetch">）
     *   - percent-encoded http%3A%2F%2F192.168.2.2     （oEmbed 的 url= 参数）
     *   - JSON 内转义     http:\/\/192.168.2.2
     * 只处理源站 host 且要求前面带协议/协议分隔符，正文里裸写的 IP 不会被改动。
     */
    private function normalize_source_hosts($html) {
        if (empty($html)) return $html;
        $target = rtrim($this->config['site_url'], '/');
        $target_host = $target !== '' ? parse_url($target, PHP_URL_HOST) : '';
        if (empty($target_host)) return $html;

        $hosts = array();
        foreach (array(home_url(), site_url()) as $u) {
            $h = parse_url($u, PHP_URL_HOST);
            if (!empty($h) && $h !== $target_host) {
                $hosts[$h] = true;
            }
        }
        if (empty($hosts)) return $html;

        $target_enc = str_replace(array(':', '/'), array('%3A', '%2F'), $target);
        $target_esc = str_replace('/', '\/', $target);
        $rel = '//' . $target_host;
        $rel_enc = '%2F%2F' . $target_host;

        foreach (array_keys($hosts) as $host) {
            $q = preg_quote($host, '~');
            // 1) 绝对地址 http(s)://host
            $html = preg_replace_callback('~https?://' . $q . '(?=[/:"\'?&#\s>])~i', function () use ($target) { return $target; }, $html);
            // 2) JSON 转义 http:\/\/host
            $html = preg_replace_callback('~https?:\\\\/\\\\/' . $q . '~i', function () use ($target_esc) { return $target_esc; }, $html);
            // 3) percent-encoded（带协议编码 / 裸协议分隔符）
            $html = preg_replace_callback('~https?%3A%2F%2F' . $q . '~i', function () use ($target_enc) { return $target_enc; }, $html);
            $html = preg_replace_callback('~(?<!%3A)%2F%2F' . $q . '~i', function () use ($rel_enc) { return $rel_enc; }, $html);
            // 4) 协议相对 //host
            $html = preg_replace_callback('~(?<![:%])//' . $q . '(?=[/:"\'?&#\s>])~i', function () use ($rel) { return $rel; }, $html);
        }
        return $html;
    }

    private function generate_fallback_html($post) {
        $site_url = $this->config['site_url'];
        $content = apply_filters('the_content', $post->post_content);
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . esc_html($post->post_title) . ' - ' . get_bloginfo('name') . '</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;max-width:800px;margin:0 auto;padding:20px;line-height:1.8;}</style></head><body><h1>' . esc_html($post->post_title) . '</h1><div>' . $content . '</div><a href="' . esc_url($site_url) . '/">← 返回首页</a></body></html>';
    }

    private function post_process_html($html) {
        $site_url = $this->config['site_url'];
        if (!empty($html) && class_exists('DOMDocument')) {
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();
            $xpath = new DOMXPath($dom);
            $nodes = $xpath->query('//*[@id="wpadminbar"]');
            foreach ($nodes as $node) {
                if ($node->parentNode) $node->parentNode->removeChild($node);
            }
            $html = $dom->saveHTML();
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $html = preg_replace('/<link[^>]*id="admin-bar-css"[^>]*>/', '', $html);
            $html = preg_replace('/<style[^>]*id="admin-bar-inline-css"[^>]*>.*?<\/style>/s', '', $html);
            $html = preg_replace('/<link[^>]*id="dashicons-css"[^>]*>/', '', $html);
        }
        $html = str_replace(home_url('/'), $site_url . '/', $html);
        $html = str_replace(home_url(), $site_url, $html);
        $html = str_replace(content_url(), $site_url . '/wp-content', $html);
        $html = str_replace(includes_url(), $site_url . '/wp-includes', $html);
        // 上面这些 str_replace 只命中字面形态；源站 host 的其它写法（协议相对 //host、
        // percent-encoded、JSON 转义）会漏网（典型：dns-prefetch、oEmbed 的 url= 参数）。
        $html = $this->normalize_source_hosts($html);
        if ($this->config['clean_scripts']) {
            $html = preg_replace('/<script[^>]*src="[^"]*wp-includes\/js\/[^"]*"[^>]*><\/script>/', '', $html);
            $html = preg_replace('/<script[^>]*src="[^"]*wp-content\/plugins\/[^"]*"[^>]*><\/script>/', '', $html);
            $html = preg_replace('/<div[^>]*id="comments"[^>]*>.*?<\/div>/s', '', $html);
            $html = preg_replace('/<script[^>]*wp-emoji[^>]*><\/script>/', '', $html);
        }
        $cache_ttl = $this->config['cache_ttl'];
        $html = str_replace('</head>', '<meta http-equiv="Cache-Control" content="public, max-age=' . (int) $cache_ttl . '"></head>', $html);
        $html = str_replace('</head>', '<meta name="x-static-export" content="true"></head>', $html);
        // 清理内部抓取标记（与 views/template-editor.php 既有做法一致）：WP 会用「当前 URL」
        // 构造部分链接，把内部参数回显进产物（多见于隐藏的评论表单）。
        $html = str_replace(array('?_cfse_internal=1', '&_cfse_internal=1', '_cfse_internal=1'), '', $html);
        return $html;
    }

    private function is_valid_html($html) {
        return !empty($html) && strlen($html) > 100 && (strpos($html, '<html') !== false || strpos($html, '<body') !== false);
    }

    private function html_has_title($html, $post) {
        $title = isset($post->post_title) ? trim($post->post_title) : '';
        if ($title === '') return true;
        return strpos($html, esc_html($title)) !== false;
    }

    private function extract_excerpt($content, $length = 200) {
        $content = strip_tags($content);
        $content = str_replace(array('[...]', '&nbsp;', "\r", "\n"), '', $content);
        if (function_exists('mb_strlen')) {
            if (mb_strlen($content) > $length) $content = mb_substr($content, 0, $length) . '...';
        } elseif (strlen($content) > $length) {
            $content = substr($content, 0, $length) . '...';
        }
        return trim($content);
    }

    // ============================================
    // R2上传和媒体同步
    // ============================================
    private function sync_media_files($content) {
        $urls = array();
        preg_match_all('/<img[^>]+(?:src|data-src|data-lazy-src|data-original)=[\'"]?([^\'"\s>]+)/i', $content, $matches);
        if (!empty($matches[1])) $urls = array_merge($urls, $matches[1]);
        if (preg_match_all('/srcset=[\'"]([^\'"]+)[\'"]/i', $content, $sets)) {
            foreach ($sets[1] as $set) {
                foreach (explode(',', $set) as $candidate) {
                    $parts = preg_split('/\s+/', trim($candidate));
                    if (!empty($parts[0])) $urls[] = $parts[0];
                }
            }
        }
        $urls = array_unique($urls);
        foreach ($urls as $image_url) {
            $image_url = trim(str_replace(array('"', "'"), '', $image_url));
            if (strpos($image_url, home_url()) === false) continue;
            $path = ltrim(parse_url($image_url, PHP_URL_PATH), '/');
            if (empty($path)) continue;
            $this->upload_media_to_r2($image_url, $path);
        }
    }

    private function upload_media_to_r2($url, $path) {
        $response = wp_remote_get($url, array('timeout' => 60));
        if (is_wp_error($response)) {
            $this->log("媒体下载失败: {$url} - " . $response->get_error_message(), 'WARNING');
            return false;
        }
        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code >= 400) {
            $this->log("媒体下载失败 HTTP {$http_code}: {$url}", 'WARNING');
            return false;
        }
        $content = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (is_array($content_type)) $content_type = $content_type[0];
        if (empty($content_type)) $content_type = 'application/octet-stream';
        return $this->upload_to_r2_via_worker($path, $content, $content_type, null);
    }

    /**
     * 托管目标列表（多托管配置）。
     *   type：'cfse-worker'（当前唯一实现，走 Worker 的 X-Auth-Key 接口）
     *         —— 规划中：'local'（只写本地目录，纯静态档）、's3'（S3 兼容对象存储直传）
     *   capabilities：{ r2: 能存静态文件, d1: 能存文章数据, cache: 支持清缓存 }
     *   enabled=false 忽略。
     * 未配置时用旧设置（cf_site_url + cf_auth_key）自动迁移成一条默认 target ⇒ 行为不变。
     */
    /**
     * 内置托管模板（provider 级）—— 只读，不接受用户写入。
     * 定义 provider 的「能力上限 / 写入机制 / 回滚兼容 type / 需要填的站点字段」。
     * 详见 docs/hosting-templates.md（决策与验收标准）。
     */
    private function get_hosting_templates() {
        return array(
            'cloudflare' => array(
                'id' => 'cloudflare',
                'name' => 'Cloudflare（Worker + R2 + D1）',
                'transport' => 'worker-api',
                'legacy_type' => 'cfse-worker',
                'capabilities' => array('r2' => true, 'd1' => true, 'render' => true, 'cache' => true),
                'intents' => array('md', 'static-list', 'static-only', 'backup'),
                'defaults' => array(
                    'capabilities' => array('r2' => true, 'd1' => true, 'render' => true, 'cache' => true),
                    'intent' => array('md', 'static-list'),
                ),
                'fields' => array('endpoint', 'auth_key', 'path_prefix', 'public_url'),
            ),
            's3' => array(
                'id' => 's3',
                'name' => 'S3 兼容对象存储',
                'transport' => 's3',
                'legacy_type' => 's3',
                'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                'intents' => array('static-only', 'backup'),
                'defaults' => array(
                    'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                    'intent' => array('static-only'),
                ),
                'fields' => array('endpoint', 'bucket', 'region', 'access_key', 'secret_key', 'path_style', 'path_prefix', 'public_url'),
            ),
            'local' => array(
                'id' => 'local',
                'name' => '本地目录',
                'transport' => 'fs',
                'legacy_type' => 'local',
                'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                'intents' => array('static-only', 'backup'),
                'defaults' => array(
                    'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                    'intent' => array('static-only'),
                ),
                'fields' => array('dir', 'path_prefix', 'public_url'),
            ),
            'github' => array(
                'id' => 'github',
                'name' => 'GitHub Pages（git 推送）',
                'transport' => 'git',
                'legacy_type' => 'github',
                'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                'intents' => array('static-only', 'backup'),
                'defaults' => array(
                    'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                    'intent' => array('static-only'),
                ),
                'fields' => array('repo', 'token', 'branch', 'path_prefix', 'public_url'),
            ),
            'netlify' => array(
                'id' => 'netlify',
                'name' => 'Netlify（deploy API）',
                'transport' => 'netlify',
                'legacy_type' => 'netlify',
                'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                'intents' => array('static-only', 'backup'),
                'defaults' => array(
                    'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                    'intent' => array('static-only'),
                ),
                'fields' => array('site', 'token', 'public_url'),
            ),
            'vercel' => array(
                'id' => 'vercel',
                'name' => 'Vercel（deploy API）',
                'transport' => 'vercel',
                'legacy_type' => 'vercel',
                'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                'intents' => array('static-only', 'backup'),
                'defaults' => array(
                    'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                    'intent' => array('static-only'),
                ),
                'fields' => array('project', 'token', 'public_url'),
            ),
            'git' => array(
                'id' => 'git',
                'name' => '通用 git（GitHub API 兼容：Gitea / 自建）',
                'transport' => 'git',
                'legacy_type' => 'git',
                'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                'intents' => array('static-only', 'backup'),
                'defaults' => array(
                    'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                    'intent' => array('static-only'),
                ),
                'fields' => array('api_base', 'repo', 'token', 'branch', 'path_prefix', 'public_url'),
            ),
            'gitlab' => array(
                'id' => 'gitlab',
                'name' => 'GitLab Pages（commits API）',
                'transport' => 'gitlab',
                'legacy_type' => 'gitlab',
                'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                'intents' => array('static-only', 'backup'),
                'defaults' => array(
                    'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                    'intent' => array('static-only'),
                ),
                'fields' => array('project', 'token', 'branch', 'path_prefix', 'public_url'),
            ),
            'ftp' => array(
                'id' => 'ftp',
                'name' => 'FTP / FTPS（虚拟主机）',
                'transport' => 'ftp',
                'legacy_type' => 'ftp',
                'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                'intents' => array('static-only', 'backup'),
                'defaults' => array(
                    'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                    'intent' => array('static-only'),
                ),
                'fields' => array('host', 'port', 'user', 'pass', 'dir', 'ftps', 'path_prefix', 'public_url'),
            ),
            'webdav' => array(
                'id' => 'webdav',
                'name' => 'WebDAV（坚果云 / Nextcloud 等）',
                'transport' => 'webdav',
                'legacy_type' => 'webdav',
                'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                'intents' => array('static-only', 'backup'),
                'defaults' => array(
                    'capabilities' => array('r2' => true, 'd1' => false, 'render' => false, 'cache' => false),
                    'intent' => array('static-only'),
                ),
                'fields' => array('endpoint', 'user', 'pass', 'path_prefix', 'public_url'),
            ),
        );
    }

    /**
     * 意图归一：兼容两种输入格式 ——
     *   ① 表单键映射：["md" => "1", "static-only" => "1"]
     *   ② 列表（模板 defaults / 已存储）：["md", "static-list"]
     * 返回规范化后的意图名列表。两处（sanitize / validate）共用，避免格式混用漏检。
     */
    private function normalize_intent($raw) {
        $out = array();
        if (!is_array($raw)) return $out;
        foreach ($raw as $k => $v) {
            if (is_int($k)) {
                if (is_string($v) && $v !== '') $out[] = $v;   // 列表格式：值是意图名
            } else {
                if (!empty($v)) $out[] = (string) $k;          // 键映射格式：键是意图名
            }
        }
        return array_values(array_unique($out));
    }

    /** 意图 → 必需能力（docs/hosting-templates.md §5） */
    private function intent_requires($intent) {
        switch ($intent) {
            case 'md':          return array('d1', 'render');
            case 'static-list': return array('d1');
            case 'static-only': return array('r2');
            case 'backup':      return array('r2');
            default:            return array();
        }
    }

    /**
     * 【冷路径】托管目标校验（§6 三层 / §12）—— 只在设置页渲染与保存时调用，热路径不调用。
     * 返回 array(
     *   'warnings'    => array(array('id','key','message','fix')),   // fix: null | array('template','type')
     *   'adjustments' => array(array('id','field','from','to')),     // 仅 $is_save=true 时收集（§12.4 回显）
     * )
     * 日志按 (key, 目标 id) 限流：同一键一次调用只记一条（§12.2）。
     */
    private function validate_hosting_targets($is_save = false, $list_override = null) {
        $templates = $this->get_hosting_templates();
        if (is_array($list_override)) {
            $list = $list_override;
        } else {
            $saved = get_option('cf_hosting_targets', '');
            if (is_array($saved)) {
                $list = $saved;   // 兼容旧数据（见 get_hosting_targets 的同名处理）
            } else {
                $list = is_string($saved) ? json_decode($saved, true) : array();
            }
        }
        if (!is_array($list) || empty($list)) {
            return array('warnings' => array(), 'adjustments' => array());
        }
        $warnings = array(); $adjustments = array(); $logged = array();
        $logOnce = function ($key, $msg) use (&$logged) {
            if (isset($logged[$key])) return;
            $logged[$key] = true;
            $this->log($msg, 'WARNING');
        };
        $union = array('r2' => false, 'd1' => false, 'render' => false, 'cache' => false);
        $wantD1 = false; // 是否有目标「声明了需要 d1 的意图」（§6：纯静态不声明 ⇒ 不警告）
        $wantR2 = false; // 是否有目标「声明了需要 r2 的意图」

        foreach ($list as $t) {
            if (!is_array($t)) continue;
            if (isset($t['enabled']) && !$t['enabled']) continue;
            $tid = isset($t['id']) ? $t['id'] : '?';

            // 1) 模板解析（与热路径同规则）：template 优先；仅旧 type 时按 legacy_type 反查
            $tplId = isset($t['template']) ? (string) $t['template'] : '';
            $legacy = isset($t['type']) ? (string) $t['type'] : '';
            if ($tplId === '' && $legacy !== '') {
                foreach ($templates as $cand => $tp) {
                    if (isset($tp['legacy_type']) && $tp['legacy_type'] === $legacy) { $tplId = $cand; break; }
                }
            }
            if ($tplId === '' || !isset($templates[$tplId])) {
                $warnings[] = array('id' => $tid, 'key' => 'unknown_template',
                    'message' => '模板「' . ($tplId !== '' ? $tplId : '未指定') . '」不存在，该目标会被跳过。', 'fix' => null);
                $logOnce('unknown_template:' . $tid, '托管目标 ' . $tid . ' 的模板 ' . ($tplId !== '' ? $tplId : '(未指定)') . ' 不存在');
                continue;
            }
            $tpl = $templates[$tplId];

            // 2) 一致性（C3/C6）：type 与 template 必须匹配
            if ($legacy !== '' && $legacy !== $tpl['legacy_type']) {
                $warnings[] = array('id' => $tid, 'key' => 'mismatch',
                    'message' => 'type=' . $legacy . ' 与 template=' . $tplId . ' 不匹配（预期 type=' . $tpl['legacy_type'] . '）；若刚从旧版本回滚，请确认哪个才是当前意图。',
                    'fix' => array('template', 'type'));
                $logOnce('mismatch:' . $tid, '托管目标 ' . $tid . ' 的 type(' . $legacy . ') 与 template(' . $tplId . ') 不匹配');
            }
            // 3) 保存时重算 type（§12.4）：仅当用户「显式填了不同值」才计入 adjustments
            //    （新结构只写 template、不写 type 属正常 ⇒ 空值静默补全，不提示"已修正"）
            if ($is_save && $legacy !== '' && $legacy !== $tpl['legacy_type']) {
                $adjustments[] = array('id' => $tid, 'field' => 'type', 'from' => $legacy, 'to' => $tpl['legacy_type']);
            }

            // 4) 能力归一（§12.7）+ 超限可见（B6 / B7④）
            $caps = (isset($t['capabilities']) && is_array($t['capabilities'])) ? $t['capabilities'] : array();
            $defCaps = isset($tpl['defaults']['capabilities']) ? $tpl['defaults']['capabilities'] : array();
            $effCaps = array();
            foreach ($tpl['capabilities'] as $k => $limit) {
                if (!$limit) {
                    $effCaps[$k] = false;
                    if (!empty($caps[$k])) {
                        $warnings[] = array('id' => $tid, 'key' => 'over_limit',
                            'message' => '模板「' . $tplId . '」不支持能力「' . $k . '」，保存后将被关闭。', 'fix' => null);
                        $logOnce('over_limit:' . $tid . ':' . $k, '托管目标 ' . $tid . ' 声明了模板不支持的 ' . $k . '，已裁剪');
                        if ($is_save) $adjustments[] = array('id' => $tid, 'field' => 'capabilities.' . $k, 'from' => true, 'to' => false);
                    }
                    continue;
                }
                $effCaps[$k] = array_key_exists($k, $caps) ? !empty($caps[$k]) : !empty($defCaps[$k]);
            }

            // 5) 意图自洽（§6 第二层）
            $intent = $this->normalize_intent((isset($t['intent']) && is_array($t['intent'])) ? $t['intent'] : (isset($tpl['defaults']['intent']) ? $tpl['defaults']['intent'] : array()));
            foreach ($intent as $it) {
                // 集合级检查的"需求"由意图决定（纯静态不需要 d1 ⇒ 不该警告 —— §6）
                if ($it === 'md' || $it === 'static-list') $wantD1 = true;
                if ($it === 'static-only' || $it === 'backup') $wantR2 = true;
                foreach ($this->intent_requires($it) as $need) {
                    if (empty($effCaps[$need])) {
                        $msg = '已选意图「' . $it . '」但未启用能力「' . $need . '」' . ($need === 'render' ? '：md 文章不会有静态产出' : '') . '。';
                        $warnings[] = array('id' => $tid, 'key' => 'intent_' . $it . '_' . $need, 'message' => $msg, 'fix' => null);
                        $logOnce('intent:' . $tid . ':' . $it . ':' . $need, '托管目标 ' . $tid . '：' . $msg);
                    }
                }
            }
            foreach ($effCaps as $k => $v) if ($v) $union[$k] = true;
        }

        // 6) 集合级：只能按「意图声明的需求」检查 —— 不能一刀切
        //    （§6：纯静态用户只勾 r2、不勾 d1 是正常状态，不警告）
        if ($wantD1 && !$union['d1']) {
            $warnings[] = array('id' => '(全部目标)', 'key' => 'union_d1',
                'message' => '有目标声明了需要 D1 的意图（服务 md / 动态列表），但没有任何目标具备「文章数据(d1)」能力：'
                           . '文章不会进 D1，Worker 后台与列表页看不到。若本站就是纯静态，请把意图改为「服务静态 HTML」。', 'fix' => null);
            $logOnce('union:d1', '有 d1 意图但无目标具备 d1 能力：文章不会进 D1');
        }
        if ($wantR2 && !$union['r2']) {
            $warnings[] = array('id' => '(全部目标)', 'key' => 'union_r2',
                'message' => '有目标声明了需要文件落点的意图（服务静态 HTML / 备份），但没有任何目标具备「静态文件(r2)」能力：'
                           . '什么都写不出去。', 'fix' => null);
            $logOnce('union:r2', '有 r2 意图但无目标具备 r2 能力：写不出去');
        }

        return array('warnings' => $warnings, 'adjustments' => $adjustments);
    }

    /** 【冷路径】把自动修正回显给用户（§12.4：自动修正必须可见）。无变化返回空串。 */
    private function render_adjustments_notice($adjustments) {
        if (empty($adjustments)) return '';
        $parts = array();
        foreach ($adjustments as $a) {
            $from = is_bool($a['from']) ? ($a['from'] ? '开' : '关') : $a['from'];
            $to = is_bool($a['to']) ? ($a['to'] ? '开' : '关') : $a['to'];
            $parts[] = $a['id'] . ' 的 ' . $a['field'] . '：' . $from . ' → ' . $to;
        }
        return '已自动修正 ' . count($adjustments) . ' 处 —— ' . implode('；', $parts) . '。';
    }

    /**
     * 内置兜底目标：目标列表为空时使用（A3：显式默认 > 列表 > 兜底）。
     * 能力取 cloudflare 模板的默认值（全开，含 render），保持与改造前一致。
     */
    private function default_worker_target() {
        $tpl = $this->get_hosting_templates();
        $cf = isset($tpl['cloudflare']) ? $tpl['cloudflare'] : null;
        $caps = $cf ? $cf['defaults']['capabilities'] : array('r2' => true, 'd1' => true, 'render' => true, 'cache' => true);
        return array(
            'id' => 'default',
            'name' => '默认（Cloudflare Worker）',
            'template' => 'cloudflare',
            'type' => 'cfse-worker',
            'enabled' => true,
            'endpoint' => rtrim(get_option('cf_site_url', get_site_url()), '/'),
            'auth_key' => get_option('cf_auth_key', ''),
            'path_prefix' => '',
            'capabilities' => $caps,
        );
    }

    /**
     * 解析托管目标 —— 【热路径】，见 docs/hosting-templates.md §12.1。
     * 热路径只做静默归一化（模板解析 / type 归一 / 能力裁剪），不记"每请求都会重复"的诊断；
     * 但"配置严重错误导致目标被整条跳过"仍记日志（静默跳过比日志噪声更危险）。
     * 返回结构保持与改造前一致（type / capabilities / endpoint / auth_key / path_prefix），下游零改动。
     */
    private function get_hosting_targets($include_disabled = false) {
        $list = array();
        $saved = get_option('cf_hosting_targets', '');
        // 兼容：旧版本因 sanitize 未注册，保存会把表单数组直接序列化进 option（PHP array）。
        // 读到数组时直接采用，避免目标列表被误判为空而退回兜底默认目标。
        if (is_array($saved)) {
            $list = $saved;
        } elseif (is_string($saved) && $saved !== '') {
            $decoded = json_decode($saved, true);
            if (is_array($decoded)) $list = $decoded;
        }
        // A3 兜底优先级：显式默认目标 > 非空列表 > 内置兜底
        if (empty($list)) {
            return array($this->default_worker_target());
        }
        $templates = $this->get_hosting_templates();
        $out = array();
        foreach ($list as $t) {
            if (!is_array($t)) continue;
            // 运行期只取「已启用」的目标；设置页渲染传 $include_disabled = true ——
            // 否则取消勾选的目标会从界面上消失，用户再保存一次就被真正删除（数据丢失）。
            if (!$include_disabled && isset($t['enabled']) && !$t['enabled']) continue;

            $tid = isset($t['id']) ? $t['id'] : '?';
            // ① 模板解析：新结构 template 优先；仅有旧 type 时按 legacy_type 反查
            $tplId = isset($t['template']) ? (string) $t['template'] : '';
            if ($tplId === '') {
                $legacy = isset($t['type']) ? (string) $t['type'] : '';
                foreach ($templates as $cand => $tp) {
                    if (isset($tp['legacy_type']) && $tp['legacy_type'] === $legacy) { $tplId = $cand; break; }
                }
            }
            if ($tplId === '' || !isset($templates[$tplId])) {
                $this->log('托管目标 ' . $tid . ' 的模板 ' . ($tplId !== '' ? $tplId : '(未指定)') . ' 不存在，已跳过', 'WARNING');
                continue;
            }
            $tpl = $templates[$tplId];
            // ② 归一化：以模板为准写回 template / type（保存路径同样重算，并回显给用户 —— §12.4）
            $t['template'] = $tplId;
            $t['type'] = $tpl['legacy_type'];
            // ③ 能力归一化（§12.7）：模板不支持 ⇒ false；支持但未声明 ⇒ 跟随模板默认；已声明 ⇒ 取值
            $caps = (isset($t['capabilities']) && is_array($t['capabilities'])) ? $t['capabilities'] : array();
            $defCaps = isset($tpl['defaults']['capabilities']) ? $tpl['defaults']['capabilities'] : array();
            $norm = array();
            foreach ($tpl['capabilities'] as $k => $limit) {
                if (!$limit) { $norm[$k] = false; continue; }
                $norm[$k] = array_key_exists($k, $caps) ? !empty($caps[$k]) : !empty($defCaps[$k]);
            }
            $t['capabilities'] = $norm;
            // ④ 意图：阶段一只解析（供冷路径校验），热路径不消费
            if (!isset($t['intent']) || !is_array($t['intent'])) {
                $t['intent'] = isset($tpl['defaults']['intent']) ? $tpl['defaults']['intent'] : array();
            }
            if (!isset($t['path_prefix'])) $t['path_prefix'] = '';
            if (isset($t['template']) && $t['template'] === 'github' && empty($t['branch'])) $t['branch'] = 'gh-pages';
            $out[] = $t;
        }
        return $out;
    }

    /**
     * 对「支持 $capability 能力」的目标逐个执行 $send($target)。
     * @return array{tried:int, results:array, ok:bool}
     *   tried=0 ⇒ 没有目标具备该能力（例如纯静态档没有 d1）：ok 视为 true（无需执行，不算失败）
     */
    private function for_each_target($capability, $send) {
        $targets = isset($this->config['hosting_targets']) ? $this->config['hosting_targets'] : array();
        $results = array();
        foreach ($targets as $t) {
            if (empty($t['capabilities'][$capability])) continue;
            $id = isset($t['id']) ? $t['id'] : ('t' . count($results));
            try {
                $results[$id] = (bool) call_user_func($send, $t);
            } catch (Throwable $e) {
                $this->log('托管目标 ' . $id . ' 调用异常：' . $e->getMessage(), 'ERROR');
                $results[$id] = false;
            }
        }
        return array(
            'tried' => count($results),
            'results' => $results,
            'ok' => empty($results) ? true : in_array(true, $results, true),
        );
    }

    /** 对某个 cfse-worker 目标发一次带 X-Auth-Key 的 JSON 请求（失败返回 null） */
    private function worker_request($target, $route, $method, $body = null, $timeout = 30) {
        $base = rtrim(isset($target['endpoint']) ? (string) $target['endpoint'] : '', '/');
        $key = isset($target['auth_key']) ? (string) $target['auth_key'] : '';
        $id = isset($target['id']) ? $target['id'] : '?';
        if ($base === '') {
            $this->log('托管目标 ' . $id . ' 未配置 endpoint', 'ERROR');
            return null;
        }
        $args = array(
            'timeout' => $timeout,
            'sslverify' => false,
            'method' => $method,
            'headers' => array('Content-Type' => 'application/json'),
        );
        if ($key !== '') $args['headers']['X-Auth-Key'] = $key;
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $resp = wp_remote_request($base . $route, $args);
        if (is_wp_error($resp)) {
            $this->log('托管目标 ' . $id . ' 请求失败(' . $route . ')：' . $resp->get_error_message(), 'ERROR');
            return null;
        }
        return $resp;
    }

    /** 按类型分派「上传静态文件」；返回 bool */
    private function target_upload($target, $path, $content, $content_type, $content_encoding) {
        if ($target['type'] === 'cfse-worker') {
            $body = array('path' => $path, 'content' => base64_encode($content), 'contentType' => $content_type, 'md5' => md5($content));
            if (!empty($content_encoding)) $body['contentEncoding'] = $content_encoding;
            $resp = $this->worker_request($target, '/upload', 'POST', $body, 30);
            if ($resp === null) return false;
            $code = wp_remote_retrieve_response_code($resp);
            if ($code !== 200) {
                $this->log('托管目标 ' . $target['id'] . ' 上传失败，HTTP ' . $code . '：' . wp_remote_retrieve_body($resp), 'ERROR');
                return false;
            }
            return true;
        }
        if ($this->target_type($target) === 'local') {
            // 纯静态档：只写本地目录，不联网（path_prefix 与 s3 一致地生效）
            $prefix = isset($target['path_prefix']) ? trim((string) $target['path_prefix'], '/') : '';
            $rel = $prefix !== '' ? ($prefix . '/' . ltrim($path, '/')) : ltrim($path, '/');
            $file = $this->target_dir($target) . '/' . $rel;
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->log('local 目标创建目录失败：' . $dir, 'ERROR');
                return false;
            }
            if (@file_put_contents($file, $content) === false) {
                $this->log('local 目标写入失败：' . $file, 'ERROR');
                return false;
            }
            $pub = $this->target_public_url($target, $path);
            $this->log('local 目标已写入：' . $file . ' (' . strlen($content) . ' 字节)' . ($pub !== '' ? (' → ' . $pub) : ''), 'DEBUG');
            return true;
        }
        if ($this->target_type($target) === 's3') {
            $ct = $content_type !== '' ? $content_type : 'application/octet-stream';
            $r = $this->s3_request($target, 'PUT', $path, array(), $content, $ct);
            if (!$r['ok']) {
                $this->log('s3 目标 ' . $target['id'] . ' 上传失败：' . $r['hint'] . ($r['detail'] !== '' ? (' — ' . $r['detail']) : ''), 'ERROR');
                return false;
            }
            $pub = $this->target_public_url($target, $path);
            $this->log('s3 目标 ' . $target['id'] . ' 上传成功' . ($pub !== '' ? ('：' . $pub) : ''), 'DEBUG');
            return true;
        }
        $this->log('托管目标 ' . (isset($target['id']) ? $target['id'] : '?') . ' 的类型 ' . $this->target_type($target) . ' 尚未实现上传', 'WARNING');
        return false;
    }

    /** 按类型分派「删除静态文件」；返回 bool */
    private function target_delete($target, $paths) {
        if ($target['type'] === 'cfse-worker') {
            $resp = $this->worker_request($target, '/delete', 'DELETE', array('paths' => array_values($paths)), 30);
            if ($resp === null) return false;
            $code = wp_remote_retrieve_response_code($resp);
            if ($code >= 400) {
                $this->log('托管目标 ' . $target['id'] . ' 删除失败，HTTP ' . $code . '：' . wp_remote_retrieve_body($resp), 'ERROR');
                return false;
            }
            return true;
        }
        if ($this->target_type($target) === 'local') {
            $ok = true;
            $prefix = isset($target['path_prefix']) ? trim((string) $target['path_prefix'], '/') : '';
            foreach ((array) $paths as $p) {
                $rel = $prefix !== '' ? ($prefix . '/' . ltrim($p, '/')) : ltrim($p, '/');
                $file = $this->target_dir($target) . '/' . $rel;
                if (file_exists($file) && !@unlink($file)) {
                    $this->log('local 目标删除失败：' . $file, 'WARNING');
                    $ok = false;
                }
                // 顺带清掉产生的空目录（最多 4 层）
                $dir = dirname($file);
                for ($i = 0; $i < 4; $i++) {
                    $base = $this->target_dir($target);
                    if (rtrim($dir, '/\\') === $base || !is_dir($dir)) break;
                    $items = @scandir($dir);
                    if (is_array($items) ? count(array_diff($items, array('.', '..'))) > 0 : true) break;
                    if (!@rmdir($dir)) break;
                    $dir = dirname($dir);
                }
            }
            return $ok;
        }
        if ($this->target_type($target) === 's3') {
            // S3 没有简单批量删除（多对象删除要 POST ?delete + XML），逐个删更直观
            $ok = true;
            foreach ((array) $paths as $p) {
                $r = $this->s3_request($target, 'DELETE', $p);
                if (!$r['ok'] && $r['code'] !== 'not_found') {
                    $this->log('s3 目标 ' . $target['id'] . ' 删除失败（' . $p . '）：' . $r['hint'], 'WARNING');
                    $ok = false;
                }
            }
            return $ok;
        }
        $this->log('托管目标 ' . (isset($target['id']) ? $target['id'] : '?') . ' 的类型 ' . $this->target_type($target) . ' 尚未实现删除', 'WARNING');
        return false;
    }

    /**
     * 目标上某个 key 的**公开访问地址**（public_url 的正确用法）：
     *   s3      ⇒ 优先 public_url；未配置则回退 endpoint/bucket（一般不可直接访问，仅作参考）
     *   local   ⇒ 优先 public_url（若本地目录被 web 服务托管）；否则给 file:// 路径
     *   worker  ⇒ 返回空串（公开地址由 Worker 侧决定，不在这里猜）
     * 会带上该目标的 path_prefix。
     */
    private function target_public_url($target, $key) {
        $type = $this->target_type($target);
        if ($type === 'cfse-worker') return '';
        $prefix = isset($target['path_prefix']) ? trim((string) $target['path_prefix'], '/') : '';
        $p = ($prefix !== '' ? $prefix . '/' : '') . ltrim((string) $key, '/');
        $pub = rtrim(isset($target['public_url']) ? (string) $target['public_url'] : '', '/');
        if ($pub !== '') return $pub . '/' . $p;
        if ($type === 's3') {
            $c = $this->s3_creds($target);
            if ($c['endpoint'] !== '' && $c['bucket'] !== '') return $c['endpoint'] . '/' . $c['bucket'] . '/' . $p;
            return '';
        }
        if ($type === 'local') return 'file://' . $this->target_dir($target) . '/' . $p;
        return '';
    }

    /** 按类型分派「清缓存」；local/s3 等无缓存层 ⇒ 视为无需清理（返回 true） */
    private function target_purge($target, $keys) {
        if ($this->target_type($target) === 'cfse-worker') {
            $resp = $this->worker_request($target, '/purge-cache', 'POST', array('keys' => array_values($keys)), 5);
            if ($resp === null) return false;
            return wp_remote_retrieve_response_code($resp) < 400;
        }
        return true;
    }

    private function upload_to_r2_via_worker($path, $content, $content_type = 'text/html', $content_encoding = null) {
        try {
            if ($this->config['debug_mode']) {
                $debug_path = CFSE_DEBUG_DIR . '/' . $path;
                $debug_dir = dirname($debug_path);
                if (!is_dir($debug_dir)) @mkdir($debug_dir, 0755, true);
                @file_put_contents($debug_path, $content);
                $this->log("调试文件已保存: {$debug_path} (" . strlen($content) . " 字节)", 'DEBUG');
            }
            // 多托管：对所有「具备静态文件能力」的目标上传
            $res = $this->for_each_target('r2', function ($t) use ($path, $content, $content_type, $content_encoding) {
                return $this->target_upload($t, $path, $content, $content_type, $content_encoding);
            });
            if ($res['tried'] === 0) {
                $this->log('没有启用「静态文件」能力的托管目标，跳过上传：' . $path, 'DEBUG');
            } elseif (!$res['ok']) {
                return false;
            } else {
                $this->log('文件已上传（' . $res['tried'] . ' 个目标）：' . $path . ' → ' . wp_json_encode($res['results']), 'INFO');
            }
            return true;
        } catch (Throwable $e) {
            $this->log("upload_to_r2_via_worker 内部异常：" . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine(), "ERROR");
            return false;
        }
    }

    // ============================================
    // D1数据库操作（关联表版本）
    // ============================================
    private static $d1_schema_checked = false;

    /**
     * 确保 D1 表结构为 v2（幂等；每进程只执行一次）。
     * v2：taxonomies 复合主键 (type, slug)；post_taxonomy 增加 taxonomy_type 列 ——
     *     修复同名 category/tag slug（如 'hosting'）互相覆盖、关联数据串扰。
     * 检测到旧结构时自动重建这两张表（posts 数据不动），并在日志提示做一次全量导出重建分类/标签数据。
     */
    /** 当前 D1 schema 版本（迁移链见 ensure_d1_schema / migrate_d1_schema） */
    const D1_SCHEMA_VERSION = 6;

    private function ensure_d1_schema() {
        if (self::$d1_schema_checked) return true;
        self::$d1_schema_checked = true;

        // 1) 确保 meta 表存在并读取 schema 版本（一条批量请求）
        $boot = $this->d1_batch(array(
            array('sql' => 'CREATE TABLE IF NOT EXISTS _cfse_meta (k TEXT PRIMARY KEY, v TEXT)', 'params' => array()),
            array('sql' => "SELECT v FROM _cfse_meta WHERE k = 'schema_version'", 'params' => array()),
        ));
        $version = null;
        if (is_array($boot) && isset($boot[1]) && is_array($boot[1]) && isset($boot[1][0]['v'])) {
            $version = (int) $boot[1][0]['v'];
        }

        // 2) 无版本记录：区分「全新库 / 已是最新结构 / 旧 v1（需重建关联表）」
        $rebuild_links = false;
        if ($version === null) {
            $probe = $this->d1_batch(array(array(
                'sql' => "SELECT name, sql FROM sqlite_master WHERE type='table' AND name IN ('posts','taxonomies','post_taxonomy')",
                'params' => array(),
            )));
            $schema_map = array();
            if (is_array($probe) && isset($probe[0]) && is_array($probe[0])) {
                foreach ($probe[0] as $row) {
                    if (isset($row['name'])) $schema_map[$row['name']] = (string) ($row['sql'] ?? '');
                }
            }
            $has_any = isset($schema_map['posts']) || isset($schema_map['taxonomies']) || isset($schema_map['post_taxonomy']);
            $tax_v2 = isset($schema_map['taxonomies']) && strpos($schema_map['taxonomies'], 'PRIMARY KEY (type, slug)') !== false;
            $pt_v2 = isset($schema_map['post_taxonomy']) && strpos($schema_map['post_taxonomy'], 'taxonomy_type') !== false;

            if ($has_any && $tax_v2 && $pt_v2) {
                // 旧库已具备 v2 基础表（无 meta 记录）：视为 v2，走一次迁移补齐 settings 等新表并记版本
                $version = 2;
            } else {
                $version = 0;
                // 已有表但非 v2 结构（旧 v1）：迁移需重建关联表（posts 数据保留）
                $rebuild_links = $has_any;
            }
        }

        // 3) 版本落后 → 迁移到最新
        if ($version < self::D1_SCHEMA_VERSION) {
            $this->migrate_d1_schema($rebuild_links);
        }
        return true;
    }

    /**
     * 迁移 D1 schema 到最新版本（当前 2）。
     * 建表均 IF NOT EXISTS 幂等；$rebuild_links 为 true 时先重建关联表
     * （旧 v1 → v2：分类/标签关联数据随之清空，提示全量导出）。
     * 迁移完成在 _cfse_meta 记录 schema_version，之后每进程仅一次 meta 读。
     */
    private function migrate_d1_schema($rebuild_links = false) {
        // v5：探测 posts 是否已有 content_format 列（缺则幂等 ALTER 补齐）
        $probe = $this->d1_batch(array(array(
            'sql' => "SELECT sql FROM sqlite_master WHERE type='table' AND name='posts'",
            'params' => array(),
        )));
        $has_format = false;
        if (is_array($probe) && isset($probe[0]) && is_array($probe[0]) && isset($probe[0][0]['sql'])) {
            if (strpos((string) $probe[0][0]['sql'], 'content_format') !== false) $has_format = true;
        }
        $statements = array();
        if (!$has_format) {
            // 老表（v4 及更早）补列；已有该列的新建表则跳过
            $statements[] = array('sql' => "ALTER TABLE posts ADD COLUMN content_format TEXT DEFAULT 'html'", 'params' => array());
        }
        if ($rebuild_links) {
            $statements[] = array('sql' => 'DROP TABLE IF EXISTS taxonomies', 'params' => array());
            $statements[] = array('sql' => 'DROP TABLE IF EXISTS post_taxonomy', 'params' => array());
        }
        $ddl = array(
            "CREATE TABLE IF NOT EXISTS posts (wp_id INTEGER PRIMARY KEY, slug TEXT UNIQUE NOT NULL, title TEXT NOT NULL, content TEXT, content_format TEXT DEFAULT 'html', excerpt TEXT, date DATETIME NOT NULL, modified DATETIME, status TEXT DEFAULT 'published', categories TEXT, tags TEXT, featured_image TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)",
            "CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, user_login TEXT UNIQUE NOT NULL, user_pass TEXT NOT NULL, user_nicename TEXT, user_email TEXT, user_url TEXT, user_registered DATETIME DEFAULT CURRENT_TIMESTAMP, display_name TEXT, role TEXT DEFAULT 'admin', avatar_url TEXT, user_status INTEGER DEFAULT 1, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, settings TEXT)",
            "CREATE TABLE IF NOT EXISTS taxonomies (type TEXT NOT NULL, slug TEXT NOT NULL, name TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (type, slug))",
            "CREATE TABLE IF NOT EXISTS post_taxonomy (post_id INTEGER NOT NULL, taxonomy_type TEXT NOT NULL, taxonomy_slug TEXT NOT NULL, PRIMARY KEY (post_id, taxonomy_type, taxonomy_slug))",
            "CREATE INDEX IF NOT EXISTS idx_posts_date ON posts(date DESC)",
            "CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status)",
            "CREATE INDEX IF NOT EXISTS idx_posts_slug ON posts(slug)",
            "CREATE INDEX IF NOT EXISTS idx_pt_post ON post_taxonomy(post_id)",
            "CREATE INDEX IF NOT EXISTS idx_pt_slug ON post_taxonomy(taxonomy_slug)",
            "CREATE TABLE IF NOT EXISTS styles (id TEXT NOT NULL, version INTEGER NOT NULL, name TEXT, tpl_json TEXT, updated_at TEXT, PRIMARY KEY (id, version))",
            "CREATE TABLE IF NOT EXISTS app_passwords (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '', pass_hash TEXT NOT NULL, last_used_at TEXT DEFAULT '', created_at TEXT NOT NULL, revoked INTEGER NOT NULL DEFAULT 0)",
            "CREATE INDEX IF NOT EXISTS idx_apppw_user ON app_passwords(user_id)",
            "INSERT INTO _cfse_meta (k, v) VALUES ('schema_version', '" . (int) self::D1_SCHEMA_VERSION . "') ON CONFLICT(k) DO UPDATE SET v = excluded.v",
        );
        foreach ($ddl as $sql) {
            $statements[] = array('sql' => $sql, 'params' => array());
        }
        $this->d1_batch($statements);

        if ($rebuild_links) {
            $this->log('D1 schema 由 v1 迁移到 v2（复合主键 type+slug），关联表已重建。请做一次“全量导出”重建分类/标签数据。', 'WARNING');
        } else {
            $this->log('D1 schema 已就绪（v' . (int) self::D1_SCHEMA_VERSION . '）', 'DEBUG');
        }
    }
    private function write_to_d1($post_data, $categories, $tags) {
        // 0. 确保表结构存在（幂等）
        $this->ensure_d1_schema();

        // 整篇文章的全部 SQL 打包成一次批量提交（优先 Worker /d1-batch，替代逐句公网 D1 API）
        $statements = array();

        // 1. 写入 posts
        $statements[] = array(
            // 真 upsert：wp_id 冲突时更新，但「Worker 端诞生」的行（wp_id ≥ CFSE_WORKER_ID_MIN）不覆盖
            // —— 归属判据用 wp_id 形态（WP 文章 = WP post ID；Worker 文章 = 时间戳量级）
            'sql' => "INSERT INTO posts (wp_id, slug, title, content, content_format, excerpt, date, modified, status, categories, tags, featured_image)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                      ON CONFLICT(wp_id) DO UPDATE SET
                        slug = excluded.slug, title = excluded.title, content = excluded.content,
                        content_format = excluded.content_format, excerpt = excluded.excerpt,
                        date = excluded.date, modified = excluded.modified, status = excluded.status,
                        categories = excluded.categories, tags = excluded.tags, featured_image = excluded.featured_image
                      WHERE posts.wp_id < " . (int) CFSE_WORKER_ID_MIN,
            'params' => array(
                $post_data['wp_id'],
                $post_data['slug'],
                $post_data['title'],
                $post_data['content'],
                isset($post_data['content_format']) ? $post_data['content_format'] : 'html',
                $post_data['excerpt'],
                $post_data['date'],
                $post_data['modified'],
                isset($post_data['status']) ? $post_data['status'] : 'published',
                $post_data['categories'],
                $post_data['tags'],
                $post_data['featured_image'],
            ),
        );

        // 2. 删除旧的分类/标签关联
        $statements[] = array('sql' => 'DELETE FROM post_taxonomy WHERE post_id = ?', 'params' => array($post_data['wp_id']));

        // 3+4. 分类 / 标签关联（v2 复合主键 (type,slug)；name 写真名；幂等 upsert + 关联）
        $tax_map = array('category' => $categories, 'tag' => $tags);
        foreach ($tax_map as $type => $terms) {
            foreach ($terms as $term) {
                $slug = is_array($term) ? ($term['slug'] ?? '') : (is_object($term) ? $term->slug : '');
                $name = is_array($term) ? ($term['name'] ?? $slug) : (is_object($term) ? ($term->name ?? '') : $slug);
                if ($slug === '') continue;
                $statements[] = array(
                    'sql' => 'INSERT INTO taxonomies (type, slug, name) VALUES (?, ?, ?) ON CONFLICT(type, slug) DO UPDATE SET name=excluded.name',
                    'params' => array($type, $slug, $name),
                );
                $statements[] = array(
                    'sql' => 'INSERT OR IGNORE INTO post_taxonomy (post_id, taxonomy_type, taxonomy_slug) VALUES (?, ?, ?)',
                    'params' => array($post_data['wp_id'], $type, $slug),
                );
            }
        }

        $results = $this->d1_batch($statements);
        if ($results === false) return false;
        foreach ((array) $results as $r) {
            if ($r === false) return false; // 降级路径某句失败
        }
        return true;
    }

    /**
     * 取文章的分类/标签结构化列表：[{name, slug}]
     * ⚠️ WP 数据库中 term slug 对非 ASCII 本身就是小写 URL 编码形态（如中文分类存 %e8%83%a1...），
     *    直接原样入库即可与 Worker 路由/URL 对齐，切勿再 rawurlencode（会二次编码成 %25e8...）。
     */
    private function get_post_terms($post_id, $taxonomy) {
        $out = array();
        $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'all'));
        if (is_wp_error($terms) || empty($terms)) return $out;
        foreach ($terms as $t) {
            $out[] = array('name' => $t->name, 'slug' => $t->slug);
        }
        return $out;
    }

    private function d1_execute($sql, $params = array()) {
        $account_id = $this->config['account_id'];
        $api_token = $this->config['api_token'];
        $database_id = $this->config['d1_database'];
        if (empty($account_id) || empty($api_token) || empty($database_id)) {
            $this->log("D1配置不完整", 'ERROR');
            return false;
        }
        $url = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/d1/database/{$database_id}/query";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('sql' => $sql, 'params' => $params)));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $api_token, 'Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code >= 400) {
            $this->log("D1查询失败 HTTP {$http_code}: {$sql}", 'ERROR');
            return false;
        }
        $resp_data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($resp_data['success'])) {
            $this->log("D1 API业务错误: " . ($resp_data['errors'][0]['message'] ?? '未知'), 'ERROR');
            return false;
        }
        return true;
    }

    private function d1_query($sql, $params = array()) {
        $account_id = $this->config['account_id'];
        $api_token = $this->config['api_token'];
        $database_id = $this->config['d1_database'];
        if (empty($account_id) || empty($api_token) || empty($database_id)) return null;
        $url = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/d1/database/{$database_id}/query";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('sql' => $sql, 'params' => $params)));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $api_token, 'Content-Type: application/json'));
        $response = curl_exec($ch);
        curl_close($ch);
        $resp_data = json_decode($response, true);
        if (empty($resp_data['success']) || empty($resp_data['result'][0]['results'])) return null;
        return $resp_data['result'][0]['results'];
    }

    /**
     * 批量执行 SQL（整篇文章一次提交）：
     *   优先走 Worker /d1-batch（D1 binding 近端，一次 HTTP，约几十 ms/批）；
     *   Worker 不可达/未部署时降级为逐句直连 Cloudflare D1 API（慢，仅兜底）。
     *
     * @param array $statements 形如 [ ['sql'=>..., 'params'=>[...]], ... ]
     * @return array|false 每句的查询结果（SELECT 为行数组，写语句为 null）；全部不可用时 false
     */
    /**
     * S3 用的 URI 编码（SigV4 规范）：字母数字与 -_.~ 原样；'/' 视参数决定是否编码；
     * **已 percent-encoded 的 %xx 原样保留**（规范化成大写）—— 我们的 R2 key 里中文/空格就是这种形态，
     * 若把它二次编码成 %25xx 会导致签名与实际对象名不一致。
     */
    private function sigv4_uri_encode($s, $encode_slash = true) {
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if (ctype_alnum($c) || $c === '-' || $c === '_' || $c === '.' || $c === '~') {
                $out .= $c;
            } elseif ($c === '/') {
                $out .= $encode_slash ? '%2F' : '/';
            } else {
                // 注：`%` 一律编码为 `%25`（RFC 3986）。此前有一版把已编码的 `%xx` 原样保留（批BO 的离线
                // 推断：以为 R2 对象名就是小写 %xx），但真机实测：含 `%` 的 key 会 403 SignatureDoesNotMatch
                // （canonical URI 与服务端重算不一致）。真正的对象名用 rawurldecode() 还原，见 s3_request()。
                $out .= '%' . strtoupper(bin2hex($c));
            }
        }
        return $out;
    }

    /** canonical query string：按 key 升序 + 各自 URI 编码 */
    private function sigv4_canonical_query($query) {
        if (empty($query) || !is_array($query)) return '';
        ksort($query);
        $parts = array();
        foreach ($query as $k => $v) {
            $parts[] = $this->sigv4_uri_encode((string) $k, true) . '=' . $this->sigv4_uri_encode((string) $v, true);
        }
        return implode('&', $parts);
    }

    /** 从 target 解析 S3 连接参数 */
    private function s3_creds($target) {
        $endpoint = rtrim(isset($target['endpoint']) ? (string) $target['endpoint'] : '', '/');
        $parts = $endpoint !== '' ? parse_url($endpoint) : array();
        $host = isset($parts['host']) ? $parts['host'] : '';
        if ($host !== '' && !empty($parts['port'])) $host .= ':' . $parts['port'];
        return array(
            'endpoint' => $endpoint,
            'scheme' => !empty($parts['scheme']) ? $parts['scheme'] : 'https',
            'host' => $host,
            'bucket' => isset($target['bucket']) ? trim((string) $target['bucket'], '/') : '',
            'region' => !empty($target['region']) ? (string) $target['region'] : 'auto',
            'access_key' => isset($target['access_key']) ? (string) $target['access_key'] : '',
            'secret_key' => isset($target['secret_key']) ? (string) $target['secret_key'] : '',
            'path_style' => $this->s3_path_style($target, $host),
            'path_prefix' => isset($target['path_prefix']) ? trim((string) $target['path_prefix'], '/') : '',
            'public_url' => rtrim(isset($target['public_url']) ? (string) $target['public_url'] : '', '/'),
        );
    }

    /**
     * 寻址方式：auto（默认，按 endpoint 判断）/ path（/bucket/key）/ virtual（bucket.host/key）。
     * auto 规则：R2 存储端点、localhost、IP、带端口的 ⇒ path-style；*.amazonaws.com ⇒ virtual-host；其它自定义域名 ⇒ virtual-host（AWS 惯例），可用显式值覆盖。
     */
    private function s3_path_style($target, $host) {
        $mode = isset($target['path_style']) ? (string) $target['path_style'] : 'auto';
        if ($mode === 'path') return true;
        if ($mode === 'virtual') return false;
        $h = strtolower((string) $host);
        if ($h === '') return true;
        if (strpos($h, 'r2.cloudflarestorage.com') !== false) return true;
        if (strpos($h, 'amazonaws.com') !== false) return false;
        if (strpos($h, 'localhost') !== false) return true;
        if (preg_match('/^\d+\.\d+\.\d+\.\d+/', $h)) return true;
        if (strpos($h, ':') !== false) return true;
        return false;
    }

    /** S3 错误分类：把 HTTP+返回体归成可读的 code / hint（供 UI 直接提示） */
    private function s3_classify($code, $text) {
        $x = strtolower((string) $text);
        if ($code >= 200 && $code < 300) return array('code' => 'ok', 'hint' => '连通且签名通过');
        if ($code === 301 || $code === 307) return array('code' => 'wrong_region', 'hint' => '被重定向（region 或寻址方式不对；R2 请把 region 填 auto）');
        if ($code === 401) return array('code' => 'unauthorized', 'hint' => '凭证被拒（401）：access_key 不存在或已失效。R2 对未知 AK 直接返回 401；AK 存在但 secret 不对通常是 403 SignatureDoesNotMatch');
        if ($code === 403 && strpos($x, 'signaturedoesnotmatch') !== false) return array('code' => 'signature_mismatch', 'hint' => 'Access Key / Secret Key 不对，或 region 与实际不符');
        if ($code === 403 && (strpos($x, 'accessdenied') !== false || strpos($x, 'forbidden') !== false)) return array('code' => 'access_denied', 'hint' => '凭证能通过签名但无权限（检查该 Key 对本 bucket 的权限；若只是不能列举，上传通常仍可用）');
        if ($code === 403) return array('code' => 'forbidden', 'hint' => '被拒（403）：多为签名或权限问题，见 detail');
        if ($code === 404 && strpos($x, 'nosuchbucket') !== false) return array('code' => 'no_such_bucket', 'hint' => 'bucket 名不对（或 region 不对）');
        if ($code === 404) return array('code' => 'not_found', 'hint' => '对象不存在（读对象时正常；上传时出现说明 bucket/path_prefix 有问题）');
        if ($code === 400 && strpos($x, 'authorizationheader') !== false) return array('code' => 'bad_auth_header', 'hint' => '签名头格式错误（endpoint 是否多带了路径？应只填域名）');
        if ($code === 400 && strpos($x, 'invalidargument') !== false && (strpos($x, 'access key') !== false || strpos($x, 'credential') !== false)) {
            return array('code' => 'bad_credential', 'hint' => 'access_key 格式不对（R2 的 S3 Access Key 为 32 位；请用 R2 → Manage API Tokens 里创建的「S3 凭证」，不要用 Global API Token）');
        }
        if ($code === 400 && strpos($x, 'region') !== false) return array('code' => 'wrong_region', 'hint' => 'region 不对（R2 一般填 auto）');
        if ($code === 400) return array('code' => 'bad_request', 'hint' => '请求被拒（400）：可能是「寻址方式」不对，改成 path 或 virtual 再试');
        if ($code === 501 || $code === 411) return array('code' => 'unsupported', 'hint' => '该存储不支持这个请求（HTTP ' . $code . '）');
        return array('code' => 'http_' . $code, 'hint' => 'HTTP ' . $code . '，见 detail 里的返回体');
    }

    /**
     * 对 s3 目标发一次签名请求（自动按 path_style 决定 bucket 放 host 还是路径）。
     * @return array {ok, http, code, hint, detail}
     */
    private function s3_request($target, $method, $key = '', $query = array(), $body = '', $content_type = '') {
        $c = $this->s3_creds($target);
        if ($c['host'] === '') return array('ok' => false, 'http' => 0, 'code' => 'config', 'hint' => 'endpoint 未填写或不合法（应形如 https://<account>.r2.cloudflarestorage.com）', 'detail' => '');
        if ($c['bucket'] === '') return array('ok' => false, 'http' => 0, 'code' => 'config', 'hint' => 'bucket 未填写', 'detail' => '');
        if ($c['access_key'] === '' || $c['secret_key'] === '') return array('ok' => false, 'http' => 0, 'code' => 'config', 'hint' => 'access_key / secret_key 未填写', 'detail' => '');

        $full_key = $c['path_prefix'] !== '' ? ($c['path_prefix'] . '/' . ltrim((string) $key, '/')) : ltrim((string) $key, '/');
        // 对象名 = 解码后的原文：本地产物路径是 URL 形态（中文 slug 呈现为小写 %xx），而对象存储里
        // 真正存的是解码后的字符串。这样 (1) 签名一致、(2) 浏览器访问 /%xx/ 时服务端解码后能命中。
        $full_key = rawurldecode($full_key);
        $host = $c['path_style'] ? $c['host'] : ($c['bucket'] . '.' . $c['host']);
        $path = $c['path_style']
            ? ('/' . $c['bucket'] . ($full_key !== '' ? '/' . $full_key : ''))
            : ('/' . $full_key);
        $creds = array(
            'access_key' => $c['access_key'], 'secret_key' => $c['secret_key'],
            'region' => $c['region'], 'service' => 's3', 'host' => $host,
        );
        $signed = $this->sigv4_sign($creds, $method, $path, $query, $body);
        $url = $c['scheme'] . '://' . $host . $this->sigv4_uri_encode($path, false)
            . (!empty($query) ? ('?' . $this->sigv4_canonical_query($query)) : '');
        $headers = array(
            'Authorization' => $signed['authorization'],
            'x-amz-date' => $signed['x-amz-date'],
            'x-amz-content-sha256' => $signed['payload_hash'],
        );
        if ($content_type !== '') $headers['Content-Type'] = $content_type;
        $resp = wp_remote_request($url, array(
            'method' => $method, 'timeout' => 60, 'sslverify' => false, 'headers' => $headers, 'body' => $body,
        ));
        if (is_wp_error($resp)) {
            $msg = $resp->get_error_message();
            $is_timeout = (strpos($msg, 'timed out') !== false || strpos($msg, 'error 28') !== false);
            return array(
                'ok' => false, 'http' => 0,
                'code' => $is_timeout ? 'timeout' : 'network',
                'hint' => ($is_timeout ? '请求超时（检查 endpoint 与网络出口）：' : '网络不可达：') . $msg,
                'detail' => '',
            );
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $text = (string) wp_remote_retrieve_body($resp);
        $cls = $this->s3_classify($code, $text);
        return array(
            'ok' => ($code >= 200 && $code < 300),
            'http' => $code,
            'code' => $cls['code'],
            'hint' => $cls['hint'],
            // 成功响应（如 ListObjects 的 XML）原样返回，便于列举/诊断；错误响应压缩空白并截断，避免日志爆炸。
            'detail' => ($code >= 200 && $code < 300) ? $text : mb_substr(preg_replace('/\s+/', ' ', $text), 0, 300),
        );
    }

    /**
     * S3 连通性 + 签名自检：ListObjectsV2（max-keys=1）
     *   200 ⇒ 连通、签名、bucket、列举权限全对；
     *   403 SignatureDoesNotMatch ⇒ 凭证/region 错；403 AccessDenied ⇒ 无列举权限（上传可能仍可用）；
     *   404 NoSuchBucket ⇒ bucket 名错。
     */
    private function s3_test_connection($target) {
        return $this->s3_request($target, 'GET', '', array('list-type' => '2', 'max-keys' => '1'));
    }

    /**
     * AWS Signature V4 签名（用于 S3 兼容对象存储：R2 / AWS S3 / MinIO / B2 等）。
     *
     * @param array  $creds   {access_key, secret_key, region, service(默认 s3), host}
     * @param string $method  GET / PUT / DELETE …
     * @param string $path    canonical 路径（path-style 时含 /bucket；不含 query）
     * @param array  $query   查询参数 [k => v]
     * @param string $payload 请求体（用于算 payload hash；GET/DELETE 传 ''）
     * @param string $date    20150830T123600Z；默认取当前 UTC
     * @param bool   $sign_content_sha 是否把 x-amz-content-sha256 计入签名头
     *        （真实 S3/R2 请求建议签；AWS 官方 get-vanilla 向量只签 host 与 x-amz-date，自检时传 false）
     * @return array 含 authorization / x-amz-date / payload_hash / canonical_request / string_to_sign / signature
     */
    private function sigv4_sign($creds, $method, $path, $query = array(), $payload = '', $date = null, $sign_content_sha = true) {
        $ak = isset($creds['access_key']) ? (string) $creds['access_key'] : '';
        $sk = isset($creds['secret_key']) ? (string) $creds['secret_key'] : '';
        $region = !empty($creds['region']) ? (string) $creds['region'] : 'us-east-1';
        $service = !empty($creds['service']) ? (string) $creds['service'] : 's3';
        $host = isset($creds['host']) ? (string) $creds['host'] : '';
        if ($date === null) $date = gmdate('Ymd\THis\Z');
        $date_stamp = substr($date, 0, 8);

        $payload_hash = hash('sha256', (string) $payload);
        $canonical_uri = $this->sigv4_uri_encode($path, false);
        $canonical_query = $this->sigv4_canonical_query($query);
        // 参与的签名头（按字典序）；是否包含 x-amz-content-sha256 由参数决定
        $sign_headers = array('host' => $host, 'x-amz-date' => $date);
        if ($sign_content_sha) $sign_headers['x-amz-content-sha256'] = $payload_hash;
        ksort($sign_headers);
        $canonical_headers = '';
        foreach ($sign_headers as $hk => $hv) $canonical_headers .= $hk . ':' . $hv . "\n";
        $signed_headers = implode(';', array_keys($sign_headers));
        $canonical_request = $method . "\n" . $canonical_uri . "\n" . $canonical_query . "\n"
            . $canonical_headers . "\n" . $signed_headers . "\n" . $payload_hash;

        $scope = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';
        $string_to_sign = "AWS4-HMAC-SHA256\n" . $date . "\n" . $scope . "\n" . hash('sha256', $canonical_request);

        $k_date = hash_hmac('sha256', $date_stamp, 'AWS4' . $sk, true);
        $k_region = hash_hmac('sha256', $region, $k_date, true);
        $k_service = hash_hmac('sha256', $service, $k_region, true);
        $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
        $signature = hash_hmac('sha256', $string_to_sign, $k_signing);

        return array(
            'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $ak . '/' . $scope
                . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature,
            'x-amz-date' => $date,
            'payload_hash' => $payload_hash,
            'canonical_request' => $canonical_request,
            'string_to_sign' => $string_to_sign,
            'signature' => $signature,
        );
    }

    /** local 类型目标的根目录（未配置则用 CFSE_LOCAL_DIR） */
    private function target_dir($target) {
        $dir = (isset($target['dir']) && $target['dir'] !== '') ? (string) $target['dir'] : CFSE_LOCAL_DIR;
        return rtrim($dir, '/\\');
    }

    /** 目标类型（默认 cfse-worker） */
    private function target_type($target) {
        return isset($target['type']) ? (string) $target['type'] : 'cfse-worker';
    }

    /**
     * 写文章数据：只对「具备 d1 能力」的目标执行。
     * 没有这样的目标（如纯静态档只有 local）⇒ **不联网**，返回等长 null 数组（不算失败）。
     */
    private function d1_batch($statements) {
        $statements = array_values($statements);
        if (empty($statements)) return array();

        $targets = isset($this->config['hosting_targets']) ? $this->config['hosting_targets'] : array();
        $usable = array();
        foreach ($targets as $t) {
            if (!empty($t['capabilities']['d1'])) $usable[] = $t;
        }
        if (empty($usable)) {
            $this->log('没有启用「D1 数据」能力的托管目标，跳过 ' . count($statements) . ' 条 SQL（纯静态档无需写库）', 'DEBUG');
            return array_fill(0, count($statements), null);
        }
        foreach ($usable as $t) {
            if ($this->target_type($t) === 'cfse-worker') {
                $out = $this->worker_d1_batch($t, $statements);
                if (is_array($out) && !empty($out)) return $out;
            } else {
                $this->log('托管目标 ' . (isset($t['id']) ? $t['id'] : '?') . ' 的类型 ' . $this->target_type($t) . ' 尚未实现写库', 'WARNING');
            }
        }
        return array();
    }

    /** 单个 cfse-worker 目标写库（原 d1_batch 实现，含 CF API 降级兜底） */
    private function worker_d1_batch($target, $statements) {
        $statements = array_values($statements);
        if (empty($statements)) return array();

        $site_url = rtrim(isset($target['endpoint']) ? (string) $target['endpoint'] : '', '/');
        $auth = isset($target['auth_key']) ? (string) $target['auth_key'] : '';
        if ($site_url !== '' && $auth !== '') {
            $resp = wp_remote_post($site_url . '/d1-batch', array(
                'timeout'    => 60,
                'sslverify'  => false,
                'headers'    => array('X-Auth-Key' => $auth, 'Content-Type' => 'application/json'),
                'body'       => wp_json_encode(array('statements' => $statements)),
            ));
            if (!is_wp_error($resp)) {
                $code = wp_remote_retrieve_response_code($resp);
                $body = json_decode(wp_remote_retrieve_body($resp), true);
                if ($code === 200 && !empty($body['success'])) {
                    $this->log('D1 批量执行 ' . count($statements) . ' 条（Worker）', 'DEBUG');
                    if (isset($body['results']) && is_array($body['results'])) {
                        return $body['results'];
                    }
                    return array_fill(0, count($statements), null);
                }
                $this->log('D1 批量执行失败（HTTP ' . $code . '），降级为直连 API: ' . ($body['error'] ?? '未知'), 'WARNING');
            }
        }

        // 降级：逐句直连 Cloudflare D1 API（保留原有行为作为兜底）
        $out = array();
        foreach ($statements as $st) {
            $sql = isset($st['sql']) ? (string) $st['sql'] : '';
            $params = isset($st['params']) && is_array($st['params']) ? $st['params'] : array();
            if (preg_match('/^\s*(select|pragma)\b/is', $sql)) {
                $out[] = $this->d1_query($sql, $params);
            } else {
                $out[] = $this->d1_execute($sql, $params) ? null : false;
            }
        }
        return $out;
    }

    /**
     * 同步站点基础信息到 D1 settings（key='site'，JSON：{name,url}）。
     * 站点信息运行时由 Worker 读取（渲染 {{SITE_NAME}}/{{SITE_URL}}），本方法在同步模板/部署时调用。
     */
    private function sync_site_settings() {
        // 站点名存纯文本（解码 WP 存库的 HTML 实体，如 &#039; → '），Worker 渲染/转义时统一处理
        $name = function_exists('wp_specialchars_decode')
            ? wp_specialchars_decode((string) get_option('blogname', ''), ENT_QUOTES)
            : (string) get_option('blogname', '');
        $url = isset($this->config['site_url']) ? rtrim($this->config['site_url'], '/') : '';
        $storage = function_exists('cfse_storage_pattern') ? cfse_storage_pattern() : '{slug}/index.html';
        // WP 时区偏移（秒）：Worker 后台发布文章时用它生成与 WP 一致的本地时间
        $gmt_offset = (float) get_option('gmt_offset', 0);
        if ($name === '' && $url === '') return;
        $payload = wp_json_encode(array(
            'name'            => $name,
            'url'             => $url,
            'storagePattern'  => $storage,
            'timezoneOffset'  => (int) round($gmt_offset * 3600),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->d1_batch(array(array(
            'sql' => "INSERT INTO settings (k, v) VALUES ('site', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v",
            'params' => array($payload),
        )));
        // 缓存策略：同步到 D1 settings（key='cache'），Worker 端 getCacheConfig 读取
        $cache_payload = wp_json_encode(array(
            'ttl_list'         => (int) $this->config['cache_ttl_list'],
            'ttl_sidebar'      => (int) $this->config['cache_ttl_sidebar'],
            'ttl_site'         => (int) $this->config['cache_ttl_site'],
            'ttl_feed'         => (int) $this->config['cache_ttl_feed'],
            'feed_count'       => (int) $this->config['feed_count'],
            'purge_on_publish' => (bool) $this->config['purge_lists'],
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->d1_batch(array(array(
            'sql' => "INSERT INTO settings (k, v) VALUES ('cache', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v",
            'params' => array($cache_payload),
        )));
        // 站点信息/缓存策略变更 → 清 Worker 端 site:ctx（打包值），长 TTL 下也能即时生效；顺带清旧版独立键
        $site_url = isset($this->config['site_url']) ? rtrim($this->config['site_url'], '/') : '';
        $auth = isset($this->config['auth_key']) ? $this->config['auth_key'] : '';
        if ($site_url !== '' && $auth !== '') {
            try {
                wp_remote_post($site_url . '/purge-cache', array(
                    'timeout'    => 5,
                    'sslverify'  => false,
                    'headers'    => array('X-Auth-Key' => $auth, 'Content-Type' => 'application/json'),
                    'body'       => wp_json_encode(array('keys' => array('site:ctx', 'cache:config', 'rss:feed'))),
                ));
            } catch (Throwable $e) { /* 清理失败不阻断 */ }
        }
        $this->log('已同步站点信息与缓存策略到 D1 settings', 'DEBUG');
    }

    // ============================================
    // 删除文章
    // ============================================
    public function delete_post($post_input) {
        if (is_object($post_input)) $post = $post_input;
        else $post = get_post($post_input);
        if (!$post || !isset($post->ID)) return;
        $post_id = $post->ID;
        if (get_post_meta($post_id, '_cfse_probe', true) === '1') {
            $this->log("文章 {$post_id} 为外观探针文章，跳过删除清理", 'DEBUG');
            return;
        }
        if (isset(self::$deleted_post_ids[$post_id])) return;
        self::$deleted_post_ids[$post_id] = true;
        // get_post_storage_path() 已返回含 index.html 的完整文件路径
        $r2_path = $this->get_post_storage_path($post);

        // auto-draft 连 D1 都没有 ⇒ 整段跳过
        if ($post->post_status === 'auto-draft') {
            $this->log("文章 {$post_id} 为 auto-draft，跳过远端清理", 'DEBUG');
            return;
        }
        // 草稿等尚无固定链接的文章路径会退化成 ?p=<ID> ⇒ 没有 R2 单页，但 D1 行是真实存在的（草稿也会入库）
        $has_single = (strpos($r2_path, '?') === false);
        if (!$has_single) {
            $this->log("文章 {$post_id}（{$post->post_status}）无固定链接/无单页，仅清理 D1", 'DEBUG');
        }

        // ① 本地产物：立即删除（无网络，快）—— 之前漏了这一步，导致 cfse-static/ 里残留
        $this->delete_local_artifact($r2_path);

        // ② 远端清理（R2 + D1）：攒到请求结束一次性批量执行。
        //    批量删除 20 篇时，逐篇同步会变成 40 次公网请求（这就是后台删除卡顿的原因）；聚合后只需 2 次。
        if ($has_single) {
            self::$pending_delete_paths[] = $r2_path;
            self::$pending_delete_paths[] = $r2_path . '.gz';
        }
        // D1 里存的是「远端 wp_id」（Worker 端诞生的篇在 WP 侧是自增 ID，映射记在 meta）
        $d1_wp_id = $this->get_remote_wp_id($post_id);
        if (!$d1_wp_id) $d1_wp_id = $post_id;
        self::$pending_delete_ids[] = $d1_wp_id;
        if (!self::$pending_deletes_registered) {
            self::$pending_deletes_registered = true;
            add_action('shutdown', array($this, 'flush_pending_deletes'), 5);
        }

        $this->log("文章 {$post_id} 已删除（本地产物已清；远端清理将在本次请求结束时批量执行）", 'INFO');
    }

    /** 待清理的远端条目（请求结束时一次性批量提交，避免批量删除时逐篇等待 Worker） */
    private static $pending_delete_paths = array();
    private static $pending_delete_ids = array();
    private static $pending_deletes_registered = false;

    /**
     * 删除本地产物（CFSE_LOCAL_DIR 下与 R2 key 同构的路径）并顺带清掉产生的空目录。
     */
    private function delete_local_artifact($r2_path) {
        $local = CFSE_LOCAL_DIR . '/' . ltrim($r2_path, '/');
        if (file_exists($local)) {
            if (@unlink($local)) {
                $this->log("本地产物已删除: {$local}", 'DEBUG');
            } else {
                $this->log("本地产物删除失败: {$local}", 'WARNING');
            }
        }
        // 向上清空目录（最多 4 层：年月/slug 等），非空即停
        $dir = dirname($local);
        for ($i = 0; $i < 4; $i++) {
            if (rtrim($dir, '/\\') === rtrim(CFSE_LOCAL_DIR, '/\\') || !is_dir($dir)) break;
            $items = @scandir($dir);
            if ($entries = is_array($items) ? array_diff($items, array('.', '..')) : array()) break;
            if (!@rmdir($dir)) break;
            $dir = dirname($dir);
        }
    }

    /**
     * 请求结束（shutdown）时一次性提交远端清理：R2 一个请求（paths 数组）+ D1 一个批量请求。
     */
    public function flush_pending_deletes() {
        if (empty(self::$pending_delete_paths) && empty(self::$pending_delete_ids)) return;
        $paths = array_values(array_unique(array_filter(self::$pending_delete_paths)));
        $ids = array_values(array_unique(array_filter(self::$pending_delete_ids)));
        self::$pending_delete_paths = array();
        self::$pending_delete_ids = array();
        try {
            if (!empty($paths)) $this->delete_from_r2($paths);
            if (!empty($ids)) {
                $this->ensure_d1_schema();
                $stmts = array();
                foreach ($ids as $id) {
                    $stmts[] = array('sql' => 'DELETE FROM post_taxonomy WHERE post_id = ?', 'params' => array($id));
                    $stmts[] = array('sql' => 'DELETE FROM posts WHERE wp_id = ?', 'params' => array($id));
                }
                $stmts[] = array('sql' => 'DELETE FROM taxonomies WHERE slug NOT IN (SELECT DISTINCT taxonomy_slug FROM post_taxonomy)', 'params' => array());
                $this->d1_batch($stmts);
            }
            $this->log('批量删除远端清理完成：R2 ' . count($paths) . ' 个路径、D1 ' . count($ids) . ' 篇', 'INFO');
        } catch (Throwable $e) {
            $this->log('批量删除远端清理异常：' . $e->getMessage(), 'ERROR');
        }
    }

    /**
     * 删除 R2 文件（支持单 path 或多个 paths，一次请求；不存在/多余路径无害）
     *
     * @param string|array $paths
     * @return bool
     */
    private function delete_from_r2($paths) {
        try {
            $paths = array_values(array_filter((array) $paths, 'strlen'));
            if (empty($paths)) return true;
            // 多托管：对所有「具备静态文件能力」的目标删除
            $res = $this->for_each_target('r2', function ($t) use ($paths) {
                return $this->target_delete($t, $paths);
            });
            if ($res['tried'] === 0) {
                $this->log('没有启用「静态文件」能力的托管目标，跳过删除：' . implode(', ', $paths), 'DEBUG');
            } elseif (!$res['ok']) {
                return false;
            } else {
                $this->log('文件已删除（' . $res['tried'] . ' 个目标）：' . implode(', ', $paths) . ' → ' . wp_json_encode($res['results']), 'INFO');
            }
            return true;
        } catch (Throwable $e) {
            $this->log("delete_from_r2 内部异常：" . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    // ============================================
    // 风格资源 → Worker assets（把 R2 里已上传的 style/ 前缀资源拉到 worker/static，随部署打包）
    // ============================================
    public function handle_sync_style_assets() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cf_sync_style_assets');
        @set_time_limit(0);
        $r = $this->sync_style_assets();
        wp_redirect(add_query_arg('style_assets_synced', $r === false ? '0' : (string) (int) $r, admin_url('options-general.php?page=cf-static-export-pro')));
        exit;
    }

    /**
     * 把 Worker R2 里已上传的风格资源（style/ 前缀）下载到 worker/static/style/，
     * 随 wrangler deploy 打包为静态 assets（assets 优先于 R2，免费且不触发 Worker）。
     * @return int|false 成功下载的文件数；配置缺失/请求失败返回 false
     */
    public function sync_style_assets() {
        $site_url = isset($this->config['site_url']) ? rtrim($this->config['site_url'], '/') : '';
        $auth = isset($this->config['auth_key']) ? $this->config['auth_key'] : '';
        if ($site_url === '' || $auth === '') return false;
        $resp = wp_remote_get($site_url . '/style-list', array(
            'timeout' => 30,
            'sslverify' => false,
            'headers' => array('X-Auth-Key' => $auth),
        ));
        if (is_wp_error($resp)) { $this->log('同步风格资源：清单获取失败 ' . $resp->get_error_message(), 'WARNING'); return false; }
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        $items = (is_array($data) && isset($data['items']) && is_array($data['items'])) ? $data['items'] : array();
        $static_root = CFSE_PLUGIN_DIR . 'worker/static/';
        $max_bytes = 8 * 1024 * 1024;
        $ok = 0; $skip = 0;
        foreach ($items as $it) {
            $key = isset($it['key']) ? (string) $it['key'] : '';
            $size = isset($it['size']) ? (int) $it['size'] : 0;
            if ($key === '' || strpos($key, 'style/') !== 0 || strpos($key, '..') !== false) continue;
            if ($size > $max_bytes) { $skip++; continue; }
            $body = wp_remote_get($site_url . '/' . $key, array('timeout' => 30, 'sslverify' => false));
            if (is_wp_error($body)) { $skip++; continue; }
            $bytes = wp_remote_retrieve_body($body);
            if (!is_string($bytes) || $bytes === '') { $skip++; continue; }
            $dest = $static_root . $key;
            if (!is_dir(dirname($dest))) wp_mkdir_p(dirname($dest));
            if (file_put_contents($dest, $bytes) !== false) { $ok++; } else { $skip++; }
        }
        $this->log("同步风格资源完成：{$ok} 个成功，{$skip} 个跳过（过大或失败）", 'DEBUG');
        return $ok;
    }

    // ============================================
    // 主题静态资源 → Worker assets（随部署打包，无需每次查 R2）
    // assets-first：/wp-content/themes/... 直接由 Cloudflare 边缘命中，免费且不触发 Worker
    // ============================================
    public function handle_stage_theme_assets() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cf_stage_theme_assets');
        @set_time_limit(0);
        $result = $this->stage_theme_assets();
        wp_redirect(add_query_arg('assets_staged', $result ? '1' : '0', admin_url('options-general.php?page=cf-static-export-pro')));
        exit;
    }

    /**
     * 把激活主题（父主题+子主题）目录下 css/js/图片/字体等复制到 worker/static/wp-content/themes/<slug>/，
     * 保持与站点 URL 相同的目录结构，wrangler deploy 时随 [assets] 上传。
     * @return bool
     */
    public function stage_theme_assets() {
        $static_root = CFSE_PLUGIN_DIR . 'worker/static/wp-content/themes/';
        $allowed = array('css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'map', 'txt');
        $max_bytes = 8 * 1024 * 1024; // 单文件上限 8MB，避免超大媒体打进 assets
        $dirs = array_unique(array(get_template_directory(), get_stylesheet_directory()));
        $total = 0;
        $failed = 0;
        foreach ($dirs as $theme_dir) {
            $slug = basename($theme_dir);
            $dest = $static_root . $slug . '/';
            if (!is_dir($theme_dir)) {
                $this->log("主题目录不存在: {$theme_dir}", 'WARNING');
                continue;
            }
            // 清理该主题旧的 staging 文件，避免删除主题文件后残留
            if (is_dir($dest)) {
                $this->rrmdir($dest);
            }
            wp_mkdir_p($dest);

            $files = array();
            $this->scan_theme_dir($theme_dir, $allowed, $files);
            foreach ($files as $file) {
                if (@filesize($file) > $max_bytes) {
                    $this->log("主题资源过大跳过（>8MB）: " . basename($file), 'WARNING');
                    continue;
                }
                $rel = ltrim(str_replace($theme_dir, '', $file), '/');
                $target = $dest . $rel;
                wp_mkdir_p(dirname($target));
                if (@copy($file, $target)) {
                    $total++;
                } else {
                    $failed++;
                    $this->log("主题资源复制失败: {$rel}", 'WARNING');
                }
            }
        }
        $this->log("主题资源已打包到 Worker assets：成功 {$total}，失败 {$failed}（部署时随 Worker 上传）", 'INFO');
        return $failed === 0;
    }

    private function scan_theme_dir($dir, $allowed, &$out) {
        if (!is_dir($dir)) return;
        $items = @scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                if (in_array($item, array('node_modules', '.git', '.svn', 'vendor', 'cache'), true)) continue;
                $this->scan_theme_dir($path, $allowed, $out);
            } elseif (is_file($path)) {
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed, true)) {
                    $out[] = $path;
                }
            }
        }
    }

    /** 递归删除目录 */
    private function rrmdir($dir) {
        if (!is_dir($dir)) return;
        $items = @scandir($dir);
        if ($items === false) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ============================================
    // Worker 代码管理
    // ============================================

    public function handle_sync_routes() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cf_sync_routes');
        @set_time_limit(0);
        $result = $this->sync_routes();
        wp_redirect(add_query_arg('routes_synced', $result ? '1' : '0', admin_url('options-general.php?page=cf-static-export-pro')));
        exit;
    }

    private function sync_routes() {
        $this->log('开始同步路由规则...', 'INFO');
        $template_path = CFSE_WORKER_DIR . 'routes.template.js';
        $target_path = CFSE_WORKER_DIR . 'routes.js';

        if (!file_exists($template_path)) {
            $this->log('路由模板文件不存在: routes.template.js', 'ERROR');
            return false;
        }

        $template = file_get_contents($template_path);
        $permalink = get_option('permalink_structure');
        $route_pattern = $this->permalink_to_route_pattern($permalink);
        $storage_pattern = $this->permalink_to_storage_pattern($permalink);

        $content = str_replace('{{ROUTE_PATTERN}}', $route_pattern, $template);
        $content = str_replace('{{STORAGE_PATTERN}}', $storage_pattern, $content);
        $content = str_replace('{{SITE_URL}}', $this->config['site_url'], $content);
        $content = str_replace('{{CFSE_PER_PAGE}}', $this->config['posts_per_page'], $content);

        if (file_put_contents($target_path, $content) === false) {
            $this->log('写入 routes.js 失败', 'ERROR');
            return false;
        }

        $this->log('路由规则同步完成', 'INFO');
        return true;
    }

/**
 * 修复 Permalink 转路由模式 - 支持更多标签
 */
private function permalink_to_route_pattern($permalink) {
    if (empty($permalink)) {
        return '/^\\/([^/]+)\\/$/';
    }
    
    $pattern = preg_quote($permalink, '/');
    
    // 支持的标签
    $tags = array(
        '%year%' => '(\\d{4})',
        '%monthnum%' => '(\\d{2})',
        '%day%' => '(\\d{2})',
        '%hour%' => '(\\d{2})',
        '%minute%' => '(\\d{2})',
        '%second%' => '(\\d{2})',
        '%postname%' => '([^/]+)',
        '%post_id%' => '(\\d+)',
        '%category%' => '([^/]+)',
        '%author%' => '([^/]+)',
    );
    
    foreach ($tags as $tag => $regex) {
        $pattern = str_replace($tag, $regex, $pattern);
    }
    
    // 处理可选部分（括号中的内容）
    $pattern = preg_replace('/\(\?:(.*?)\)/', '(?:$1)?', $pattern);
    
    // 生成 JS 正则字面量，必须 ^...$ 全锚定：
    // 例 /%postname%/ → /^\/([^/]+)\/$/（只匹配单段 slug 路径，不会误吞 /category/x/、/tag/x/、/2024/）
    return '/^' . $pattern . '$/';
}

/**
 * 修复 Permalink 转存储模式
 */
private function permalink_to_storage_pattern($permalink) {
    if (empty($permalink) || $permalink === '/%postname%/') {
        return '{slug}/index.html';
    }
    
    $pattern = $permalink;
    
    $tags = array(
        '%year%' => '{year}',
        '%monthnum%' => '{month}',
        '%day%' => '{day}',
        '%hour%' => '{hour}',
        '%minute%' => '{minute}',
        '%second%' => '{second}',
        '%postname%' => '{slug}',
        '%post_id%' => '{id}',
        '%category%' => '{category}',
        '%author%' => '{author}',
    );
    
    foreach ($tags as $tag => $replacement) {
        $pattern = str_replace($tag, $replacement, $pattern);
    }
    
    $pattern = trim($pattern, '/');
    return $pattern . '/index.html';
}
	/**
	 * 同步模板：从模板编辑器保存的自定义模板重新生成 worker/src/templates.js
	 */
public function handle_sync_templates() {
    if (!current_user_can('manage_options')) wp_die('权限不足');
    check_admin_referer('cf_sync_templates');
    
    // 重新生成 templates.js
    if (function_exists('cfse_generate_templates_from_custom')) {
        cfse_generate_templates_from_custom();
    }

    // 站点信息运行时由 Worker 从 D1 settings 读取，同步模板时一并更新
    $this->sync_site_settings();

    $this->log('模板已同步', 'INFO');
    wp_redirect(add_query_arg('templates_synced', '1', admin_url('options-general.php?page=cf-static-export-pro')));
    exit;
}
public function handle_deploy_worker() {
    if (!current_user_can('manage_options')) wp_die('权限不足');
    check_admin_referer('cf_deploy_worker');
    @set_time_limit(0);

    // 强制引入模板编辑器模块
    $editor_path = CFSE_PLUGIN_DIR . 'views/template-editor.php';
    if (file_exists($editor_path)) {
        require_once $editor_path;
    }

    // 站点信息同步到 D1 settings（部署前保证运行时取值就绪）
    $this->sync_site_settings();

    // 重新生成 routes.js（源为 routes.template.js；每次部署同步，确保 permalink/每页数等最新）
    $this->sync_routes();

    // 生成/部署分支：激活风格模式下直接使用 styles/<id> 快照产物（跳过再生成与主题资源同步）；
    // 默认（WP 模板模式）按原有流程 regenerate + stage_theme_assets。
    $active_style = get_option('cfse_active_style', '');
    $style_root = ($active_style !== '') ? CFSE_PLUGIN_DIR . 'styles/' . $active_style : '';
    $using_style = ($style_root !== '' && is_dir($style_root) && file_exists($style_root . '/templates.js'));
    if ($using_style) {
        $this->log("部署使用激活风格: {$active_style}（跳过模板再生成与主题资源同步）", 'INFO');
    } else {
        if ($active_style !== '') {
            $this->log('激活风格目录缺失，回退为 WP 模板模式: ' . $style_root, 'WARNING');
            delete_option('cfse_active_style');
        }
        // 生成最新的 templates.js
        if (function_exists('cfse_generate_templates_from_custom')) {
            cfse_generate_templates_from_custom();
        }

        // 把当前激活主题的静态资源（css/js/图片/字体）复制到 worker/static，随部署打包为 assets
        $this->stage_theme_assets();
    }

    $deployed = $this->deploy_worker();
    wp_redirect(add_query_arg('deployed', $deployed ? '1' : '0', admin_url('options-general.php?page=cf-static-export-pro')));
    exit;
}

    private function deploy_worker() {
        $worker_dir = CFSE_PLUGIN_DIR . 'worker';
        if (!is_dir($worker_dir)) {
            $this->log('Worker 目录不存在: ' . $worker_dir, 'ERROR');
            return false;
        }

        // 确保 assets 目录存在（wrangler [assets] directory 缺失会部署失败）
        $static_dir = CFSE_PLUGIN_DIR . 'worker/static';
        if (!is_dir($static_dir)) {
            wp_mkdir_p($static_dir);
        }

        $required = array('src/index.js', 'src/routes.js', 'src/templates.js', 'src/database.js', 'src/storage.js', 'src/cache.js', 'src/auth.js', 'src/admin.js', 'src/site.js');
        foreach ($required as $file) {
            if (!file_exists($worker_dir . '/' . $file)) {
                $this->log("缺少必要文件: {$file}", 'ERROR');
                return false;
            }
        }

        if (function_exists('exec')) {
            $command = 'cd ' . escapeshellarg($worker_dir) . ' && npx wrangler deploy 2>&1';
            $output = array();
            $return_code = 0;
            exec($command, $output, $return_code);
            if ($return_code === 0) {
                $this->log('Worker 部署成功', 'INFO');
                return true;
            } else {
                $this->log('Worker 部署失败: ' . implode("\n", $output), 'ERROR');
                return false;
            }
        }

        $this->log('exec 函数不可用，请手动部署: cd worker && npx wrangler deploy', 'WARNING');
        return false;
    }

    // ============================================
    // 各种操作处理器
    // ============================================
    private function increment_exported_count() {
        $count = (int) get_option('cf_exported_count', 0);
        update_option('cf_exported_count', $count + 1);
    }

    public function handle_clear_cache() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cf_clear_cache');
        update_option('cf_exported_count', 0);
        delete_option('cf_last_export_time');
        global $wpdb;
        $wpdb->delete($wpdb->postmeta, array('meta_key' => '_cf_exported'));
        $this->log('手动清除缓存完成', 'INFO');
        wp_redirect(admin_url('options-general.php?page=cf-static-export-pro&cache_cleared=1'));
        exit;
    }

    public function get_stats_ajax() {
        check_ajax_referer('cfse_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('权限不足');
        wp_send_json_success($this->get_export_stats());
    }

    public function deactivate() {
        wp_clear_scheduled_hook('cf_auto_export_cron');
        $this->log('插件已停用', 'INFO');
    }

    public function handle_auto_export() {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $posts = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'meta_query' => array(
            'relation' => 'AND',
            array('key' => '_cf_exported', 'compare' => 'NOT EXISTS'),
            array('key' => '_cfse_synced_d1', 'compare' => 'NOT EXISTS'),
        ), 'posts_per_page' => $this->config['batch_size']));
        foreach ($posts as $post) {
            $this->export_post($post->ID);   // 标记由 export_post() 按开关决定（已导出 / 已同步 D1）
        }
        $this->log("自动导出完成，处理了 " . count($posts) . " 篇文章", 'INFO');
    }

    public function add_export_column($columns) { $columns['cf_exported'] = '☁️ 导出状态'; return $columns; }
    public function render_export_column($column, $post_id) {
        if ($column !== 'cf_exported') return;
        if (get_post_meta($post_id, '_cf_exported', true)) {
            echo '<span title="已生成静态单页（本地或 R2）">✅ 已导出</span>';
        } elseif (get_post_meta($post_id, '_cfse_synced_d1', true)) {
            echo '<span title="只把数据同步进了 D1（未生成静态单页）；单页由 Worker 按需渲染">☁️ 已同步 D1</span>';
        } else {
            echo '<span title="还没有同步/导出">⏳ 待导出</span>';
        }
    }

    public function add_manual_export_button() {
        global $post;
        if (!$post || !isset($post->post_status) || $post->post_status !== 'publish' || !current_user_can('manage_options')) return;
        echo '<div class="misc-pub-section"><a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=cf_manual_export&post_id=' . $post->ID), 'cf_manual_export')) . '" class="button button-secondary">☁️ 重新导出到Cloudflare</a></div>';
    }


    /** 收集全部已发布文章的 ID（分批 200，避免大站一次性载入） */
    private function collect_published_ids() {
        $all = array();
        $offset = 0;
        $chunk = 200;
        do {
            $ids = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $chunk, 'offset' => $offset, 'fields' => 'ids'));
            if (empty($ids)) break;
            $all = array_merge($all, $ids);
            $offset += $chunk;
        } while (count($ids) === $chunk);
        return $all;
    }

    // ============================================
    // Worker 单篇对齐（编辑页「☁️ Worker 同步」面板）
    // ============================================
    /** 拉取 Worker 单篇（/sync/posts?wp_id=），成功返回该篇数组，否则 null */
    private function fetch_worker_post($wp_id) {
        $site = isset($this->config['site_url']) ? rtrim($this->config['site_url'], '/') : '';
        $key = isset($this->config['auth_key']) ? $this->config['auth_key'] : '';
        if ($site === '' || $key === '') return null;
        $resp = wp_remote_get($site . '/sync/posts?wp_id=' . (int) $wp_id, array(
            'timeout' => 30,
            'headers' => array('X-Auth-Key' => $key),
        ));
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return null;
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($data) || empty($data['posts'][0])) return null;
        return $data['posts'][0];
    }

    /**
     * 用 Worker 版本覆盖 WP 现有篇（对齐）。不触发回写导出（内容本就来自 Worker）。
     * 返回 true/false。注意 cfse_skip_export 用「保存-恢复」写法，避免与拉取的短路互相干扰。
     */
    private function overwrite_post_from_remote($post_id, $remote) {
        $fmt = (isset($remote['content_format']) && $remote['content_format'] === 'md') ? 'md' : 'html';
        $args = array(
            'ID' => (int) $post_id,
            'post_title' => isset($remote['title']) ? (string) $remote['title'] : '',
            'post_content' => isset($remote['content']) ? (string) $remote['content'] : '',
        );
        if (!empty($remote['slug'])) $args['post_name'] = (string) $remote['slug'];
        $prev_skip = isset($GLOBALS['cfse_skip_export']) ? $GLOBALS['cfse_skip_export'] : null;
        $GLOBALS['cfse_skip_export'] = true;
        $res = wp_update_post($args, true);
        if (is_wp_error($res)) {
            if ($prev_skip === null) unset($GLOBALS['cfse_skip_export']); else $GLOBALS['cfse_skip_export'] = $prev_skip;
            $this->log('对齐失败 wp_id=' . (int) $post_id . ': ' . $res->get_error_message(), 'ERROR');
            return false;
        }
        update_post_meta($post_id, 'content_format', $fmt);
        if (!empty($remote['wp_id'])) update_post_meta($post_id, '_cfse_remote_wp_id', (int) $remote['wp_id']);
        // wp_update_post() 总是把 post_modified 设成当前时间（参数会被忽略），这里按远端值直接修正：
        // 两端时间一致，「检查」才会显示「✔ 两端一致」而不是一直「本地较新」
        if (!empty($remote['modified'])) {
            global $wpdb;
            $mod = (string) $remote['modified'];
            $wpdb->update(
                $wpdb->posts,
                array('post_modified' => $mod, 'post_modified_gmt' => get_gmt_from_date($mod)),
                array('ID' => (int) $post_id)
            );
            clean_post_cache((int) $post_id);
        }
        $this->apply_worker_terms($post_id, isset($remote['categories']) ? $remote['categories'] : '', 'category');
        $this->apply_worker_terms($post_id, isset($remote['tags']) ? $remote['tags'] : '', 'post_tag');
        if ($prev_skip === null) unset($GLOBALS['cfse_skip_export']); else $GLOBALS['cfse_skip_export'] = $prev_skip;
        return true;
    }

    /** 「↻ 从 Worker 对齐本篇」 */
    public function handle_align_worker() {
        // 面板里的「对齐本篇」是链接（GET），所以读 $_REQUEST
        $post_id = isset($_REQUEST['post_id']) ? (int) $_REQUEST['post_id'] : 0;
        $this->log("收到「对齐本篇」请求：post_id={$post_id}", 'INFO');
        if (!$post_id || !current_user_can('edit_post', $post_id)) wp_die('权限不足');
        check_admin_referer('cfse_align_worker_' . $post_id);
        $post = get_post($post_id);
        if (!$post) {
            $this->log("对齐失败：WP 侧文章不存在 post_id={$post_id}", 'ERROR');
            wp_safe_redirect(add_query_arg('cfse_aligned', '0', admin_url('edit.php')));
            exit;
        }
        $remote_id = $this->get_remote_wp_id($post_id);
        $remote = $remote_id ? $this->fetch_worker_post($remote_id) : null;
        $ok = false;
        if ($remote) {
            $ok = $this->overwrite_post_from_remote($post_id, $remote);
        } else {
            $this->log("对齐失败：未能从 Worker 取到远端 wp_id={$remote_id}（WP post_id={$post_id}）", 'ERROR');
        }
        $this->log("从 Worker 对齐 wp_id={$post_id}（远端 {$remote_id}）：" . ($ok ? '成功' : '失败'), $ok ? 'INFO' : 'ERROR');
        // 跳回当前文章；拿不到编辑链接（如已删除）才退回列表页
        $back = get_edit_post_link($post_id, 'raw');
        if (!$back) $back = admin_url('edit.php');
        wp_safe_redirect(add_query_arg('cfse_aligned', $ok ? '1' : '0', $back));
        exit;
    }

    /** AJAX：查询该篇在 Worker 端与本地的版本时间（编辑页异步调用，不阻塞页面渲染） */
    /** AJAX：测试各托管目标的连通性（用已保存的配置） */
    public function ajax_test_targets() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('error' => '无权限'), 403);
        check_ajax_referer('cfse_test_targets');
        $out = array();
        foreach ($this->get_hosting_targets() as $t) {
            $name = (isset($t['name']) && $t['name'] !== '') ? $t['name'] : (isset($t['id']) ? $t['id'] : '?');
            $type = isset($t['type']) ? $t['type'] : 'cfse-worker';
            if ($type === 'cfse-worker') {
                // 用受保护接口（/style-list）测：这样 401 能明确指向「X-Auth-Key 不对」——
                // /health 不需要认证，只能证明「能连通」，证明不了 Key。
                $base = rtrim(isset($t['endpoint']) ? (string) $t['endpoint'] : '', '/');
                if ($base === '') {
                    $out[] = array('name' => $name, 'ok' => false, 'code' => 'config', 'detail' => '未配置端点', 'http' => 0);
                    continue;
                }
                $resp = $this->worker_request($t, '/style-list', 'GET', null, 10);
                if ($resp === null) {
                    $out[] = array('name' => $name, 'ok' => false, 'code' => 'network', 'detail' => '网络不可达或连接失败（检查端点是否可以访问）', 'http' => 0);
                    continue;
                }
                $code = (int) wp_remote_retrieve_response_code($resp);
                if ($code === 200) {
                    $out[] = array('name' => $name, 'ok' => true, 'code' => 'ok', 'detail' => '连通且 X-Auth-Key 通过', 'http' => $code);
                } elseif ($code === 401 || $code === 403) {
                    $out[] = array('name' => $name, 'ok' => false, 'code' => 'auth', 'detail' => 'X-Auth-Key 不对（HTTP ' . $code . '）', 'http' => $code);
                } else {
                    $out[] = array('name' => $name, 'ok' => false, 'code' => 'http_' . $code, 'detail' => 'HTTP ' . $code, 'http' => $code);
                }
            } elseif ($type === 'local') {
                $dir = $this->target_dir($t);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    $out[] = array('name' => $name, 'ok' => false, 'code' => 'fs', 'detail' => '目录不存在且无法创建：' . $dir, 'http' => 0);
                    continue;
                }
                $w = is_writable($dir);
                $out[] = array('name' => $name, 'ok' => $w, 'code' => $w ? 'ok' : 'fs', 'detail' => $dir . ($w ? '（可写）' : '（不可写）'), 'http' => 0);
            } elseif ($type === 's3') {
                $r = $this->s3_test_connection($t);
                $out[] = array(
                    'name' => $name, 'ok' => $r['ok'], 'code' => $r['code'],
                    'detail' => $r['hint'] . ($r['http'] ? ('（HTTP ' . $r['http'] . '）') : '') . ($r['detail'] !== '' ? (' — ' . $r['detail']) : ''),
                    'http' => $r['http'],
                );
            } else {
                $out[] = array('name' => $name, 'ok' => false, 'code' => 'unimplemented', 'detail' => '类型 ' . $type . ' 尚未实现', 'http' => 0);
            }
        }
        wp_send_json_success(array('ok' => true, 'results' => $out));
    }

    public function ajax_worker_status() {
        check_ajax_referer('cfse_worker_status');
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) wp_send_json(array('ok' => false));
        $post = get_post($post_id);
        if (!$post) wp_send_json(array('ok' => false));
        $remote_id = $this->get_remote_wp_id($post_id);
        $remote = $remote_id ? $this->fetch_worker_post($remote_id) : null;
        if (!$remote) wp_send_json(array('ok' => false));
        $worker_mod = isset($remote['modified']) ? (string) $remote['modified'] : '';
        $local_mod = (string) $post->post_modified;
        $need = ($worker_mod !== '' && strtotime($worker_mod) > strtotime($local_mod));
        $local_newer = ($worker_mod !== '' && strtotime($local_mod) > strtotime($worker_mod));
        wp_send_json(array('ok' => true, 'remote_wp_id' => $remote_id, 'worker_modified' => $worker_mod, 'local_modified' => $local_mod, 'need_sync' => $need, 'local_newer' => $local_newer));
    }

    // ============================================
    // 从 Worker 拉取文章（WP 主动拉；Worker → WP 不可达，见 ROADMAP I）
    // ============================================
    /**
     * 拉取 Worker 端诞生的文章（wp_id >= CFSE_WORKER_ID_MIN）。
     * @param bool $overwrite true 时对 WP 中已存在同 ID 的篇用 Worker 版本覆盖（对齐），否则跳过（幂等保护）。
     * @return array [新增数, 更新数, 跳过数, 失败数, Worker 返回总数]
     */
    private function pull_posts_from_worker($overwrite = false) {
        $site = isset($this->config['site_url']) ? rtrim($this->config['site_url'], '/') : '';
        $key = isset($this->config['auth_key']) ? $this->config['auth_key'] : '';
        if ($site === '' || $key === '') {
            $this->log('拉取失败：静态站点 URL 或 Worker Auth Key 未配置', 'ERROR');
            return array(0, 0, 0, 1, 0);
        }
        $url = $site . '/sync/posts?limit=1000&min_wp_id=' . CFSE_WORKER_ID_MIN;
        $resp = wp_remote_get($url, array('timeout' => 60, 'headers' => array('X-Auth-Key' => $key)));
        if (is_wp_error($resp)) {
            $this->log('拉取失败：' . $resp->get_error_message(), 'ERROR');
            return array(0, 0, 0, 1, 0);
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            $this->log("拉取失败：HTTP {$code} " . substr((string) wp_remote_retrieve_body($resp), 0, 200), 'ERROR');
            return array(0, 0, 0, 1, 0);
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        $items = (is_array($data) && isset($data['posts']) && is_array($data['posts'])) ? $data['posts'] : array();
        $total = count($items);
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        $prev_skip = isset($GLOBALS['cfse_skip_export']) ? $GLOBALS['cfse_skip_export'] : null;
        $GLOBALS['cfse_skip_export'] = true; // 期间不回写 Worker
        foreach ($items as $p) {
            $wid = isset($p['wp_id']) ? (int) $p['wp_id'] : 0;
            if ($wid <= 0) { $errors++; continue; }
            $existing_id = $this->find_post_by_remote_id($wid);
            if ($existing_id) {
                if (!$overwrite) { $skipped++; continue; }
                if ($this->overwrite_post_from_remote($existing_id, $p)) { $updated++; } else { $errors++; }
                continue;
            }
            $fmt = (isset($p['content_format']) && $p['content_format'] === 'md') ? 'md' : 'html';
            // 刻意不用 import_id：那会把 wp_posts 的 AUTO_INCREMENT 顶到时间戳量级（见 AGENTS.md 批L）。
            // 远端 ID 记在 meta `_cfse_remote_wp_id`，导出/删除 D1 时用它 —— 两端数据依然对得上。
            $args = array(
                'post_title' => isset($p['title']) ? (string) $p['title'] : '',
                'post_content' => isset($p['content']) ? (string) $p['content'] : '',
                'post_status' => (isset($p['status']) && $p['status'] === 'published') ? 'publish' : 'draft',
                'post_type' => 'post',
                'post_author' => get_current_user_id() ? get_current_user_id() : 1,
                'meta_input' => array('content_format' => $fmt),
            );
            if (!empty($p['slug'])) $args['post_name'] = (string) $p['slug'];
            if (!empty($p['date'])) $args['post_date'] = (string) $p['date'];
            $new_id = wp_insert_post($args, true);
            if (is_wp_error($new_id)) {
                $errors++;
                $this->log("拉取入库失败 wp_id={$wid}: " . $new_id->get_error_message(), 'ERROR');
                continue;
            }
            update_post_meta($new_id, '_cfse_remote_wp_id', $wid); // 记录远端 wp_id（D1 主键）
            $this->apply_worker_terms($new_id, isset($p['categories']) ? $p['categories'] : '', 'category');
            $this->apply_worker_terms($new_id, isset($p['tags']) ? $p['tags'] : '', 'post_tag');
            $imported++;
        }
        if ($prev_skip === null) unset($GLOBALS['cfse_skip_export']); else $GLOBALS['cfse_skip_export'] = $prev_skip;
        return array($imported, $updated, $skipped, $errors, $total);
    }

    /**
     * 该篇在 Worker 端（D1）的 wp_id：有 meta `_cfse_remote_wp_id` 时返回它，否则 0（= WP 本地诞生）。
     * 为什么不拿「WP 文章 ID」当远端 ID：拉取时若用 import_id 对齐 ID，会把 wp_posts 的 AUTO_INCREMENT
     * 顶到时间戳量级，之后 WP 新建的文章 ID 也变成那样（会误判成「Worker 端诞生」，见 AGENTS.md 批L）。
     */
    private function get_remote_wp_id($post_id) {
        $v = (int) get_post_meta((int) $post_id, '_cfse_remote_wp_id', true);
        return $v > 0 ? $v : 0;
    }

    /** 按远端 wp_id 找 WP 侧文章 ID（0 = 未找到）—— 拉取幂等判断用 */
    private function find_post_by_remote_id($remote_wp_id) {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cfse_remote_wp_id' AND meta_value = %d LIMIT 1",
            (int) $remote_wp_id
        ));
        return $found ? (int) $found : 0;
    }

    /** 把 Worker 的 JSON 分类/标签（[{name,slug}] 或字符串数组）映射到 WP：按 slug/name 找，缺则创建 */
    private function apply_worker_terms($post_id, $json, $taxonomy) {
        $terms = json_decode((string) $json, true);
        if (!is_array($terms)) $terms = array();
        $ids = array();
        foreach ($terms as $t) {
            if (is_array($t)) {
                $slug = isset($t['slug']) ? (string) $t['slug'] : '';
                $name = isset($t['name']) ? (string) $t['name'] : $slug;
            } else {
                $slug = (string) $t;
                $name = (string) $t;
            }
            if ($slug === '' && $name === '') continue;
            $term = $slug !== '' ? get_term_by('slug', $slug, $taxonomy) : false;
            if (!$term && $name !== '') $term = get_term_by('name', $name, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $ids[] = (int) $term->term_id;
                continue;
            }
            $ins = wp_insert_term($name !== '' ? $name : $slug, $taxonomy, $slug !== '' ? array('slug' => $slug) : array());
            if (is_wp_error($ins)) continue;
            $ids[] = (int) $ins['term_id'];
        }
        if (!empty($ids)) wp_set_object_terms($post_id, $ids, $taxonomy, false);
    }

    /** 设置页「📥 从 Worker 拉取文章」 */
    public function handle_pull_worker() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cf_pull_worker');
        @set_time_limit(0);
        @ignore_user_abort(true);
        $overwrite = !empty($_POST['cfse_pull_overwrite']);
        list($imported, $updated, $skipped, $errors, $total) = $this->pull_posts_from_worker($overwrite);
        $this->log('从 Worker 拉取完成（overwrite=' . ($overwrite ? '1' : '0') . "）：共 {$total} 篇，新增 {$imported}，对齐更新 {$updated}，跳过 {$skipped}，失败 {$errors}", 'INFO');
        wp_redirect(admin_url('options-general.php?page=cf-static-export-pro&pulled=' . $imported . '&pull_updated=' . $updated . '&pull_skipped=' . $skipped . '&pull_total=' . $total));
        exit;
    }

    /** 设置页「📥 生成到本地」：只渲染落盘，不联网 */
    public function handle_generate_local() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cf_generate_local');
        @set_time_limit(0);
        @ignore_user_abort(true);
        $all = $this->collect_published_ids();
        $this->log('本地生成开始，共 ' . count($all) . ' 篇，并发 ' . $this->config['export_concurrency'], 'INFO');
        list($success, $total) = $this->export_posts_concurrent($all, $this->config['export_concurrency'], 'generate');
        $this->log("本地生成完成：成功 {$success} / {$total}（目录 " . CFSE_LOCAL_DIR . "）", 'INFO');
        wp_redirect(admin_url('options-general.php?page=cf-static-export-pro&local_generated=' . $success . '&total=' . $total));
        exit;
    }

    /** 设置页「🚀 上传到远端」：读本地产物 → 上传 R2 + 写 D1 + 清缓存 */
    public function handle_publish_remote() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cf_publish_remote');
        @set_time_limit(0);
        @ignore_user_abort(true);
        $this->ensure_d1_schema();
        $all = $this->collect_published_ids();
        $this->log('上传到远端开始，共 ' . count($all) . ' 篇，并发 ' . $this->config['export_concurrency'], 'INFO');
        list($success, $total) = $this->export_posts_concurrent($all, $this->config['export_concurrency'], 'publish');
        $this->log("上传到远端完成：成功 {$success} / {$total}", 'INFO');
        wp_redirect(admin_url('options-general.php?page=cf-static-export-pro&published=' . $success . '&total=' . $total));
        exit;
    }

    // ============================================
    // 整站目录发布（出口层）：把「任意静态产物目录」发到具备「静态文件」能力的目标
    // 说明：只调用现有 target 分派（for_each_target / target_upload），不改动它们。
    // 与「📦 内容发布」的区别：那是逐篇文章的生成/上传；这里是整个目录树。
    // ============================================

    /** 允许发布的目录白名单（realpath 后的前缀） */
    private function allowed_site_roots() {
        return array(CFSE_LOCAL_DIR, WP_CONTENT_DIR);
    }

    /** 禁止发布的目录：源码类目录与 wp-content 根（避免把 PHP 源码推到公开静态站） */
    private function is_forbidden_site_dir($real) {
        $wc = realpath(WP_CONTENT_DIR);
        if ($wc === false) return false;
        $wc = rtrim(str_replace('\\', '/', $wc), '/');
        if ($real === $wc) return true;  // wp-content 根：会连带 plugins/themes
        foreach (array('plugins', 'themes', 'mu-plugins') as $sub) {
            $f = $wc . '/' . $sub;
            if ($real === $f || strpos($real . '/', $f . '/') === 0) return true;
        }
        return false;
    }

    /** 解析并校验产物目录：必须在白名单内且不在禁止目录中（防任意目录读取与源码外泄） */
    private function resolve_site_dir($dir) {
        $dir = trim((string) $dir);
        if ($dir === '') return null;
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) return null;
        $real = rtrim(str_replace('\\', '/', $real), '/');
        if ($this->is_forbidden_site_dir($real)) return null;
        foreach ($this->allowed_site_roots() as $root) {
            $root_real = realpath($root);
            if ($root_real === false) continue;
            $root_real = rtrim(str_replace('\\', '/', $root_real), '/');
            if ($real === $root_real || strpos($real . '/', $root_real . '/') === 0) {
                return $real;
            }
        }
        return null;
    }

    /** 递归收集待发布文件（相对路径，正斜杠）；结果缓存 10 分钟（$reset 时重建） */
    private function site_file_list($real_dir, $reset = false) {
        $key = 'cfse_site_files_' . md5($real_dir);
        if ($reset) delete_transient($key);
        $cached = get_transient($key);
        if (is_array($cached)) return $cached;

        if (!class_exists('RecursiveIteratorIterator')) return array();
        $skip_names = array('.DS_Store', 'Thumbs.db');
        $files = array();
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($real_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $name = $f->getFilename();
            if (in_array($name, $skip_names, true)) continue;
            $abs = str_replace('\\', '/', $f->getPathname());
            $rel = ltrim(substr($abs, strlen($real_dir)), '/');
            if ($rel === '') continue;
            // 目录守卫文件（插件为保护目录写入的 index.php）不发布
            if ($name === 'index.php' && $f->getSize() < 200) {
                $head = (string) @file_get_contents($f->getPathname());
                if (strpos($head, 'Silence is golden') !== false) continue;
            }
            $files[] = $rel;
        }
        sort($files);
        set_transient($key, $files, 600);
        return $files;
    }

    /** 扩展名 → Content-Type（未知按二进制，避免把图片当 HTML 发出去） */
    private function mime_for_path($path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = array(
            'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
            'css'  => 'text/css; charset=utf-8',  'js'  => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8', 'xml' => 'application/xml; charset=utf-8',
            'xsl'  => 'application/xml; charset=utf-8',  'txt' => 'text/plain; charset=utf-8',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',  'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',  'webp' => 'image/webp', 'ico' => 'image/x-icon',
            'woff' => 'font/woff',  'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
            'eot'  => 'application/vnd.ms-fontobject',
            'pdf'  => 'application/pdf', 'zip' => 'application/zip',
            'mp4'  => 'video/mp4', 'mp3' => 'audio/mpeg',
        );
        return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
    }

    /**
     * 处理一段文件（offset/limit）并返回统计 —— 供后台 AJAX 分段调用（避免单次请求超时）。
     * public：便于 CLI 探针与后续复用。
     */
    public function publish_site_dir_chunk($dir, $offset, $limit = 200, $reset = false) {
        $real = $this->resolve_site_dir($dir);
        if ($real === null) {
            return array('error' => '目录不合法或不在允许范围内（仅允许本插件产物目录 / wp-content 下的目录）');
        }
        // 先确认有「静态文件」能力的目标，避免白跑
        $probe = $this->for_each_target('r2', function ($t) { return true; });
        if ((int) $probe['tried'] === 0) {
            return array('error' => '没有启用「静态文件」能力的托管目标，请先在「🎯 托管目标」里配置并勾选');
        }

        $offset = max(0, (int) $offset);
        $limit  = max(1, min(500, (int) $limit));
        $all    = $this->site_file_list($real, $reset);
        $total  = count($all);
        $slice  = array_slice($all, $offset, $limit);

        $ok = 0; $failed = 0; $bytes = 0; $errors = array();
        $max_bytes = 20 * 1024 * 1024;
        foreach ($slice as $rel) {
            $path = $real . '/' . $rel;
            if (!is_readable($path)) { $failed++; $errors[] = $rel . '（不可读）'; continue; }
            $size = (int) @filesize($path);
            if ($size > $max_bytes) { $failed++; $errors[] = $rel . '（超过 20MB，已跳过）'; continue; }
            $content = (string) @file_get_contents($path);
            if ($content === '' && $size > 0) { $failed++; $errors[] = $rel . '（读取失败）'; continue; }
            $mime = $this->mime_for_path($rel);
            $res = $this->for_each_target('r2', function ($t) use ($rel, $content, $mime) {
                // 部署型目标（github / netlify）不走逐文件上传 —— 由「📦 部署到 git 型托管」一次性提交
                if ($this->is_deploy_target($t)) return true;
                return $this->target_upload($t, $rel, $content, $mime, null);
            });
            if (!empty($res['ok'])) { $ok++; $bytes += strlen($content); }
            else { $failed++; $errors[] = $rel . '（上传失败）'; }
        }

        $next = $offset + count($slice);
        $this->log(
            "整站发布分段：{$real} offset={$offset} 本段=" . count($slice) . " 成功={$ok} 失败={$failed}",
            $failed > 0 ? 'WARNING' : 'INFO'
        );

        return array(
            'dir'         => $real,
            'total'       => $total,
            'processed'   => $next,
            'done'        => ($next >= $total),
            'next_offset' => $next,
            'ok'          => $ok,
            'failed'      => $failed,
            'bytes'       => $bytes,
            'errors'      => array_slice($errors, 0, 5),
        );
    }

    /** AJAX：整站发布（前端循环调用直到 done） */
    public function ajax_publish_site() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('error' => '无权限'), 403);
        check_ajax_referer('cfse_publish_site');
        $dir    = isset($_POST['dir']) ? wp_unslash($_POST['dir']) : '';
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit  = isset($_POST['limit']) ? intval($_POST['limit']) : 200;
        $reset  = !empty($_POST['reset']);
        $res = $this->publish_site_dir_chunk($dir, $offset, $limit, $reset);
        if (isset($res['error'])) wp_send_json_error($res);
        wp_send_json_success($res);
    }

    // ============================================
    // git 型托管（GitHub Pages）—— 部署型 transport
    // 与逐文件上传（worker/local/s3）不同：这里是「一次提交 = 一次部署」的站点级事务。
    // ============================================

    /** GitHub API 调用（返回结构化结果，便于 UI / 日志直接展示） */
    private function github_api($target, $method, $path, $body = null) {
        $repo = isset($target['repo']) ? trim((string) $target['repo'], '/ ') : '';
        $token = isset($target['token']) ? (string) $target['token'] : '';
        if ($repo === '' || strpos($repo, '/') === false) {
            return array('ok' => false, 'http' => 0, 'code' => 'config', 'hint' => 'repo 未填（应为 owner/name）', 'json' => null, 'detail' => '');
        }
        if ($token === '') {
            return array('ok' => false, 'http' => 0, 'code' => 'config', 'hint' => 'token 未填', 'json' => null, 'detail' => '');
        }
        $args = array(
            'method' => $method,
            'timeout' => 60,
            'sslverify' => false,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'CF-Static-Export-Pro',
                'Content-Type' => 'application/json',
            ),
        );
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $base = (isset($target['api_base']) && $target['api_base'] !== '') ? rtrim((string) $target['api_base'], '/') : 'https://api.github.com';
        $resp = wp_remote_request($base . $path, $args);
        if (is_wp_error($resp)) {
            return array('ok' => false, 'http' => 0, 'code' => 'network', 'hint' => '网络不可达：' . $resp->get_error_message(), 'json' => null, 'detail' => '');
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $text = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($text, true);
        $msg = (is_array($json) && isset($json['message'])) ? (string) $json['message'] : '';
        $hint = '';
        if ($code === 401) $hint = 'Token 无效或已过期';
        elseif ($code === 403) $hint = '权限不足或触发限速' . ($msg !== '' ? '：' . $msg : '');
        elseif ($code === 404) $hint = '仓库或分支不存在（检查 repo 名与 token 权限）';
        elseif ($code === 409) $hint = '冲突（ref 已变化，重试即可）';
        elseif ($code === 422) $hint = '请求被拒：' . $msg;
        elseif ($code >= 400) $hint = ($msg !== '' ? $msg : ('HTTP ' . $code));
        return array('ok' => ($code >= 200 && $code < 300), 'http' => $code, 'code' => ($hint === '' ? 'ok' : 'error'), 'hint' => $hint, 'json' => $json, 'detail' => $text);
    }

    /** GitHub blob sha（本地可算 ⇒ 增量对比零额外 API 成本） */
    private function github_blob_sha($content) {
        return sha1('blob ' . strlen($content) . "\0" . $content);
    }

    /** 部署一个目录到 GitHub Pages 分支（一次提交 = 一次部署） */
    private function github_deploy($target, $dir) {
        $real = $this->resolve_site_dir($dir);
        if ($real === null) return array('ok' => false, 'hint' => '目录不合法或不在允许范围内');

        $repo = isset($target['repo']) ? trim((string) $target['repo'], '/ ') : '';
        $branch = !empty($target['branch']) ? (string) $target['branch'] : 'gh-pages';
        $prefix = isset($target['path_prefix']) ? trim((string) $target['path_prefix'], '/ ') : '';

        $files = $this->site_file_list($real, true);
        if (empty($files)) return array('ok' => false, 'hint' => '目录中没有文件');
        $maxFiles = 500;
        if (count($files) > $maxFiles) {
            return array('ok' => false, 'hint' => '目录含 ' . count($files) . ' 个文件，超过当前上限 ' . $maxFiles . '（本批仅支持中小目录）');
        }

        // 1) 目标分支 ref；不存在则基于默认分支创建
        $ref = $this->github_api($target, 'GET', "/repos/{$repo}/git/ref/heads/{$branch}");
        if (!$ref['ok']) {
            if ($ref['http'] !== 404) return array('ok' => false, 'hint' => '取 ref 失败：' . $ref['hint']);
            $info = $this->github_api($target, 'GET', "/repos/{$repo}");
            if (!$info['ok']) return array('ok' => false, 'hint' => '取仓库信息失败：' . $info['hint']);
            $def = !empty($info['json']['default_branch']) ? $info['json']['default_branch'] : 'main';
            $baseRef = $this->github_api($target, 'GET', "/repos/{$repo}/git/ref/heads/{$def}");
            if (!$baseRef['ok']) return array('ok' => false, 'hint' => '取默认分支失败：' . $baseRef['hint']);
            $headSha = $baseRef['json']['object']['sha'];
            $mk = $this->github_api($target, 'POST', "/repos/{$repo}/git/refs", array('ref' => 'refs/heads/' . $branch, 'sha' => $headSha));
            if (!$mk['ok']) return array('ok' => false, 'hint' => '创建分支失败：' . $mk['hint']);
            $this->log("github 目标 {$target['id']}：新建分支 {$branch}（基于 {$def}）", 'INFO');
        } else {
            $headSha = $ref['json']['object']['sha'];
        }

        // 2) head commit → base tree（保留分支上现有文件）
        $commit = $this->github_api($target, 'GET', "/repos/{$repo}/git/commits/{$headSha}");
        if (!$commit['ok']) return array('ok' => false, 'hint' => '取 commit 失败：' . $commit['hint']);
        $baseTree = $commit['json']['tree']['sha'];

        // 3) 现有文件 sha 表（增量 + 清理判断）
        $tree = $this->github_api($target, 'GET', "/repos/{$repo}/git/trees/{$baseTree}?recursive=1");
        $existing = array();
        if ($tree['ok'] && !empty($tree['json']['tree'])) {
            foreach ($tree['json']['tree'] as $e) {
                if (isset($e['type']) && $e['type'] === 'blob') $existing[$e['path']] = $e['sha'];
            }
        }

        // 4) 本地文件 → 只上传变化的 blob
        $entries = array(); $wanted = array();
        $uploaded = 0; $unchanged = 0; $failed = 0;
        foreach ($files as $rel) {
            $abs = $real . '/' . $rel;
            $content = (string) @file_get_contents($abs);
            if ($content === '' && (int) @filesize($abs) > 0) { $failed++; continue; }
            // GitHub Pages 是文件系统：URL 会被解码后按「真实文件名」查找 ⇒ 路径必须解码成原文
            // （本地产物路径是 URL 形态，例如 dewplayer-%e6%9c%80%e6%bc%82.../index.html）
            $ghPath = rawurldecode(($prefix !== '' ? $prefix . '/' : '') . $rel);
            if ($ghPath === '' || strpos($ghPath, '..') !== false || strpos($ghPath, "\0") !== false) {
                $failed++;
                continue;
            }
            $wanted[$ghPath] = true;
            $sha = $this->github_blob_sha($content);
            if (isset($existing[$ghPath]) && $existing[$ghPath] === $sha) { $unchanged++; continue; }
            $blob = $this->github_api($target, 'POST', "/repos/{$repo}/git/blobs", array('content' => base64_encode($content), 'encoding' => 'base64'));
            if (!$blob['ok']) return array('ok' => false, 'hint' => '上传 blob 失败（' . $rel . '）：' . $blob['hint']);
            $entries[] = array('path' => $ghPath, 'mode' => '100644', 'type' => 'blob', 'sha' => $blob['json']['sha']);
            $uploaded++;
        }

        // 5) .nojekyll（Pages 必需；放分支根）
        $nj = "cfse\r\n";
        $njSha = $this->github_blob_sha($nj);
        if (!isset($wanted['.nojekyll']) && (!isset($existing['.nojekyll']) || $existing['.nojekyll'] !== $njSha)) {
            $b = $this->github_api($target, 'POST', "/repos/{$repo}/git/blobs", array('content' => base64_encode($nj), 'encoding' => 'base64'));
            if ($b['ok']) $entries[] = array('path' => '.nojekyll', 'mode' => '100644', 'type' => 'blob', 'sha' => $b['json']['sha']);
        }

        // 6) 删除：仅限 path_prefix 之下、且不在本次产物里的文件
        $deleted = 0;
        foreach ($existing as $p => $sha) {
            if ($prefix !== '' && strpos($p, $prefix . '/') !== 0) continue;
            if ($prefix === '' && $p === '.nojekyll') continue;
            if (isset($wanted[$p])) continue;
            $entries[] = array('path' => $p, 'mode' => '100644', 'type' => 'blob', 'sha' => null);
            $deleted++;
        }

        if (empty($entries)) {
            return array('ok' => true, 'repo' => $repo, 'branch' => $branch, 'uploaded' => 0, 'unchanged' => $unchanged, 'deleted' => 0, 'failed' => $failed, 'total' => count($files), 'commit' => $headSha, 'hint' => '内容无变化，无需提交');
        }

        // 7) tree（带 base_tree ⇒ 保留本次未涉及的文件）
        $newTree = $this->github_api($target, 'POST', "/repos/{$repo}/git/trees", array('base_tree' => $baseTree, 'tree' => $entries));
        if (!$newTree['ok']) return array('ok' => false, 'hint' => '建 tree 失败：' . $newTree['hint']);

        // 8) commit
        $newCommit = $this->github_api($target, 'POST', "/repos/{$repo}/git/commits", array(
            'message' => 'Deploy static site — ' . $uploaded . ' updated, ' . $deleted . ' deleted (' . gmdate('Y-m-d H:i:s') . ' UTC)',
            'tree' => $newTree['json']['sha'],
            'parents' => array($headSha),
        ));
        if (!$newCommit['ok']) return array('ok' => false, 'hint' => '建 commit 失败：' . $newCommit['hint']);

        // 9) 更新 ref
        $upd = $this->github_api($target, 'PATCH', "/repos/{$repo}/git/refs/heads/{$branch}", array('sha' => $newCommit['json']['sha'], 'force' => false));
        if (!$upd['ok']) return array('ok' => false, 'hint' => '更新 ref 失败：' . $upd['hint']);

        // 10) 启用 Pages（幂等：已启用时 POST 返回 409，改用 PUT）
        $pages = $this->github_api($target, 'POST', "/repos/{$repo}/pages", array('source' => array('branch' => $branch, 'path' => '/')));
        if (!$pages['ok'] && in_array($pages['http'], array(409, 422), true)) {
            $this->github_api($target, 'PUT', "/repos/{$repo}/pages", array('source' => array('branch' => $branch, 'path' => '/')));
        }

        return array(
            'ok' => true, 'repo' => $repo, 'branch' => $branch,
            'uploaded' => $uploaded, 'unchanged' => $unchanged, 'deleted' => $deleted, 'failed' => $failed,
            'total' => count($files), 'commit' => $newCommit['json']['sha'],
            'public_url' => $this->target_public_url($target, ''),
        );
    }

    /** 部署型 transport 分派 */
    private function target_deploy($target, $dir) {
        $type = $this->target_type($target);
        if ($type === 'github' || $type === 'git') return $this->github_deploy($target, $dir);
        if ($type === 'netlify') return $this->netlify_deploy($target, $dir);
        if ($type === 'vercel') return $this->vercel_deploy($target, $dir);
        if ($type === 'gitlab') return $this->gitlab_deploy($target, $dir);
        if ($type === 'ftp') return $this->ftp_deploy($target, $dir);
        if ($type === 'webdav') return $this->webdav_deploy($target, $dir);
        return array('ok' => false, 'hint' => '该类型尚不支持站点级部署：' . $type);
    }

    /** 部署型目标的公共前置：白名单校验 + 文件清单 + 规模上限 */
    private function deploy_prepare($target, $dir, $maxFiles = 500) {
        $real = $this->resolve_site_dir($dir);
        if ($real === null) return array('error' => '目录不合法或不在允许范围内');
        $files = $this->site_file_list($real, true);
        if (empty($files)) return array('error' => '目录中没有文件');
        if (count($files) > $maxFiles) {
            return array('error' => '目录含 ' . count($files) . ' 个文件，超过上限 ' . $maxFiles . '（本批仅支持中小目录）');
        }
        return array('real' => $real, 'files' => $files);
    }

    // ---------- Netlify（digest 部署）----------
    private function netlify_deploy($target, $dir) {
        $pre = $this->deploy_prepare($target, $dir);
        if (isset($pre['error'])) return array('ok' => false, 'hint' => $pre['error']);
        $site = isset($target['site']) ? trim((string) $target['site']) : '';
        $token = isset($target['token']) ? (string) $target['token'] : '';
        if ($site === '') return array('ok' => false, 'hint' => 'site 未填（Netlify Site ID 或站点名）');
        if ($token === '') return array('ok' => false, 'hint' => 'token 未填');

        // 1) 建 deploy（body = 路径 → sha1）
        $digest = array(); $raw = array();
        foreach ($pre['files'] as $rel) {
            $content = (string) @file_get_contents($pre['real'] . '/' . $rel);
            $key = '/' . $rel;
            $digest[$key] = sha1($content);
            $raw[$key] = $content;
        }
        $r = $this->netlify_api($target, 'POST', "/sites/{$site}/deploys", array('files' => $digest, 'draft' => false));
        if (!$r['ok']) return array('ok' => false, 'hint' => '创建 deploy 失败：' . $r['hint']);
        $deployId = isset($r['json']['id']) ? (string) $r['json']['id'] : '';
        if ($deployId === '') return array('ok' => false, 'hint' => 'deploy 响应缺少 id');
        $required = (isset($r['json']['required']) && is_array($r['json']['required'])) ? $r['json']['required'] : array();

        // 2) 只上传 required 里的文件（sha1 已存在的会被跳过）
        $uploaded = 0; $failed = 0;
        foreach ($required as $p) {
            $p = (string) $p;
            $lookup = (strpos($p, '/') === 0) ? $p : ('/' . $p);
            if (!isset($raw[$lookup])) { $failed++; continue; }
            $url = 'https://api.netlify.com/api/v1/deploys/' . rawurlencode($deployId) . '/files/' . ltrim($p, '/');
            $resp = wp_remote_request($url, array(
                'method' => 'PUT', 'timeout' => 60, 'sslverify' => false,
                'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/octet-stream'),
                'body' => $raw[$lookup],
            ));
            if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) >= 400) $failed++;
            else $uploaded++;
        }
        return array(
            'ok' => ($failed === 0), 'site' => $site, 'deploy_id' => $deployId,
            'total' => count($pre['files']), 'uploaded' => $uploaded, 'unchanged' => count($pre['files']) - count($required),
            'failed' => $failed, 'public_url' => $this->target_public_url($target, ''),
            'hint' => $failed === 0 ? ('deploy ' . substr($deployId, 0, 8) . ' 已提交') : ($failed . ' 个文件上传失败'),
        );
    }

    private function netlify_api($target, $method, $path, $body = null) {
        $token = isset($target['token']) ? (string) $target['token'] : '';
        $args = array(
            'method' => $method, 'timeout' => 60, 'sslverify' => false,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'User-Agent' => 'CF-Static-Export-Pro'),
        );
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $resp = wp_remote_request('https://api.netlify.com/api/v1' . $path, $args);
        if (is_wp_error($resp)) return array('ok' => false, 'http' => 0, 'hint' => '网络不可达：' . $resp->get_error_message(), 'json' => null);
        $code = (int) wp_remote_retrieve_response_code($resp);
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        $msg = (is_array($json) && isset($json['message'])) ? (string) $json['message'] : '';
        $hint = '';
        if ($code === 401) $hint = 'Token 无效';
        elseif ($code === 404) $hint = 'site 不存在（检查 Site ID / 站点名）';
        elseif ($code === 422) $hint = '请求被拒：' . $msg;
        elseif ($code === 429) $hint = '触发限速（Netlify 部署限速 3/分、100/天）';
        elseif ($code >= 400) $hint = ($msg !== '' ? $msg : ('HTTP ' . $code));
        return array('ok' => ($code >= 200 && $code < 300), 'http' => $code, 'hint' => $hint, 'json' => $json);
    }

    // ---------- Vercel（deployment + files）----------
    private function vercel_deploy($target, $dir) {
        $pre = $this->deploy_prepare($target, $dir);
        if (isset($pre['error'])) return array('ok' => false, 'hint' => $pre['error']);
        $project = isset($target['project']) ? trim((string) $target['project']) : '';
        $token = isset($target['token']) ? (string) $target['token'] : '';
        if ($project === '') return array('ok' => false, 'hint' => 'project 未填（Vercel 项目名）');
        if ($token === '') return array('ok' => false, 'hint' => 'token 未填');

        // 1) manifest
        $manifest = array(); $raw = array();
        foreach ($pre['files'] as $rel) {
            $content = (string) @file_get_contents($pre['real'] . '/' . $rel);
            $sha = sha1($content);
            $manifest[] = array('file' => $rel, 'sha' => $sha, 'size' => strlen($content));
            $raw[$sha] = $content;
        }
        $body = array('name' => $project, 'project' => $project, 'target' => 'production', 'files' => $manifest);
        $r = $this->vercel_api($target, 'POST', '/v13/deployments', $body);
        if (!$r['ok']) return array('ok' => false, 'hint' => '创建 deployment 失败：' . $r['hint']);
        $id = isset($r['json']['id']) ? (string) $r['json']['id'] : '';
        if ($id === '') return array('ok' => false, 'hint' => 'deployment 响应缺少 id');
        $missing = (isset($r['json']['missing']) && is_array($r['json']['missing'])) ? $r['json']['missing'] : array();

        // 2) 上传 missing（x-vercel-digest）
        $uploaded = 0; $failed = 0;
        foreach ($missing as $sha) {
            $sha = (string) $sha;
            if (!isset($raw[$sha])) { $failed++; continue; }
            $resp = wp_remote_request('https://api.vercel.com/v2/files', array(
                'method' => 'POST', 'timeout' => 60, 'sslverify' => false,
                'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/octet-stream', 'x-vercel-digest' => $sha, 'User-Agent' => 'CF-Static-Export-Pro'),
                'body' => $raw[$sha],
            ));
            $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
            if ($code === 405) {   // 少数实现只接受 PUT
                $resp = wp_remote_request('https://api.vercel.com/v2/files', array(
                    'method' => 'PUT', 'timeout' => 60, 'sslverify' => false,
                    'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/octet-stream', 'x-vercel-digest' => $sha, 'User-Agent' => 'CF-Static-Export-Pro'),
                    'body' => $raw[$sha],
                ));
                $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
            }
            if ($code >= 200 && $code < 300) $uploaded++; else $failed++;
        }

        // 3) 轮询（最多 ~40s，避免长时间阻塞）
        $state = '';
        for ($i = 0; $i < 10; $i++) {
            sleep(3);
            $g = $this->vercel_api($target, 'GET', '/v13/deployments/' . rawurlencode($id));
            if (!$g['ok']) break;
            $state = isset($g['json']['readyState']) ? (string) $g['json']['readyState'] : (isset($g['json']['state']) ? (string) $g['json']['state'] : '');
            if ($state === 'READY' || $state === 'ERROR' || $state === 'CANCELED') break;
        }
        $url = isset($r['json']['url']) ? ('https://' . ltrim((string) $r['json']['url'], '/')) : '';
        return array(
            'ok' => ($failed === 0), 'project' => $project, 'deployment' => $id, 'state' => $state,
            'total' => count($pre['files']), 'uploaded' => $uploaded, 'unchanged' => count($pre['files']) - count($missing),
            'failed' => $failed, 'url' => $url, 'public_url' => $url !== '' ? $url : $this->target_public_url($target, ''),
            'hint' => ($failed === 0 ? 'deployment 已提交' : ($failed . ' 个文件上传失败')) . ($state !== '' ? ('，state=' . $state) : ''),
        );
    }

    private function vercel_api($target, $method, $path, $body = null) {
        $token = isset($target['token']) ? (string) $target['token'] : '';
        $args = array(
            'method' => $method, 'timeout' => 60, 'sslverify' => false,
            'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'User-Agent' => 'CF-Static-Export-Pro'),
        );
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $resp = wp_remote_request('https://api.vercel.com' . $path, $args);
        if (is_wp_error($resp)) return array('ok' => false, 'http' => 0, 'hint' => '网络不可达：' . $resp->get_error_message(), 'json' => null);
        $code = (int) wp_remote_retrieve_response_code($resp);
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        $msg = (is_array($json) && isset($json['error']['message'])) ? (string) $json['error']['message'] : '';
        $hint = '';
        if ($code === 401) $hint = 'Token 无效';
        elseif ($code === 403) $hint = '无权限：' . $msg;
        elseif ($code === 404) $hint = '项目不存在（先在 Vercel 建好项目）';
        elseif ($code === 400) $hint = '请求无效：' . $msg;
        elseif ($code >= 400) $hint = ($msg !== '' ? $msg : ('HTTP ' . $code));
        return array('ok' => ($code >= 200 && $code < 300), 'http' => $code, 'hint' => $hint, 'json' => $json);
    }

    // ---------- GitLab（commits API + actions[]）----------
    private function gitlab_deploy($target, $dir) {
        $pre = $this->deploy_prepare($target, $dir);
        if (isset($pre['error'])) return array('ok' => false, 'hint' => $pre['error']);
        $projectRaw = isset($target['project']) ? trim((string) $target['project']) : '';
        $token = isset($target['token']) ? (string) $target['token'] : '';
        if ($projectRaw === '') return array('ok' => false, 'hint' => 'project 未填（group/project 或数字 ID）');
        if ($token === '') return array('ok' => false, 'hint' => 'token 未填');
        $project = rawurlencode($projectRaw);
        $branch = !empty($target['branch']) ? (string) $target['branch'] : 'gh-pages';
        // GitLab Pages 只发布 CI 产物 public/ ⇒ 默认把产物放在 public/ 下
        $prefix = isset($target['path_prefix']) ? trim((string) $target['path_prefix'], '/ ') : '';
        if ($prefix === '') $prefix = 'public';

        // 1) 默认分支（用于首次创建目标分支）
        $proj = $this->gitlab_api($target, 'GET', "/projects/{$project}");
        if (!$proj['ok']) return array('ok' => false, 'hint' => '取项目失败：' . $proj['hint']);
        $def = !empty($proj['json']['default_branch']) ? (string) $proj['json']['default_branch'] : 'main';
        $brInfo = $this->gitlab_api($target, 'GET', "/projects/{$project}/repository/branches/" . rawurlencode($branch));
        $branchExists = $brInfo['ok'];

        // 2) 现有文件（prefix 下，分页）
        $existing = array();
        for ($page = 1; $page <= 20; $page++) {
            $t = $this->gitlab_api($target, 'GET', "/projects/{$project}/repository/tree?recursive=true&per_page=100&page={$page}&path=" . rawurlencode($prefix));
            if (!$t['ok'] || !is_array($t['json'])) break;
            foreach ($t['json'] as $e) {
                if (isset($e['type'], $e['path']) && $e['type'] === 'blob') $existing[$e['path']] = true;
            }
            if (count($t['json']) < 100) break;
        }

        // 3) actions：新增/更新 + 删除
        $actions = array(); $wanted = array(); $uploaded = 0;
        foreach ($pre['files'] as $rel) {
            $path = rawurldecode($prefix . '/' . $rel);
            if ($path === '' || strpos($path, '..') !== false) continue;
            $wanted[$path] = true;
            $content = (string) @file_get_contents($pre['real'] . '/' . $rel);
            $isBinary = (strpos($content, "\0") !== false) || !preg_match('//u', $content);
            $a = array(
                'action' => isset($existing[$path]) ? 'update' : 'create',
                'file_path' => $path,
                'content' => $isBinary ? base64_encode($content) : $content,
            );
            if ($isBinary) $a['encoding'] = 'base64';
            $actions[] = $a;
            $uploaded++;
        }
        // Pages 需要 CI 产物 public/ ⇒ 带一个最小 .gitlab-ci.yml
        if (!isset($wanted['.gitlab-ci.yml'])) {
            $yml = "pages:\n  stage: deploy\n  script:\n    - echo 'static site'\n  artifacts:\n    paths:\n      - public\n";
            $actions[] = array('action' => isset($existing['.gitlab-ci.yml']) ? 'update' : 'create', 'file_path' => '.gitlab-ci.yml', 'content' => $yml);
            $wanted['.gitlab-ci.yml'] = true;
        }
        $deleted = 0;
        foreach ($existing as $p => $_) {
            if (isset($wanted[$p])) continue;
            $actions[] = array('action' => 'delete', 'file_path' => $p);
            $deleted++;
        }

        // 4) 一次提交
        $body = array(
            'branch' => $branch,
            'commit_message' => 'Deploy static site — ' . $uploaded . ' files (' . gmdate('Y-m-d H:i:s') . ' UTC)',
            'actions' => $actions,
        );
        if (!$branchExists) $body['start_branch'] = $def;
        $c = $this->gitlab_api($target, 'POST', "/projects/{$project}/repository/commits", $body);
        if (!$c['ok']) return array('ok' => false, 'hint' => '提交失败：' . $c['hint']);

        return array(
            'ok' => true, 'project' => $projectRaw, 'branch' => $branch, 'prefix' => $prefix,
            'total' => count($pre['files']), 'uploaded' => $uploaded, 'deleted' => $deleted,
            'commit' => isset($c['json']['id']) ? $c['json']['id'] : '',
            'public_url' => $this->target_public_url($target, ''),
            'hint' => '已提交 ' . $uploaded . ' 个文件' . ($deleted ? ('，删除 ' . $deleted . ' 个') : '') . '；Pages 由 CI（.gitlab-ci.yml）发布 public/',
        );
    }

    // ---------- FTP / FTPS（虚拟主机）----------
    private function ftp_deploy($target, $dir) {
        $pre = $this->deploy_prepare($target, $dir);
        if (isset($pre['error'])) return array('ok' => false, 'hint' => $pre['error']);
        if (!function_exists('ftp_connect')) {
            return array('ok' => false, 'hint' => 'PHP 缺少 ftp 扩展（请在 php.ini 启用 extension=ftp）');
        }
        $host = isset($target['host']) ? trim((string) $target['host']) : '';
        $port = !empty($target['port']) ? (int) $target['port'] : 21;
        if ($host === '') return array('ok' => false, 'hint' => 'host 未填');
        $useFtps = !empty($target['ftps']);
        $conn = $useFtps ? @ftp_ssl_connect($host, $port, 20) : @ftp_connect($host, $port, 20);
        if (!$conn) return array('ok' => false, 'hint' => ($useFtps ? 'FTPS' : 'FTP') . ' 连接失败：' . $host . ':' . $port . '（检查主机/端口/防火墙；FTPS 需服务器支持显式 TLS）');
        if (!@ftp_login($conn, isset($target['user']) ? (string) $target['user'] : '', isset($target['pass']) ? (string) $target['pass'] : '')) {
            @ftp_close($conn);
            return array('ok' => false, 'hint' => 'FTP 登录失败（用户名/密码）');
        }
        @ftp_pasv($conn, true);

        $root = isset($target['dir']) ? trim((string) $target['dir'], '/ ') : '';
        $prefix = isset($target['path_prefix']) ? trim((string) $target['path_prefix'], '/ ') : '';
        $base = trim($root . '/' . $prefix, '/');
        if ($base !== '') $this->ftp_mkdirs($conn, $base);

        $uploaded = 0; $failed = 0; $errors = array();
        foreach ($pre['files'] as $rel) {
            $relPath = rawurldecode($rel);              // FTP 是文件系统 ⇒ 用解码后的原文
            if ($relPath === '' || strpos($relPath, '..') !== false) { $failed++; continue; }
            $remote = '/' . trim($base . '/' . $relPath, '/');
            $sub = dirname($relPath);
            if ($sub !== '.' && $sub !== '') $this->ftp_mkdirs($conn, trim($base . '/' . $sub, '/'));
            if (@ftp_put($conn, $remote, $pre['real'] . '/' . $rel, FTP_BINARY)) {
                $uploaded++;
            } else {
                $failed++;
                if (count($errors) < 3) $errors[] = $relPath;
            }
        }
        @ftp_close($conn);
        return array(
            'ok' => ($failed === 0), 'host' => $host, 'port' => $port, 'ftps' => $useFtps,
            'total' => count($pre['files']), 'uploaded' => $uploaded, 'failed' => $failed, 'errors' => $errors,
            'public_url' => $this->target_public_url($target, ''),
            'hint' => ($failed === 0)
                ? ('已上传 ' . $uploaded . ' 个文件 —— 增量覆盖（未删除远端多余文件；如需严格同步请改用 github/gitlab/netlify/vercel）')
                : ('失败 ' . $failed . ' 个：' . implode('、', $errors)),
            'mode' => 'overlay',
        );
    }

    private function ftp_mkdirs($conn, $dir) {
        $dir = trim((string) $dir, '/');
        if ($dir === '') return;
        $cur = '';
        foreach (explode('/', $dir) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            $cur .= '/' . $seg;
            @ftp_mkdir($conn, $cur);          // 已存在会失败，忽略
        }
    }

    // ---------- WebDAV（坚果云 / Nextcloud 等）----------
    private function webdav_deploy($target, $dir) {
        $pre = $this->deploy_prepare($target, $dir);
        if (isset($pre['error'])) return array('ok' => false, 'hint' => $pre['error']);
        $base = isset($target['endpoint']) ? rtrim((string) $target['endpoint'], '/') : '';
        if ($base === '') return array('ok' => false, 'hint' => 'endpoint 未填（如 https://dav.jianguoyun.com/dav/站点目录）');
        $prefix = isset($target['path_prefix']) ? trim((string) $target['path_prefix'], '/ ') : '';
        if ($prefix !== '') $this->webdav_mkdirs($target, $base, $prefix);

        $uploaded = 0; $failed = 0; $errors = array();
        foreach ($pre['files'] as $rel) {
            $relPath = rawurldecode($rel);              // WebDAV 也是文件系统 ⇒ 用原文
            if ($relPath === '' || strpos($relPath, '..') !== false) { $failed++; continue; }
            $full = trim($prefix . '/' . $relPath, '/');
            $sub = dirname($full);
            if ($sub !== '.' && $sub !== '' && $sub !== '') $this->webdav_mkdirs($target, $base, $sub);
            $content = (string) @file_get_contents($pre['real'] . '/' . $rel);
            $r = $this->webdav_request($target, 'PUT', $base . '/' . $this->webdav_encode($full), $content, $this->mime_for_path($rel));
            if (!empty($r['ok'])) {
                $uploaded++;
            } else {
                $failed++;
                if (count($errors) < 3) $errors[] = $relPath . '（HTTP ' . $r['http'] . ($r['hint'] !== '' ? '：' . $r['hint'] : '') . '）';
            }
        }
        return array(
            'ok' => ($failed === 0), 'endpoint' => $base, 'total' => count($pre['files']),
            'uploaded' => $uploaded, 'failed' => $failed, 'errors' => $errors,
            'public_url' => $this->target_public_url($target, ''),
            'hint' => ($failed === 0)
                ? ('已上传 ' . $uploaded . ' 个文件 —— 增量覆盖（未删除远端多余文件；如需严格同步请改用 github/gitlab/netlify/vercel）')
                : ('失败 ' . $failed . ' 个：' . implode('、', $errors)),
            'mode' => 'overlay',
        );
    }

    /** 逐级 MKCOL（405/301 = 已存在，忽略） */
    private function webdav_mkdirs($target, $base, $dir) {
        $dir = trim((string) $dir, '/');
        if ($dir === '') return;
        $acc = '';
        foreach (explode('/', $dir) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            $acc .= ($acc === '' ? '' : '/') . $seg;
            $this->webdav_request($target, 'MKCOL', $base . '/' . $this->webdav_encode($acc) . '/', '', '');
        }
    }

    /** URL 路径逐段编码（保留 /） */
    private function webdav_encode($path) {
        return implode('/', array_map('rawurlencode', explode('/', (string) $path)));
    }

    private function webdav_request($target, $method, $url, $body, $contentType) {
        $user = isset($target['user']) ? (string) $target['user'] : '';
        $pass = isset($target['pass']) ? (string) $target['pass'] : '';
        $headers = array('User-Agent' => 'CF-Static-Export-Pro');
        if ($user !== '') $headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . $pass);
        if ($contentType !== '') $headers['Content-Type'] = $contentType;
        $args = array('method' => $method, 'timeout' => 60, 'sslverify' => false, 'headers' => $headers);
        if ($body !== '') $args['body'] = $body;
        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) return array('ok' => false, 'http' => 0, 'hint' => '网络不可达：' . $resp->get_error_message());
        $code = (int) wp_remote_retrieve_response_code($resp);
        $hint = '';
        if ($code === 401) $hint = '认证失败（用户名/密码；坚果云请用应用密码）';
        elseif ($code === 403) $hint = '无权限';
        elseif ($code === 404) $hint = '路径不存在（父目录创建失败？）';
        elseif ($code === 405) $hint = '已存在';
        elseif ($code === 409) $hint = '父目录不存在或冲突';
        elseif ($code === 507) $hint = '配额不足';
        elseif ($code >= 400) $hint = 'HTTP ' . $code;
        return array('ok' => ($code >= 200 && $code < 300), 'http' => $code, 'hint' => ($code === 405 || $code === 301) ? '' : $hint);
    }

    private function gitlab_api($target, $method, $path, $body = null) {
        $token = isset($target['token']) ? (string) $target['token'] : '';
        $base = (isset($target['api_base']) && $target['api_base'] !== '') ? rtrim((string) $target['api_base'], '/') : 'https://gitlab.com/api/v4';
        $args = array(
            'method' => $method, 'timeout' => 60, 'sslverify' => false,
            'headers' => array('PRIVATE-TOKEN' => $token, 'Content-Type' => 'application/json', 'User-Agent' => 'CF-Static-Export-Pro'),
        );
        if ($body !== null) $args['body'] = wp_json_encode($body);
        $resp = wp_remote_request($base . $path, $args);
        if (is_wp_error($resp)) return array('ok' => false, 'http' => 0, 'hint' => '网络不可达：' . $resp->get_error_message(), 'json' => null);
        $code = (int) wp_remote_retrieve_response_code($resp);
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        $msg = '';
        if (is_array($json)) {
            if (isset($json['message'])) $msg = is_array($json['message']) ? implode('; ', $json['message']) : (string) $json['message'];
            elseif (isset($json['error'])) $msg = (string) $json['error'];
        }
        $hint = '';
        if ($code === 401) $hint = 'Token 无效';
        elseif ($code === 403) $hint = '无权限（需 api 权限）';
        elseif ($code === 404) $hint = '项目/分支不存在（检查 project 与权限）';
        elseif ($code === 400) $hint = '请求被拒：' . $msg;
        elseif ($code >= 400) $hint = ($msg !== '' ? $msg : ('HTTP ' . $code));
        return array('ok' => ($code >= 200 && $code < 300), 'http' => $code, 'hint' => $hint, 'json' => $json);
    }

    /** 目标是否属于「部署型」（站点级事务，不参与逐文件上传） */
    private function is_deploy_target($target) {
        return in_array($this->target_type($target), array('github', 'git', 'netlify', 'vercel', 'gitlab', 'ftp', 'webdav'), true);
    }

    /** 把目录部署到所有「部署型」目标 */
    private function deploy_site_to_deploy_targets($dir) {
        $results = array(); $okCount = 0;
        foreach ($this->get_hosting_targets() as $t) {
            if (!$this->is_deploy_target($t)) continue;
            $r = $this->target_deploy($t, $dir);
            $results[$t['id']] = $r;
            if (!empty($r['ok'])) $okCount++;
            $this->log('部署到 ' . $t['id'] . '（' . $this->target_type($t) . '）：' . (!empty($r['ok']) ? '成功' : ('失败 - ' . (isset($r['hint']) ? $r['hint'] : '?'))), !empty($r['ok']) ? 'INFO' : 'ERROR');
        }
        return array('total' => count($results), 'ok_count' => $okCount, 'results' => $results);
    }

    /** 设置页「📦 部署到 git 型托管」 */
    public function handle_deploy_git() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cfse_deploy_git');
        @set_time_limit(0);
        @ignore_user_abort(true);
        $dir = isset($_POST['cfse_git_dir']) ? wp_unslash($_POST['cfse_git_dir']) : '';
        $res = $this->deploy_site_to_deploy_targets($dir);
        $detail = '';
        foreach ($res['results'] as $id => $r) {
            $detail .= $id . ':' . (!empty($r['ok'])
                ? ('OK(' . (isset($r['uploaded']) ? $r['uploaded'] : 0) . ' 更新/' . (isset($r['deleted']) ? $r['deleted'] : 0) . ' 删除)')
                : ('FAIL ' . (isset($r['hint']) ? $r['hint'] : ''))) . '；';
        }
        if ($detail === '') $detail = '没有启用中的部署型目标（github / netlify）';
        wp_redirect(admin_url('options-general.php?page=cf-static-export-pro&git_ok=' . (int) $res['ok_count'] . '&git_total=' . (int) $res['total'] . '&git_detail=' . rawurlencode($detail)));
        exit;
    }

    public function handle_manual_export() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cf_manual_export');
        $post_id = intval($_GET['post_id']);
        if ($post_id > 0) $this->export_post($post_id);
        wp_redirect(admin_url('post.php?post=' . $post_id . '&action=edit&cf_exported=1'));
        exit;
    }

    public function handle_bulk_export() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cf_bulk_export');
        @set_time_limit(0);
        @ignore_user_abort(true);
        // 先确保 D1 表结构为 v2（旧结构自动重建），随后全量重导会重写结构化 categories/tags
        $this->ensure_d1_schema();
        $all = $this->collect_published_ids();

        $this->log('全量导出开始，共 ' . count($all) . ' 篇，并发 ' . $this->config['export_concurrency'] . '，模式 auto', 'INFO');
        list($success, $total) = $this->export_posts_concurrent($all, $this->config['export_concurrency'], 'auto');
        $this->log("全量导出完成：成功 {$success} / {$total}", 'INFO');
        update_option('cf_exported_count', $success);
        wp_redirect(admin_url('options-general.php?page=cf-static-export-pro&exported=' . $success . '&total=' . $total));
        exit;
    }

    /**
     * 并发批量导出：按 workers 分片，每片一个 PHP CLI 子进程后台执行；
     * exec 不可用、worker 脚本缺失或只有 1 篇时自动降级为串行。
     *
     * @param array $post_ids 文章 ID 列表
     * @param int   $workers  并发进程数（1~8）
     * @return array [成功数, 总数]
     */
    private function export_posts_concurrent($post_ids, $workers = 4, $mode = 'auto') {
        $post_ids = array_values(array_unique(array_map('intval', (array) $post_ids)));
        $post_ids = array_values(array_filter($post_ids, function ($id) { return $id > 0; }));
        $total = count($post_ids);
        if ($total === 0) return array(0, 0);

        $workers = max(1, min((int) $workers, 8));
        $script = CFSE_PLUGIN_DIR . 'tools/bulk-export-worker.php';
        if (!function_exists('exec') || $total <= 1 || !file_exists($script)) {
            $ok = 0;
            foreach ($post_ids as $id) { if ($this->run_export_mode($id, $mode)) $ok++; }
            return array($ok, $total);
        }

        // 传给 CLI 子进程的原站地址：CLI 里没有 HTTP 上下文，若 wp-config.php 用
        // $_SERVER['HTTP_HOST'] 计算 WP_HOME，home_url()/get_permalink() 会退化成
        // http://localhost/，导致前台抓取连接失败、HTML 里 URL 替换失效。
        $worker_site = home_url('/');
/**
 * 找 CLI php 解释器。
 * fpm 环境下 PHP_BINARY 是 /usr/local/sbin/php-fpm（不能执行脚本），
 * 直接拿它 spawn 子进程会全部失败（表现为「内部渲染全部失败」+ 回退，进而 nginx 504）。
 */
if (!function_exists('cfse_cli_php')) {
    function cfse_cli_php() {
        static $cached = null;
        if ($cached !== null) return $cached;
        $cands = array();
        $env = trim((string) @shell_exec('command -v php 2>/dev/null'));
        if ($env !== '') $cands[] = $env;
        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') $cands[] = rtrim(PHP_BINDIR, '/') . '/php';
        $cands[] = '/usr/local/bin/php';
        $cands[] = '/usr/bin/php';
        foreach ($cands as $c) {
            if ($c !== '' && @is_executable($c)) { $cached = $c; return $cached; }
        }
        $cached = PHP_BINARY;
        return $cached;
    }
}

        $chunks = array_chunk($post_ids, (int) ceil($total / $workers));
        $tmp_dir = sys_get_temp_dir();
        $pid = getmypid();
        $out_files = array();
        $done_files = array();
        foreach ($chunks as $i => $ids_chunk) {
            $out_files[$i] = $tmp_dir . '/cfse_bulk_' . $pid . '_' . $i . '.out';
            $done_files[$i] = $out_files[$i] . '.done';
            @unlink($out_files[$i]);
            @unlink($done_files[$i]);
            $cmd = escapeshellarg(cfse_cli_php())
                . ' ' . escapeshellarg($script)
                . ' ' . escapeshellarg('--site=' . $worker_site)
                . ' ' . escapeshellarg('--mode=' . $mode)
                . ' ' . implode(',', $ids_chunk)
                . ' > ' . escapeshellarg($out_files[$i]) . ' 2>&1; echo done > ' . escapeshellarg($done_files[$i]);
            @exec($cmd . ' > /dev/null 2>&1 &');
        }

        // 轮询等待子进程（每 worker 上限：分到的篇数×25s + 120s 启动余量）
        $per_worker = (int) ceil($total / count($chunks));
        $deadline = time() + ($per_worker * 25) + 120;
        $started = time();
        while (time() < $deadline) {
            $remaining = count($chunks);
            foreach ($done_files as $df) {
                if (file_exists($df)) $remaining--;
            }
            if ($remaining <= 0) break;
            usleep(1500000); // 1.5s
        }

        $ok = 0;
        $finished = 0;
        foreach ($out_files as $i => $out) {
            if (file_exists($done_files[$i])) {
                $finished++;
                $text = file_exists($out) ? (string) file_get_contents($out) : '';
                if (preg_match('/OK:(\d+)/', $text, $m)) {
                    $ok += (int) $m[1];
                }
            }
            @unlink($out);
            @unlink($done_files[$i]);
        }

        if ($finished < count($chunks) || $ok === 0) {
            // 子进程异常/超时：回退串行兜底，保证导出不丢
            $this->log("并发导出未完整完成（完成 {$finished}/" . count($chunks) . " 片，成功 {$ok}），回退串行补全", 'WARNING');
            foreach ($post_ids as $id) {
                if ($this->run_export_mode($id, $mode)) $ok++;
            }
            $this->log('并发回退串行完成', 'INFO');
        } else {
            $this->log('并发导出完成：' . count($chunks) . " 片进程，成功 {$ok}，耗时 " . (time() - $started) . 's', 'INFO');
        }
        return array($ok, $total);
    }

    /**
     * 设置页「👤 Worker 后台账号」：把管理员写入 D1 users（PBKDF2-SHA256 密码哈希）
     */
    public function handle_seed_admin() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cf_seed_admin');
        $login = isset($_POST['cf_admin_username']) ? sanitize_user(wp_unslash($_POST['cf_admin_username']), true) : '';
        $pass = isset($_POST['cf_admin_password']) ? (string) wp_unslash($_POST['cf_admin_password']) : '';
        $email = isset($_POST['cf_admin_email']) ? sanitize_email(wp_unslash($_POST['cf_admin_email'])) : '';
        if ($login === '' || strlen($pass) < 8) {
            wp_redirect(add_query_arg(array('seeded' => '0'), admin_url('options-general.php?page=cf-static-export-pro')));
            exit;
        }
        $this->ensure_d1_schema();
        $iter = 100000;
        $salt = random_bytes(16);
        // hash_pbkdf2 的 length 参数为返回 hex 长度：传 64 得到 32 字节派生（64 hex），与 Worker 端 256bit 派生一致
        $hash = hash_pbkdf2('sha256', $pass, $salt, $iter, 64);
        $store = 'pbkdf2_sha256$' . $iter . '$' . bin2hex($salt) . '$' . $hash;
        $display = $login;
        $existing = get_user_by('login', $login);
        if ($existing) $display = $existing->display_name ? $existing->display_name : $existing->user_login;
        $this->d1_batch(array(array(
            'sql' => "INSERT INTO users (user_login, user_pass, user_nicename, user_email, display_name, role, user_status)
                      VALUES (?, ?, ?, ?, ?, 'admin', 1)
                      ON CONFLICT(user_login) DO UPDATE SET user_pass = excluded.user_pass, user_email = excluded.user_email, display_name = excluded.display_name, updated_at = CURRENT_TIMESTAMP",
            'params' => array($login, $store, $login, $email, $display),
        )));
        $this->log('Worker 后台用户已写入: ' . $login, 'INFO');
        wp_redirect(add_query_arg(array('seeded' => '1'), admin_url('options-general.php?page=cf-static-export-pro')));
        exit;
    }

    public function test_connection() {
        check_ajax_referer('cfse_ajax_nonce', 'nonce');
        $account_id = sanitize_text_field($_POST['account_id']);
        $api_token = sanitize_text_field($_POST['api_token']);
        if (empty($account_id) || empty($api_token)) wp_send_json_error('请填写Account ID和API Token');
        if (!extension_loaded('curl')) wp_send_json_error('服务器未安装cURL扩展');
        $ch = curl_init("https://api.cloudflare.com/client/v4/accounts/{$account_id}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $api_token, 'Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code === 200) wp_send_json_success('连接成功！');
        else wp_send_json_error("连接失败（HTTP {$http_code}）");
    }
}

// ============================================
// 初始化
// ============================================
CF_Static_Export_Pro::get_instance();