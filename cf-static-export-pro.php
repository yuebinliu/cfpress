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

// 拆分出的模块（详见 docs/roadmap-current.md Step 1）
require_once CFSE_PLUGIN_DIR . 'includes/class-transports.php';
require_once CFSE_PLUGIN_DIR . 'includes/class-admin-ui.php';
require_once CFSE_PLUGIN_DIR . 'includes/class-worker-cms.php';

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
    /** 文章所属站点的 meta 键（可见字段，便于后台「自定义字段」手动改；多值 = 多个站点） */
    const META_SITE = 'cfse_site';
    /** 托管目标「⚙️ 配置」弹窗的 HTML（渲染表格行时收集，表格之后统一输出为 modal） */
    private $target_modals = array();
    private $reported_dir_failure = false;

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
    private function ensure_worker_files() {
        return CFSE_Worker_CMS::get_instance()->ensure_worker_files();
    }
    private function generate_initial_file($name) {
        return CFSE_Worker_CMS::get_instance()->generate_initial_file($name);
    }

    // ============================================
    // 钩子初始化
    // ============================================
	private function init_hooks() {
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('save_post', array($this, 'export_post'), 10, 3);
		add_action('add_meta_boxes', array($this, 'add_doc_format_metabox'), 10, 2);
		add_action('add_meta_boxes', array($this, 'add_site_metabox'), 10, 2);
		add_action('admin_post_cfse_align_worker', array($this, 'handle_align_worker'));
		add_action('wp_ajax_cfse_worker_status', array($this, 'ajax_worker_status'));
		add_action('wp_ajax_cfse_test_targets', array($this, 'ajax_test_targets'));
		add_action('wp_ajax_cfse_publish_site', array($this, 'ajax_publish_site'));
		add_action('admin_post_cfse_deploy_git', array($this, 'handle_deploy_git'));
		// 优先级 5：必须早于导出钩子（save_post 优先级 10），否则本次导出读到的是旧标记
		add_action('save_post', array($this, 'save_doc_format_meta'), 5, 3);
		add_action('save_post', array($this, 'save_site_meta'), 5, 3);
		// 文章列表批量设置站点（每个站点一项，避免复杂弹窗）
		add_filter('bulk_actions-edit-post', array($this, 'add_site_bulk_actions'));
		add_filter('handle_bulk_actions-edit-post', array($this, 'handle_site_bulk_actions'), 10, 3);
		add_filter('the_content', array($this, 'render_md_content'), 9);
		add_action('before_delete_post', array($this, 'delete_post'));
		add_action('trash_post', array($this, 'delete_post'));
		add_action('untrash_post', array($this, 'export_post'));
		// 评论 → D1 单向同步（B1；总开关 cf_comments_sync，默认关）
		add_action('wp_insert_comment', array($this, 'on_comment_changed'), 10, 2);
		add_action('edit_comment', array($this, 'on_comment_changed'), 10, 1);
		add_action('transition_comment_status', array($this, 'on_comment_status_changed'), 10, 3);
		add_action('delete_comment', array($this, 'on_comment_deleting'), 10, 2);
		add_action('deleted_comment', array($this, 'on_comment_deleted'), 10, 1);
		add_action('admin_post_cfse_sync_comments', array($this, 'handle_sync_comments'));
		add_action('admin_post_cfse_pull_comments', array($this, 'handle_pull_comments'));
		add_action('post_submitbox_misc_actions', array($this, 'add_manual_export_button'));
		add_action('admin_post_cf_manual_export', array($this, 'handle_manual_export'));
		add_action('admin_post_cf_sync_site_info', array($this, 'handle_sync_site_info'));
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
            // 缓存策略（TTL / feed 条数 / 发布后清缓存）已全部归 CMS 后台维护
            // （D1 settings k='cache' 行）；WP 侧不再读写这些 option，也不再清 KV 缓存。
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
            // 「📤 导出文章」按钮的手动导出参数（与上面「发布文章时」的开关分离；默认全不勾）
            'manual_sync_d1' => (bool) get_option('cf_manual_sync_d1', false),
            'manual_gen_static' => (bool) get_option('cf_manual_gen_static', false),
            'manual_pub_remote' => (bool) get_option('cf_manual_pub_remote', false),
            // 评论：off=隐藏评论区 / embed=注入管理员填写的第三方片段 / native=主题原生骨架 + 自有数据（B 阶段）
            'comments_mode' => (string) get_option('cf_comments_mode', 'off'),
            'comments_embed' => (string) get_option('cf_comments_embed', ''),
        'banner_url' => (string) get_option('cf_banner_url', ''),
        'logo_url'   => (string) get_option('cf_logo_url', ''),
            'comments_sync' => (bool) get_option('cf_comments_sync', false),
            'comments_accept' => (string) get_option('cf_comments_accept', 'off'),
            'comments_pull_cron' => (bool) get_option('cf_comments_pull_cron', true),
        );
    }
    /**
     * 通用私有方法代理：供拆分出去的新类（CFSE_Admin_UI 等）回调主类 private 方法。
     * 拆分纪律要求「只搬不改」=> 不改原方法可见性，用反射转发。
     *
     * @param string $name 方法名
     * @param array  $args 参数
     * @return mixed
     */
    public function cfse_proxy($name, $args = array()) {
        if (!method_exists($this, $name)) {
            return null;
        }
        $r = new ReflectionMethod($this, $name);
        $r->setAccessible(true);
        return $r->invokeArgs($this, $args);
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
        // 评论回拉（B3）：定时把「Worker 端诞生、还没落地」的评论拉回来 —— 否则 WP 后台永远看不到读者评论
        add_action('cfse_pull_comments_cron', array($this, 'handle_pull_comments_cron'));
        if (!wp_next_scheduled('cfse_pull_comments_cron')) {
            wp_schedule_event(time() + 300, 'hourly', 'cfse_pull_comments_cron');
        }
    }
    public function sanitize_hosting_targets($value) {
        return CFSE_Transports::get_instance()->sanitize_hosting_targets($value);
    }
    public function render_hosting_targets_table() {
        return CFSE_Admin_UI::get_instance()->render_hosting_targets_table();
    }
    private function is_first_cloudflare_target($t) {
        return CFSE_Admin_UI::get_instance()->is_first_cloudflare_target($t);
    }
    private function render_cf_platform_fields($render = true) {
        return CFSE_Admin_UI::get_instance()->render_cf_platform_fields($render);
    }
    private function render_hosting_target_row($i, $t, $warns = array()) {
        return CFSE_Admin_UI::get_instance()->render_hosting_target_row($i, $t, $warns);
    }
    public function add_admin_menu() {
        return CFSE_Admin_UI::get_instance()->add_admin_menu();
    }
    public function register_settings() {
        return CFSE_Admin_UI::get_instance()->register_settings();
    }

    /** 评论注入代码（第三方服务片段）：管理员填写 ⇒ 原样保存（不做 kses，否则 <script> 会被剥掉） */
    public function sanitize_embed_code($value) {
        $v = is_string($value) ? trim($value) : '';
        if (strlen($v) > 20000) { $v = substr($v, 0, 20000); }
        return $v;
    }
    public function enqueue_admin_assets() {
        return CFSE_Admin_UI::get_instance()->enqueue_admin_assets();
    }
    public function settings_page() {
        return CFSE_Admin_UI::get_instance()->settings_page();
    }
    private function get_export_stats() {
        return CFSE_Admin_UI::get_instance()->get_export_stats();
    }
    private function get_recent_logs($lines = 10) {
        return CFSE_Admin_UI::get_instance()->get_recent_logs($lines);
    }

    public function log($message, $level = 'INFO') {
        if (!$this->config['enable_logging']) return;
        $timestamp = current_time('Y-m-d H:i:s');
        @file_put_contents(CFSE_LOG_FILE, "[{$timestamp}] [{$level}] {$message}\n", FILE_APPEND | LOCK_EX);
    }
    private function get_post_storage_path($post) {
        return CFSE_Worker_CMS::get_instance()->get_post_storage_path($post);
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
    private function build_post_meta($post) {
        return CFSE_Worker_CMS::get_instance()->build_post_meta($post);
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
        // 该篇所属站点（meta cfse_site；空则默认站点；再空则空数组 ⇒ 不生成不发布）
        $artifact['sites'] = $this->get_post_sites($post->ID);
        return $artifact;
    }

    /**
     * 阶段 1b：把产物写到本地导出目录（纯静态托管/离线检查用；不上传、不写 D1）。
     * 目录结构与 R2 key 同构：WP_CONTENT_DIR/cfse-static/<storagePath>
     */
    private function write_artifact_local($artifact) {
        // 多站点：同一份 HTML（渲染一次）写到该篇所属的每个站点目录 CFSE_LOCAL_DIR/<site_dir>/…
        $sites = (isset($artifact['sites']) && is_array($artifact['sites'])) ? $artifact['sites'] : array();
        $pid = isset($artifact['post']) ? $artifact['post']->ID : '?';
        if (empty($sites)) {
            $this->log("文章 {$pid} 未指定站点且未设默认站点，跳过本地生成（给文章选站点，或在托管目标里标记「默认站点」）", 'WARNING');
            return array();
        }
        $written = array();
        foreach ($sites as $site) {
            $target = $this->site_local_root($site) . '/' . ltrim($artifact['path'], '/');
            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new Exception('本地目录创建失败: ' . $dir);
            }
            $guard = dirname($this->site_local_root($site)) . '/index.php';
            if (!file_exists($guard)) @file_put_contents($guard, "<?php // Silence is golden.\n");
            if (@file_put_contents($target, $artifact['html']) === false) {
                throw new Exception('本地写入失败: ' . $target);
            }
            $written[] = $target;
        }
        $this->log('本地生成：' . count($written) . ' 个站点 (' . strlen($artifact['html']) . ' 字节/份) → ' . implode(' | ', $written), 'INFO');
        return $written;
    }
    private function is_worker_owned_post($post) {
        return CFSE_Worker_CMS::get_instance()->is_worker_owned_post($post);
    }

    /**
     * 阶段 2：发布到远端（上传 R2 + 同步媒体 + 写 D1 + 清缓存）。
     *
     * @param array       $artifact    build_post_artifact()/build_post_meta() 的返回值（后者须自带 path）
     * @param string|null $html_source 非 null 时用它作为正文（「上传到远端」= 用本地已生成的 HTML）
     */
    private function publish_artifact($artifact, $html_source = null, $write_d1 = true, $only_ids = null) {
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
            $this->invalidate_md_single_page($post, $only_ids);
        } else {
            $uploaded = false;
            for ($i = 0; $i < $this->config['max_retries']; $i++) {
                if ($this->upload_to_r2_via_worker($r2_path, $html, $content_type, $content_encoding, $only_ids)) {
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
                if ($this->write_to_d1($artifact['post_data'], $artifact['categories'], $artifact['tags'], $only_ids)) {
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

        // 缓存清理已归 CMS 侧（site.cache.purge_on_publish，或在 CMS 后台手动清缓存）：
        // WP 侧不再发 /purge-cache 请求，列表/侧边栏按各自 TTL 自然过期。

        return true;
    }

    /**
     * md 文章的单页由 Worker 渲染：数据一变，就必须**清除已存在的 R2 单页**，
     * 否则访问到的是上一次渲染的旧内容（D1 已更新、页面却没变）。
     * 清掉后由 Worker 的「单页按需渲染」用最新 D1 内容现场渲染并回填。
     */
    private function invalidate_md_single_page($post, $only_ids = null) {
        if ($this->get_content_format($post->ID) !== 'md') return;
        $path = $this->get_post_storage_path($post);
        $this->delete_from_r2(array($path, $path . '.gz'), $only_ids);
        $this->log("文章 {$post->ID} 为 md：已清除旧单页（下次访问由 Worker 按需渲染最新内容）", 'INFO');
    }

    /**
     * 按设置页三项开关执行（只渲染一次 HTML）：
     *   ① $sync_d1     写 D1（数据入库；列表页/Worker 后台读它）
     *   ② $gen_static  渲染落盘到 CFSE_LOCAL_DIR
     *   ③ $pub_remote  上传 R2（md 文章在 publish_artifact 内部跳过 R2）+ 清缓存；写不写 D1 由 ① 决定
     */
    private function export_post_by_switches($post, $sync_d1, $gen_static, $pub_remote) {
        // 站点集合：决定「生成到哪些目录」与「发布到哪些目标」
        $sites = $this->get_post_sites($post->ID);
        $onlyIds = array();
        foreach ($sites as $st) $onlyIds[] = $st['id'];
        if (empty($onlyIds)) {
            $this->log("文章 {$post->ID} 未指定站点且未设默认站点，跳过导出（内容只留在本地 WP）", 'INFO');
            return true;
        }
        $artifact = null;
        if ($gen_static || $pub_remote) {
            $artifact = $this->build_post_artifact($post);
            if ($gen_static) $this->write_artifact_local($artifact);
        }
        if ($pub_remote) {
            return $this->publish_artifact($artifact, null, $sync_d1, $onlyIds);
        }
        if ($sync_d1) {
            $meta = $this->build_post_meta($post);
            $ok = $this->write_to_d1($meta['post_data'], $meta['categories'], $meta['tags'], $onlyIds);
            $this->log("文章 {$post->ID} 仅同步到 D1：" . ($ok ? '成功' : '失败'), $ok ? 'INFO' : 'ERROR');
            if ($ok) $this->invalidate_md_single_page($post);   // md：清了旧单页，访问时才能看到新内容
            return $ok;
        }
        return true;
    }
    private function sync_draft_to_d1($post) {
        return CFSE_Worker_CMS::get_instance()->sync_draft_to_d1($post);
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
            $written = $this->write_artifact_local($this->build_post_artifact($post));
            return !empty($written);
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
        $sites = $this->get_post_sites($post->ID);
        if (empty($sites)) {
            $this->log("文章 {$post_id} 未指定站点且未设默认站点，跳过上传（给文章选站点，或标记默认站点）", 'WARNING');
            return false;
        }
        $okAny = false;
        foreach ($sites as $site) {
            $local = $this->site_local_root($site) . '/' . ltrim($path, '/');
            if (!is_readable($local)) {
                $this->log("文章 {$post_id} 站点 " . $this->site_dir_of($site) . " 的本地产物不存在，跳过该站点（请先「生成到本地」）: {$local}", 'WARNING');
                continue;
            }
            $html = (string) @file_get_contents($local);
            if ($html === '') {
                $this->log("文章 {$post_id} 站点 " . $this->site_dir_of($site) . " 的本地产物为空，跳过: {$local}", 'ERROR');
                continue;
            }
            try {
                // 元数据（D1 行）以 WP 当前状态为准，正文用本地已生成的 HTML；只对该站点的目标发布
                $artifact = $this->build_post_meta($post);
                $artifact['path'] = $path;
                $artifact['sites'] = $sites;
                $this->publish_artifact($artifact, $html, true, array($site['id']));
                $okAny = true;
            } catch (Throwable $e) {
                $this->log("文章 {$post_id} 站点 " . $this->site_dir_of($site) . " 上传失败: " . $e->getMessage(), 'ERROR');
            }
        }
        return $okAny;
    }
    private function get_post_site_ids_raw($post_id) {
        return CFSE_Admin_UI::get_instance()->get_post_site_ids_raw($post_id);
    }

    public function add_site_metabox($post_type = '', $post = null) {
        if ($post_type !== 'post') return;
        add_meta_box('cfse_site', '🌐 站点', array($this, 'render_site_metabox'), 'post', 'side', 'default');
    }
    public function render_site_metabox($post) {
        return CFSE_Admin_UI::get_instance()->render_site_metabox($post);
    }

    /** 保存「站点」面板（无本面板 nonce ⇒ 不动作，避免其它写入路径被误清） */
    public function save_site_meta($post_id, $post = null, $update = null) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['cfse_site_nonce'])) return;
        if (!wp_verify_nonce($_POST['cfse_site_nonce'], 'cfse_site_' . $post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;
        delete_post_meta($post_id, self::META_SITE);
        $vals = isset($_POST['cfse_site']) ? (array) $_POST['cfse_site'] : array();
        $added = 0;
        foreach ($vals as $v) {
            $v = preg_replace('/[^0-9]/', '', (string) $v);
            if ($v === '' ) continue;
            add_post_meta($post_id, self::META_SITE, $v);
            $added++;
        }
        $this->log("文章 {$post_id} 站点已更新为 " . ($added ? ($added . ' 个') : '（空 → 走默认站点）'), 'INFO');
    }

    // ---------- 文章列表：批量设置站点 ----------

    public function add_site_bulk_actions($actions) {
        $targets = $this->get_hosting_targets();
        foreach ($targets as $t) {
            $actions['cfse_site_' . (int) $t['id']] = '设置站点：' . $t['name'];
        }
        $actions['cfse_site_clear'] = '清除站点（走默认站点）';
        return $actions;
    }

    public function handle_site_bulk_actions($redirect, $action, $post_ids) {
        if (strpos($action, 'cfse_site_') !== 0) return $redirect;
        $done = 0;
        if ($action === 'cfse_site_clear') {
            foreach ($post_ids as $pid) {
                delete_post_meta($pid, self::META_SITE);
                $done++;
            }
            $this->log("批量：已清除 {$done} 篇文章的站点标记", 'INFO');
        } else {
            $sid = preg_replace('/[^0-9]/', '', substr($action, strlen('cfse_site_')));
            if ($sid !== '' && $this->site_by_id($sid)) {
                foreach ($post_ids as $pid) {
                    delete_post_meta($pid, self::META_SITE);
                    add_post_meta($pid, self::META_SITE, $sid);
                    $done++;
                }
                $this->log("批量：{$done} 篇文章已设为站点 id={$sid}", 'INFO');
            }
        }
        return add_query_arg('cfse_site_bulk', $done, $redirect);
    }

    public function add_doc_format_metabox($post_type = '', $post = null) {
        add_meta_box('cfse_doc_format', '📝 文档格式', array($this, 'render_doc_format_metabox'), 'post', 'side', 'high');
        // 「Worker 端诞生」的篇（wp_id 为时间戳形态，见 ROADMAP I.1）额外给一个同步面板
        $remote_id = ($post && isset($post->ID)) ? $this->get_remote_wp_id($post->ID) : 0;
        if ($remote_id || ($post && isset($post->ID) && (int) $post->ID >= CFSE_WORKER_ID_MIN)) {
            add_meta_box('cfse_worker_sync', '☁️ Worker 同步', array($this, 'render_worker_sync_metabox'), 'post', 'side', 'default');
        }
    }
    public function render_doc_format_metabox($post) {
        return CFSE_Admin_UI::get_instance()->render_doc_format_metabox($post);
    }
    public function render_worker_sync_metabox($post) {
        return CFSE_Admin_UI::get_instance()->render_worker_sync_metabox($post);
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
    private function get_content_format($post_id) {
        return CFSE_Admin_UI::get_instance()->get_content_format($post_id);
    }
    private function render_markdown($md) {
        return CFSE_Worker_CMS::get_instance()->render_markdown($md);
    }
    private function md_to_plain($md) {
        return CFSE_Worker_CMS::get_instance()->md_to_plain($md);
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
    /**
     * 「📤 导出文章」（设置页「文章导出 / 拉取」按钮）：按**手动导出参数**（cf_manual_*）执行。
     * 与发布文章时自动导出（cf_sync_d1 / cf_gen_static / cf_publish_remote）分离 —— 两套参数各自独立。
     */
    public function export_post_manual($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') return false;
        $m_d1  = (bool) get_option('cf_manual_sync_d1', 0);
        $m_pub = (bool) get_option('cf_manual_pub_remote', 0);
        $m_gen = (bool) get_option('cf_manual_gen_static', 0) || $m_pub;   // ③ 隐含 ②
        if (!$m_d1 && !$m_gen && !$m_pub) {
            $this->log("文章 {$post_id} 未勾选任何「导出参数」（导出到 D1 / 生成静态文件 / 上传到 R2），跳过", 'INFO');
            return true;
        }
        if (!extension_loaded('curl')) { $this->log('cURL扩展未安装', 'ERROR'); return false; }
        return (bool) $this->export_post_by_switches($post, $m_d1, $m_gen, $m_pub);
    }

    private function run_export_mode($post_id, $mode) {
        if ($mode === 'generate') return $this->generate_post_local($post_id);
        if ($mode === 'publish') return $this->publish_post_local($post_id);
        if ($mode === 'manual') return $this->export_post_manual($post_id);
        return (bool) $this->export_post($post_id);
    }

    // purge_page_caches() / purge_sites_caches() 已移除（2026-09）：
    // 缓存策略与缓存清理由 CMS 后台负责（D1 settings k='cache'），WP 侧不再产生 /purge-cache 请求。

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
        return CFSE_Worker_CMS::get_instance()->fetch_from_frontend($post);
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
        return CFSE_Worker_CMS::get_instance()->is_valid_html($html);
    }

    private function html_has_title($html, $post) {
        $title = isset($post->post_title) ? trim($post->post_title) : '';
        if ($title === '') return true;
        return strpos($html, esc_html($title)) !== false;
    }
    private function extract_excerpt($content, $length = 200) {
        return CFSE_Worker_CMS::get_instance()->extract_excerpt($content, $length);
    }
    private function sync_media_files($content) {
        return CFSE_Worker_CMS::get_instance()->sync_media_files($content);
    }
    private function upload_media_to_r2($url, $path) {
        return CFSE_Worker_CMS::get_instance()->upload_media_to_r2($url, $path);
    }
    private function get_hosting_templates() {
        return CFSE_Transports::get_instance()->get_hosting_templates();
    }
    private function normalize_intent($raw) {
        return CFSE_Transports::get_instance()->normalize_intent($raw);
    }
    private function intent_requires($intent) {
        return CFSE_Transports::get_instance()->intent_requires($intent);
    }
    private function validate_hosting_targets($is_save = false, $list_override = null) {
        return CFSE_Transports::get_instance()->validate_hosting_targets($is_save, $list_override);
    }
    private function render_adjustments_notice($adjustments) {
        return CFSE_Transports::get_instance()->render_adjustments_notice($adjustments);
    }
    private function default_worker_target() {
        return CFSE_Transports::get_instance()->default_worker_target();
    }
    private function get_hosting_targets($include_disabled = false) {
        return CFSE_Transports::get_instance()->get_hosting_targets($include_disabled);
    }
    private function site_dir_of($target) {
        return CFSE_Admin_UI::get_instance()->site_dir_of($target);
    }

    /** 该站点的本地产物根目录：CFSE_LOCAL_DIR/<site_dir>/ */
    private function site_local_root($target) {
        return CFSE_LOCAL_DIR . '/' . $this->site_dir_of($target);
    }
    private function default_site() {
        return CFSE_Admin_UI::get_instance()->default_site();
    }

    /** 按 id 取站点（启用中的） */
    private function site_by_id($id) {
        $id = (string) $id;
        $targets = isset($this->config['hosting_targets']) ? $this->config['hosting_targets'] : array();
        foreach ($targets as $t) {
            if ((string) (isset($t['id']) ? $t['id'] : '') === $id) return $t;
        }
        return null;
    }

    /**
     * 一篇文章所属的站点列表（数组 of target）。
     * 规则：meta cfse_site 有值 ⇒ 取其中「存在且启用」的站点；无值/全失效 ⇒ 走默认站点；仍无 ⇒ 空数组（该篇不生成、不发布）。
     */
    private function get_post_sites($post_id) {
        $raw = get_post_meta($post_id, self::META_SITE, false);
        $ids = array();
        foreach ((array) $raw as $v) {
            if (is_array($v)) { foreach ($v as $x) $ids[] = (string) $x; }
            elseif (is_string($v) && strpos($v, ',') !== false) { foreach (explode(',', $v) as $x) $ids[] = trim($x); }
            elseif ($v !== '' && $v !== null) { $ids[] = (string) $v; }
        }
        $sites = array(); $seen = array();
        foreach ($ids as $id) {
            if ($id === '' || isset($seen[$id])) continue;
            $t = $this->site_by_id($id);
            if ($t) { $sites[] = $t; $seen[$id] = true; }
        }
        if (empty($sites)) {
            $def = $this->default_site();
            if ($def) $sites[] = $def;
        }
        return $sites;
    }
    private function for_each_target($capability, $send, $only_ids = null) {
        return CFSE_Transports::get_instance()->for_each_target($capability, $send, $only_ids);
    }
    private function worker_request($target, $route, $method, $body = null, $timeout = 30) {
        return CFSE_Transports::get_instance()->worker_request($target, $route, $method, $body, $timeout);
    }
    private function target_upload($target, $path, $content, $content_type, $content_encoding) {
        return CFSE_Transports::get_instance()->target_upload($target, $path, $content, $content_type, $content_encoding);
    }
    private function target_delete($target, $paths) {
        return CFSE_Transports::get_instance()->target_delete($target, $paths);
    }
    private function target_public_url($target, $key) {
        return CFSE_Transports::get_instance()->target_public_url($target, $key);
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
    private function upload_to_r2_via_worker($path, $content, $content_type = 'text/html', $content_encoding = null, $only_ids = null) {
        return CFSE_Worker_CMS::get_instance()->upload_to_r2_via_worker($path, $content, $content_type, $content_encoding, $only_ids);
    }
    private function ensure_d1_schema() {
        return CFSE_Worker_CMS::get_instance()->ensure_d1_schema();
    }
    private function migrate_d1_schema($rebuild_links = false) {
        return CFSE_Worker_CMS::get_instance()->migrate_d1_schema($rebuild_links);
    }
    private function write_to_d1($post_data, $categories, $tags, $only_ids = null) {
        return CFSE_Worker_CMS::get_instance()->write_to_d1($post_data, $categories, $tags, $only_ids);
    }
    private function get_post_terms($post_id, $taxonomy) {
        return CFSE_Worker_CMS::get_instance()->get_post_terms($post_id, $taxonomy);
    }
    private function d1_execute($sql, $params = array()) {
        return CFSE_Worker_CMS::get_instance()->d1_execute($sql, $params);
    }
    private function d1_query($sql, $params = array()) {
        return CFSE_Worker_CMS::get_instance()->d1_query($sql, $params);
    }

    // ===== 以下 10 个方法已拆分到 includes/class-transports.php（同名转发，调用点无需改动）=====

    private function sigv4_uri_encode($s, $encode_slash = true) {
        return CFSE_Transports::get_instance()->sigv4_uri_encode($s, $encode_slash);
    }

    private function sigv4_canonical_query($query) {
        return CFSE_Transports::get_instance()->sigv4_canonical_query($query);
    }

    private function s3_creds($target) {
        return CFSE_Transports::get_instance()->s3_creds($target);
    }

    private function s3_path_style($target, $host) {
        return CFSE_Transports::get_instance()->s3_path_style($target, $host);
    }

    private function s3_classify($code, $text) {
        return CFSE_Transports::get_instance()->s3_classify($code, $text);
    }

    private function s3_request($target, $method, $key = '', $query = array(), $body = '', $content_type = '') {
        return CFSE_Transports::get_instance()->s3_request($target, $method, $key, $query, $body, $content_type);
    }

    private function s3_test_connection($target) {
        return CFSE_Transports::get_instance()->s3_test_connection($target);
    }

    private function sigv4_sign($creds, $method, $path, $query = array(), $payload = '', $date = null, $sign_content_sha = true) {
        return CFSE_Transports::get_instance()->sigv4_sign($creds, $method, $path, $query, $payload, $date, $sign_content_sha);
    }

    private function target_dir($target) {
        return CFSE_Transports::get_instance()->target_dir($target);
    }

    private function target_type($target) {
        return CFSE_Transports::get_instance()->target_type($target);
    }
    private function d1_batch($statements, $only_ids = null) {
        return CFSE_Worker_CMS::get_instance()->d1_batch($statements, $only_ids);
    }
    private function worker_d1_batch($target, $statements) {
        return CFSE_Worker_CMS::get_instance()->worker_d1_batch($target, $statements);
    }
    public function purge_site_ctx_option() {
        return CFSE_Worker_CMS::get_instance()->purge_site_ctx_option();
    }
    /**
     * 设置页「🔄 同步站点设置到 D1」：WP 侧**唯一**会写 D1 site 行的入口（服务端合并写入）。
     * 自动写入已全部下线（改站点标题/描述/URL、同步模板、初始化部署 都不再写 D1）。
     */
    public function handle_sync_site_info() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cf_sync_site_info');
        $ok = $this->sync_site_info();
        wp_redirect(add_query_arg('site_info_synced', $ok ? '1' : '0', admin_url('options-general.php?page=cf-static-export-pro')));
        exit;
    }
    public function sync_site_info() {
        return CFSE_Worker_CMS::get_instance()->sync_site_info();
    }

    // ============================================
    // 评论 → D1 同步（B1：单向；WP 始终是权威）
    // ============================================
    /** 评论同步总开关（默认关：不动远端） */
    private function comments_sync_enabled() {
        return (bool) get_option('cf_comments_sync', false);
    }

    /** 抓取风格时临时创建的探针文章 ⇒ 它的评论不同步 */
    private function is_probe_comment($comment) {
        if (!$comment) return false;
        $pid = (int) $comment->comment_post_ID;
        if ($pid <= 0) return false;
        return (bool) get_post_meta($pid, '_cfse_probe', true);
    }

    /** 单条评论的 D1 行（顺序与 SQL 占位符一一对应） */
    private function comment_d1_row($comment) {
        $appr = ($comment->comment_approved === '1' || $comment->comment_approved === 1) ? 1 : 0;
        // 显示用日期文本：与抓取端 cfse_skin_comment_entry_skeleton() 的候选顺序保持一致（date_format + ' at ' + time_format）
        $date_text = '';
        $ts = strtotime((string) $comment->comment_date);
        if ($ts) {
            $df = (string) get_option('date_format');
            $tf = (string) get_option('time_format');
            if ($df !== '' && $tf !== '') { $date_text = date_i18n($df, $ts) . ' at ' . date_i18n($tf, $ts); }
            else { $date_text = date_i18n($df !== '' ? $df : 'F j, Y', $ts); }
        }
        // 头像 URL：在 WP 侧算好（gravatar 需要 md5，Worker 端没有可靠的同步 md5）
        $avatar = '';
        if (function_exists('get_avatar_url')) {
            $avatar = (string) get_avatar_url($comment, array('size' => 40));
        }
        return array(
            (int) $comment->comment_ID,
            (int) $comment->comment_ID,   // wp_comment_id（B1 阶段就是它自己）
            (int) $comment->comment_post_ID,
            (int) $comment->comment_parent,
            (string) $comment->comment_author,
            (string) $comment->comment_author_email,
            (string) $comment->comment_author_url,
            $avatar,
            $date_text,
            (string) $comment->comment_date,
            (string) $comment->comment_content,
            $appr,
            (string) ($comment->comment_type !== '' ? $comment->comment_type : 'comment'),
            'wp',
            current_time('mysql'),
        );
    }

    private function comment_upsert_sql() {
        return "INSERT INTO comments (comment_id, wp_comment_id, post_id, parent_id, author, author_email, author_url, avatar, date_text, date, content, approved, type, origin, updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(comment_id) DO UPDATE SET wp_comment_id=excluded.wp_comment_id, post_id=excluded.post_id,
                parent_id=excluded.parent_id, author=excluded.author, author_email=excluded.author_email,
                author_url=excluded.author_url, avatar=excluded.avatar, date_text=excluded.date_text,
                date=excluded.date, content=excluded.content,
                approved=excluded.approved, type=excluded.type, origin=excluded.origin, updated_at=excluded.updated_at";
    }

    /** 新增/编辑一条评论 → D1（wp_insert_comment / edit_comment 共用） */
    public function on_comment_changed($comment_id, $comment = null) {
        if (!empty($GLOBALS['cfse_skip_comment_sync'])) return;   // 回拉期间短路（见 pull_comments_from_worker）
        if (!$this->comments_sync_enabled()) return;
        if (!$comment) $comment = get_comment($comment_id);
        if (!$comment || $this->is_probe_comment($comment)) return;
        // 回拉来的评论（带 _cfse_remote_comment_id）：它对应 D1 里**已存在**的那一行，
        // 只能更新那一行（wp_comment_id / origin / approved），绝不能按 WP 新 ID 再插一行。
        $remote_id = (int) get_comment_meta((int) $comment->comment_ID, '_cfse_remote_comment_id', true);
        if ($remote_id > 0) {
            $this->comment_map_back($remote_id, (int) $comment->comment_ID);
            return;
        }
        $this->d1_batch(array(array('sql' => $this->comment_upsert_sql(), 'params' => $this->comment_d1_row($comment))));
        $this->log('评论 #' . (int) $comment_id . ' 已同步到 D1（approved=' . $comment->comment_approved . '）', 'DEBUG');
    }

    /** 审核状态变化（wp_set_comment_status / 后台批量审核都会触发）
     *  ⚠️ `wp_delete_comment()` 末尾也会触发一次 `transition_comment_status('…->delete')`——
     *  这里若无脑 upsert，就会把刚删掉的行又写回 D1（实测踩过）⇒ 删除类状态走删除。 */
    public function on_comment_status_changed($new_status, $old_status, $comment) {
        if (!$comment) return;
        $id = (int) $comment->comment_ID;
        if (in_array($new_status, array('delete', 'trash', 'spam'), true)) {
            $this->on_comment_deleted($id);
            return;
        }
        $this->on_comment_changed($id, $comment);
    }

    /** 评论**删除前** → 从 D1 删除
     *  回拉来的评论在 D1 里的主键是**远端 ID**（时间戳形态），所以必须用 meta `_cfse_remote_comment_id` 定位；
     *  而到 `deleted_comment` 时该 meta 已被 WP 删掉 ⇒ 只能挂在更早的 `delete_comment` 上。 */
    public function on_comment_deleting($comment_id, $comment = null) {
        if (!$this->comments_sync_enabled()) return;
        $remote_id = (int) get_comment_meta((int) $comment_id, '_cfse_remote_comment_id', true);
        $target = $remote_id > 0 ? $remote_id : (int) $comment_id;
        $this->d1_batch(array(array('sql' => 'DELETE FROM comments WHERE comment_id = ?', 'params' => array($target))));
        $this->log('评论 #' . (int) $comment_id . ' 已从 D1 删除（D1 id=' . $target . '）', 'DEBUG');
    }

    /** 评论删除 → 从 D1 删除（兜底，按 WP comment_ID；回拉来的评论由上面的 delete_comment 钩子处理） */
    public function on_comment_deleted($comment_id) {
        if (!$this->comments_sync_enabled()) return;
        $this->d1_batch(array(array(
            'sql' => 'DELETE FROM comments WHERE comment_id = ?',
            'params' => array((int) $comment_id),
        )));
        $this->log('评论 #' . (int) $comment_id . ' 已从 D1 删除', 'DEBUG');
    }

    /** 全量补同步（分批；返回 array(已处理条数, WP 侧评论总数)） */
    public function sync_all_comments($batch = 200) {
        if (!$this->comments_sync_enabled()) return array(0, 0);
        $batch = max(1, min(1000, (int) $batch));
        $offset = 0;
        $done = 0;
        $total = 0;
        $counts = wp_count_comments();
        if (is_object($counts) && isset($counts->total_comments)) { $total = (int) $counts->total_comments; }
        while (true) {
            $rows = get_comments(array('status' => 'any', 'number' => $batch, 'offset' => $offset, 'orderby' => 'comment_ID', 'order' => 'ASC'));
            if (empty($rows)) break;
            $statements = array();
            foreach ($rows as $c) {
                if ($this->is_probe_comment($c)) continue;
                $statements[] = array('sql' => $this->comment_upsert_sql(), 'params' => $this->comment_d1_row($c));
            }
            if (!empty($statements)) { $this->d1_batch($statements); $done += count($statements); }
            if (count($rows) < $batch) break;
            $offset += $batch;
            if ($offset > 50000) break;
        }
        $this->log('评论全量补同步：' . $done . ' / ' . $total, 'INFO');
        return array($done, $total);
    }

    // ============================================
    // B2：把 Worker 端收到的评论拉回 WP（WP 主动拉；落地后 WP 成为权威）
    //   线上 Worker 无法访问内网源站 ⇒ 方向只能是 WP 拉（与 /sync/posts 同构）。
    // ============================================
    /** 把「已落地」的结果写回 D1：wp_comment_id + origin='wp' + approved（外加头像与格式化日期 —— 只有 WP 算得出），
     *  ⇒ 下次不再被拉（不回环），且「Worker 端提交」的评论也能拿到头像与主题日期文本。 */
    private function comment_map_back($remote_id, $wp_comment_id) {
        $c = get_comment($wp_comment_id);
        if (!$c) return;
        // 行顺序见 comment_d1_row()：[4]author [5]email [6]url [7]avatar [8]date_text [10]content [11]approved
        $row = $this->comment_d1_row($c);
        $this->d1_batch(array(array(
            'sql' => "UPDATE comments SET wp_comment_id = ?, origin = 'wp', approved = ?, avatar = ?, date_text = ?,
                      author = ?, author_email = ?, author_url = ?, content = ?, updated_at = ? WHERE comment_id = ?",
            'params' => array(
                (int) $wp_comment_id,
                (int) $row[11],
                (string) $row[7],
                (string) $row[8],
                (string) $row[4],
                (string) $row[5],
                (string) $row[6],
                (string) $row[10],
                current_time('mysql'),
                (int) $remote_id,
            ),
        )));
    }

    /** 回拉 Worker 端评论（origin='worker' 且未落地）。返回 array(新增, 跳过, 错误) */
    public function pull_comments_from_worker() {
        $site = isset($this->config['site_url']) ? rtrim($this->config['site_url'], '/') : '';
        $key = isset($this->config['auth_key']) ? $this->config['auth_key'] : '';
        if ($site === '' || $key === '') {
            $this->log('评论回拉失败：静态站点 URL 或 Worker Auth Key 未配置', 'ERROR');
            return array(0, 0, 1);
        }
        $resp = wp_remote_get($site . '/sync/comments?limit=500&unpulled=1', array('timeout' => 60, 'headers' => array('X-Auth-Key' => $key)));
        if (is_wp_error($resp)) {
            $this->log('评论回拉失败：' . $resp->get_error_message(), 'ERROR');
            return array(0, 0, 1);
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            $this->log("评论回拉失败：HTTP {$code} " . substr((string) wp_remote_retrieve_body($resp), 0, 200), 'ERROR');
            return array(0, 0, 1);
        }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        $items = (is_array($data) && isset($data['comments']) && is_array($data['comments'])) ? $data['comments'] : array();

        $added = 0; $skipped = 0; $errors = 0;
        $map = array();   // 远端 comment_id => WP comment_ID（父评论先到 ⇒ 能正确挂 children）
        // 回拉期间**短路同步钩子**：此刻 WP 侧评论还没写 _cfse_remote_comment_id（要等插入后才能写），
        // 不短路的话钩子会按 WP 新 ID 另插一行（实测踩过）⇒ 用「保存-恢复」写法；落地后由 comment_map_back 回写。
        $prev_skip = isset($GLOBALS['cfse_skip_comment_sync']) ? $GLOBALS['cfse_skip_comment_sync'] : null;
        $GLOBALS['cfse_skip_comment_sync'] = true;
        foreach ($items as $c) {
            $rid = isset($c['comment_id']) ? (int) $c['comment_id'] : 0;
            $pid = isset($c['post_id']) ? (int) $c['post_id'] : 0;
            if ($rid <= 0 || $pid <= 0) { $errors++; continue; }

            // 已落地过？（meta 兜底：万一 D1 的 wp_comment_id 没写回）
            $exists = get_comments(array(
                'meta_key' => '_cfse_remote_comment_id', 'meta_value' => $rid,
                'number' => 1, 'status' => 'any', 'fields' => 'ids',
            ));
            if (!empty($exists)) {
                $map[$rid] = (int) $exists[0];
                $this->comment_map_back($rid, (int) $exists[0]);
                $skipped++;
                continue;
            }
            if (!get_post($pid)) { $skipped++; continue; }   // 目标文章不在 WP（例如 Worker 侧诞生的文章）
            $rparent = isset($c['parent_id']) ? (int) $c['parent_id'] : 0;
            // 父评论映射：① 本批已处理过的（$map：远端 comment_id → WP comment_ID）；② 或者它本来就活在 WP 里。
            // 回拉只取 unpulled（origin='worker'），而**父评论常常早已是 WP 原生评论** —— D1 里原生评论的
            // comment_id 就等于 WP comment_ID，但它不在本批里、$map 里自然也没有 ⇒ 子回复会掉成顶层。
            // 实测踩过：回复落进 WP 后 comment_parent=0（层级丢失）。
            $parent_wp = 0;
            if ($rparent > 0) {
                if (isset($map[$rparent])) {
                    $parent_wp = (int) $map[$rparent];
                } else {
                    $pobj = get_comment($rparent);
                    if ($pobj && (int) $pobj->comment_post_ID === $pid) {
                        $parent_wp = (int) $rparent;
                    }
                }
            }

            // 刻意**不指定** comment_ID：那会把 wp_comments 的 AUTO_INCREMENT 顶到时间戳量级（批L 的教训）。
            // 远端 comment_id 记在 meta `_cfse_remote_comment_id`，回写 D1 时用它定位那一行。
            $args = array(
                'comment_post_ID'      => $pid,
                'comment_author'       => isset($c['author']) ? (string) $c['author'] : '',
                'comment_author_email' => isset($c['author_email']) ? (string) $c['author_email'] : '',
                'comment_author_url'   => isset($c['author_url']) ? (string) $c['author_url'] : '',
                'comment_content'      => isset($c['content']) ? (string) $c['content'] : '',
                'comment_approved'     => (isset($c['approved']) && (int) $c['approved'] === 1) ? 1 : 0,
                'comment_parent'       => $parent_wp,
                'comment_type'         => 'comment',
            );
            if (!empty($c['date'])) { $args['comment_date'] = (string) $c['date']; $args['comment_date_gmt'] = (string) $c['date']; }
            $cid = wp_insert_comment($args);
            if (!$cid) { $errors++; continue; }
            add_comment_meta($cid, '_cfse_remote_comment_id', $rid, true);
            $map[$rid] = (int) $cid;
            $this->comment_map_back($rid, (int) $cid);   // 落地后立刻回写 D1（wp_comment_id / origin / approved）
            $added++;
        }
        // 恢复原值（不要直接 unset：可能与外层调用嵌套）
        if ($prev_skip === null) { unset($GLOBALS['cfse_skip_comment_sync']); }
        else { $GLOBALS['cfse_skip_comment_sync'] = $prev_skip; }
        $this->log('评论回拉完成：新增 ' . $added . ' / 跳过 ' . $skipped . ' / 错误 ' . $errors . '（返回 ' . count($items) . ' 条）', 'INFO');
        return array($added, $skipped, $errors);
    }

    /** cron：定时评论回拉（默认开；需评论同步也开着 —— 不同步就没必要拉） */
    public function handle_pull_comments_cron() {
        if (!get_option('cf_comments_pull_cron', true)) return;
        if (!$this->comments_sync_enabled()) return;
        list($added, $skipped, $errors) = $this->pull_comments_from_worker();
        if ($added > 0 || $errors > 0) {
            $this->log('定时评论回拉：新增 ' . $added . ' / 跳过 ' . $skipped . ' / 错误 ' . $errors, 'INFO');
        }
    }

    /** 设置页按钮：手动触发评论回拉 */
    public function handle_pull_comments() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cfse_pull_comments');
        list($added, $skipped, $errors) = $this->pull_comments_from_worker();
        $back = wp_get_referer();
        wp_safe_redirect(add_query_arg(array('cfse_comments_pulled' => $added, 'cfse_comments_pull_err' => $errors), $back ? $back : admin_url()));
        exit;
    }

    /** 设置页按钮：手动触发评论全量补同步 */
    public function handle_sync_comments() {
        if (!current_user_can('manage_options')) wp_die('权限不足');
        check_admin_referer('cfse_sync_comments');
        list($done, $total) = $this->sync_all_comments(200);
        $this->log('手动评论全量补同步完成：' . $done . ' / ' . $total, 'INFO');
        $back = wp_get_referer();
        wp_safe_redirect(add_query_arg(array('cfse_comments_synced' => $done), $back ? $back : admin_url()));
        exit;
    }
    public function delete_post($post_input) {
        return CFSE_Worker_CMS::get_instance()->delete_post($post_input);
    }
    private function delete_local_artifact($r2_path) {
        return CFSE_Worker_CMS::get_instance()->delete_local_artifact($r2_path);
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
    private function delete_from_r2($paths, $only_ids = null) {
        try {
            $paths = array_values(array_filter((array) $paths, 'strlen'));
            if (empty($paths)) return true;
            // 多托管：对所有「具备静态文件能力」的目标删除（可按站点过滤）
            $res = $this->for_each_target('r2', function ($t) use ($paths) {
                return $this->target_delete($t, $paths);
            }, $only_ids);
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
    private function permalink_to_storage_pattern($permalink) {
        return CFSE_Worker_CMS::get_instance()->permalink_to_storage_pattern($permalink);
    }
	/**
	 * 同步模板：把模板编辑器保存的自定义模板导出为风格包 tpl.json（worker/static 下，需 wrangler deploy 生效）
	 */
public function handle_sync_templates() {
    if (!current_user_can('manage_options')) wp_die('权限不足');
    check_admin_referer('cf_sync_templates');
    
    // 导出风格包 tpl.json（渲染引擎固定在 Worker 侧，不再生成 templates.js）
    if (function_exists('cfse_export_tpl_json_from_custom')) {
        cfse_export_tpl_json_from_custom();
    }

    // 站点信息不再随本操作写入 D1（需要时在设置页点「🔄 同步站点设置到 D1」）

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

    // 站点信息不再随本操作写入 D1（需要时在设置页点「🔄 同步站点设置到 D1」）

    // 重新生成 routes.js（源为 routes.template.js；每次部署同步，确保 permalink/每页数等最新）
    $this->sync_routes();

    // 生成/部署分支：激活风格模式下直接使用 styles/<id> 快照产物（跳过再导出与主题资源同步）；
    // 默认（WP 模板模式）按原有流程导出 tpl.json + stage_theme_assets。
    $active_style = get_option('cfse_active_style', '');
    $style_root = ($active_style !== '') ? CFSE_PLUGIN_DIR . 'styles/' . $active_style : '';
    $using_style = ($style_root !== '' && is_dir($style_root) && file_exists($style_root . '/tpl.json'));
    if ($using_style) {
        $this->log("部署使用激活风格: {$active_style}（跳过模板再生成与主题资源同步）", 'INFO');
    } else {
        if ($active_style !== '') {
            $this->log('激活风格目录缺失，回退为 WP 模板模式: ' . $style_root, 'WARNING');
            delete_option('cfse_active_style');
        }
        // 导出最新的风格包 tpl.json
        if (function_exists('cfse_export_tpl_json_from_custom')) {
            cfse_export_tpl_json_from_custom();
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

        $required = array('src/index.js', 'src/routes.js', 'src/template-engine.js', 'src/fallback-tpl.js', 'src/database.js', 'src/storage.js', 'src/cache.js', 'src/auth.js', 'src/admin.js', 'src/site.js');
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
        // 两个「已处理」标记都要清：列表状态列与「自动导出」的筛选条件都看它们；
        // 只清 _cf_exported 的话，对"只勾了同步到 D1"的文章等于没清（自动导出的条件要求两个标记都不存在）。
        // 只动 WP 侧记账 —— 不碰 D1 / R2 / 本地产物 / site:ctx（那些归 CMS 自己管）。
        $wpdb->delete($wpdb->postmeta, array('meta_key' => '_cf_exported'));
        $wpdb->delete($wpdb->postmeta, array('meta_key' => '_cfse_synced_d1'));
        $this->log('已重置导出标记（_cf_exported / _cfse_synced_d1 已清空）', 'INFO');
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
        return CFSE_Admin_UI::get_instance()->render_export_column($column, $post_id);
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
    private function fetch_worker_post($wp_id) {
        return CFSE_Worker_CMS::get_instance()->fetch_worker_post($wp_id);
    }
    private function overwrite_post_from_remote($post_id, $remote) {
        return CFSE_Worker_CMS::get_instance()->overwrite_post_from_remote($post_id, $remote);
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
            } elseif ($type === 'github') {
                $repo = isset($t['repo']) ? trim($t['repo']) : '';
                $tok  = isset($t['token']) ? trim($t['token']) : '';
                if ($repo === '' || $tok === '') {
                    $out[] = array('name' => $name, 'ok' => false, 'code' => 'config', 'detail' => '仓库 owner/name 与 Token 都要填', 'http' => 0);
                } else {
                    $resp = wp_remote_get('https://api.github.com/repos/' . $repo, array(
                        'timeout' => 15,
                        'headers' => array(
                            'Authorization' => 'Bearer ' . $tok,
                            'Accept'        => 'application/vnd.github+json',
                            'User-Agent'    => 'cf-static-export-pro',
                        ),
                    ));
                    if (is_wp_error($resp)) {
                        $out[] = array('name' => $name, 'ok' => false, 'code' => 'network', 'detail' => $resp->get_error_message(), 'http' => 0);
                    } else {
                        $code = (int) wp_remote_retrieve_response_code($resp);
                        $body = (string) wp_remote_retrieve_body($resp);
                        if ($code === 200) {
                            $j = json_decode($body, true);
                            $perm = (is_array($j) && isset($j['permissions']['push']) && $j['permissions']['push']) ? '有写权限' : '只读权限（部署需要写权限）';
                            $out[] = array('name' => $name, 'ok' => true, 'code' => 'ok',
                                'detail' => 'HTTP 200，仓库可访问（' . $perm . '）' . (isset($j['full_name']) ? '：' . $j['full_name'] : ''), 'http' => $code);
                        } elseif ($code === 401) {
                            $out[] = array('name' => $name, 'ok' => false, 'code' => 'unauthorized', 'detail' => 'HTTP 401：Token 不对或已过期', 'http' => $code);
                        } elseif ($code === 404) {
                            $out[] = array('name' => $name, 'ok' => false, 'code' => 'not_found',
                                'detail' => 'HTTP 404：仓库不存在，或 Token 无权访问私有仓库（需 repo 权限）', 'http' => $code);
                        } elseif ($code === 403) {
                            $out[] = array('name' => $name, 'ok' => false, 'code' => 'forbidden', 'detail' => 'HTTP 403：权限不足或被限流', 'http' => $code);
                        } else {
                            $out[] = array('name' => $name, 'ok' => false, 'code' => 'http_' . $code, 'detail' => "HTTP {$code} " . substr($body, 0, 160), 'http' => $code);
                        }
                    }
                }
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
    private function pull_posts_from_worker($overwrite = false) {
        return CFSE_Worker_CMS::get_instance()->pull_posts_from_worker($overwrite);
    }
    private function get_remote_wp_id($post_id) {
        return CFSE_Worker_CMS::get_instance()->get_remote_wp_id($post_id);
    }
    private function find_post_by_remote_id($remote_wp_id) {
        return CFSE_Worker_CMS::get_instance()->find_post_by_remote_id($remote_wp_id);
    }
    private function apply_worker_terms($post_id, $json, $taxonomy) {
        return CFSE_Worker_CMS::get_instance()->apply_worker_terms($post_id, $json, $taxonomy);
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
    private function allowed_site_roots() {
        return CFSE_Transports::get_instance()->allowed_site_roots();
    }
    private function is_forbidden_site_dir($real) {
        return CFSE_Transports::get_instance()->is_forbidden_site_dir($real);
    }
    private function resolve_site_dir($dir) {
        return CFSE_Transports::get_instance()->resolve_site_dir($dir);
    }
    private function site_file_list($real_dir, $reset = false) {
        return CFSE_Transports::get_instance()->site_file_list($real_dir, $reset);
    }
    private function mime_for_path($path) {
        return CFSE_Transports::get_instance()->mime_for_path($path);
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
    private function github_api($target, $method, $path, $body = null) {
        return CFSE_Transports::get_instance()->github_api($target, $method, $path, $body);
    }
    private function github_blob_sha($content) {
        return CFSE_Transports::get_instance()->github_blob_sha($content);
    }
    private function github_deploy($target, $dir) {
        return CFSE_Transports::get_instance()->github_deploy($target, $dir);
    }
    private function target_deploy($target, $dir) {
        return CFSE_Transports::get_instance()->target_deploy($target, $dir);
    }
    private function deploy_prepare($target, $dir, $maxFiles = 500) {
        return CFSE_Transports::get_instance()->deploy_prepare($target, $dir, $maxFiles);
    }
    private function netlify_deploy($target, $dir) {
        return CFSE_Transports::get_instance()->netlify_deploy($target, $dir);
    }
    private function netlify_api($target, $method, $path, $body = null) {
        return CFSE_Transports::get_instance()->netlify_api($target, $method, $path, $body);
    }
    private function vercel_deploy($target, $dir) {
        return CFSE_Transports::get_instance()->vercel_deploy($target, $dir);
    }
    private function vercel_api($target, $method, $path, $body = null) {
        return CFSE_Transports::get_instance()->vercel_api($target, $method, $path, $body);
    }
    private function gitlab_deploy($target, $dir) {
        return CFSE_Transports::get_instance()->gitlab_deploy($target, $dir);
    }
    private function ftp_deploy($target, $dir) {
        return CFSE_Transports::get_instance()->ftp_deploy($target, $dir);
    }
    private function ftp_mkdirs($conn, $dir) {
        return CFSE_Transports::get_instance()->ftp_mkdirs($conn, $dir);
    }
    private function webdav_deploy($target, $dir) {
        return CFSE_Transports::get_instance()->webdav_deploy($target, $dir);
    }
    private function webdav_mkdirs($target, $base, $dir) {
        return CFSE_Transports::get_instance()->webdav_mkdirs($target, $base, $dir);
    }
    private function webdav_encode($path) {
        return CFSE_Transports::get_instance()->webdav_encode($path);
    }
    private function webdav_request($target, $method, $url, $body, $contentType) {
        return CFSE_Transports::get_instance()->webdav_request($target, $method, $url, $body, $contentType);
    }
    private function gitlab_api($target, $method, $path, $body = null) {
        return CFSE_Transports::get_instance()->gitlab_api($target, $method, $path, $body);
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

        $this->log('导出文章开始，共 ' . count($all) . ' 篇，并发 ' . $this->config['export_concurrency'] . '，模式 manual（按「导出参数」勾选）', 'INFO');
        list($success, $total) = $this->export_posts_concurrent($all, $this->config['export_concurrency'], 'manual');
        $this->log("导出文章完成：成功 {$success} / {$total}", 'INFO');
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