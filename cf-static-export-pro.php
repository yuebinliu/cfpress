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
    private $config = array();


    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (isset($_GET['_cfse_internal']) && $_GET['_cfse_internal'] === '1') {
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
		add_action('before_delete_post', array($this, 'delete_post'));
		add_action('trash_post', array($this, 'delete_post'));
		add_action('untrash_post', array($this, 'export_post'));
		add_action('post_submitbox_misc_actions', array($this, 'add_manual_export_button'));
		add_action('admin_post_cf_manual_export', array($this, 'handle_manual_export'));
		add_action('admin_post_cf_bulk_export', array($this, 'handle_bulk_export'));
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
        $this->config = array(
            'account_id' => get_option('cf_account_id', ''),
            'api_token' => get_option('cf_api_token', ''),
            'r2_bucket' => get_option('cf_r2_bucket', ''),
            'd1_database' => get_option('cf_d1_database', ''),
            'site_url' => rtrim(get_option('cf_site_url', get_site_url()), '/'),
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
            'auth_key' => get_option('cf_auth_key', ''),
            'posts_per_page' => max(1, (int) get_option('cf_posts_per_page', 10)),
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
        );
        foreach ($fields as $key => $args) {
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
                            <tr><th><label for="cf_cache_ttl">缓存时间</label></th>
                                <td><input type="number" id="cf_cache_ttl" name="cf_cache_ttl" value="<?php echo esc_attr($this->config['cache_ttl']); ?>" style="width:100px" /> 秒</td></tr>
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
                            <tr><th>高级选项</th>
                                <td>
                                    <label><input type="checkbox" name="cf_clean_dynamic_scripts" value="1" <?php checked($this->config['clean_scripts']); ?> /> 移除动态脚本</label><br/>
                                    <label><input type="checkbox" name="cf_enable_logging" value="1" <?php checked($this->config['enable_logging']); ?> /> 启用日志</label><br/>
                                    <label><input type="checkbox" name="cf_enable_compression" value="1" <?php checked($this->config['enable_compression']); ?> /> 启用Gzip压缩</label><br/>
                                    <label><input type="checkbox" name="cf_debug_mode" value="1" <?php checked($this->config['debug_mode']); ?> /> 调试模式</label><br/>
                                    <label><input type="checkbox" name="cf_purge_sidebar" value="1" <?php checked($this->config['purge_sidebar']); ?> /> 发布后即时刷新侧边栏（成本极小，建议开启）</label><br/>
                                    <label><input type="checkbox" name="cf_purge_lists" value="1" <?php checked($this->config['purge_lists']); ?> /> 发布后即时刷新列表页第一页缓存（首页/分类/标签/归档；高更新大站建议关闭，靠缓存自然过期）</label>
                                </td></tr>
                            <tr><th>缓存策略</th>
                                <td>
                                    <p class="description">纯 TTL 语义：<b>0 = 直查 D1 不缓存</b>（最省 KV 写入额度，推荐默认）。常见组合：全 D1 = 列表 0 / 侧边栏 0；混合 = 列表 0 / 侧边栏 86400；全 KV = 列表 3600 / 侧边栏 86400（仅高流量建议，注意 KV 免费写额度 1000/天）。<b>运行时调整请在 Worker 后台「设置 → 缓存策略」操作</b>（保存即生效，以最后保存的一侧为准）。</p>
                                    <div style="margin-top:6px;">
                                        <label>列表页 TTL <input type="number" name="cf_cache_ttl_list" value="<?php echo esc_attr($this->config['cache_ttl_list']); ?>" style="width:90px" min="0" /> 秒</label>
                                        <label style="margin-left:12px;">侧边栏 TTL <input type="number" name="cf_cache_ttl_sidebar" value="<?php echo esc_attr($this->config['cache_ttl_sidebar']); ?>" style="width:90px" min="0" /> 秒</label>
                                        <label style="margin-left:12px;">站点信息 TTL <input type="number" name="cf_cache_ttl_site" value="<?php echo esc_attr($this->config['cache_ttl_site']); ?>" style="width:90px" min="0" /> 秒</label>
                                        <label style="margin-left:12px;">Feed TTL <input type="number" name="cf_cache_ttl_feed" value="<?php echo esc_attr($this->config['cache_ttl_feed']); ?>" style="width:90px" min="0" /> 秒</label>
                                        <label style="margin-left:12px;">Feed 条数 <input type="number" name="cf_feed_count" value="<?php echo esc_attr($this->config['feed_count']); ?>" style="width:70px" min="1" max="100" /></label>
                                    </div>
                                </td></tr>
                        </table>
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
        .cfse-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px; }
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
        if (!empty($GLOBALS['cfse_in_export'])) {
            $this->log("文章 {$post_id} 跳过：已在导出流程中", 'DEBUG');
            return;
        }
        $GLOBALS['cfse_in_export'] = true;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (wp_is_post_autosave($post_id)) return;
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') {
            $GLOBALS['cfse_in_export'] = false;
            return;
        }
        if (get_post_meta($post_id, '_cfse_probe', true) === '1') {
            $this->log("文章 {$post_id} 为外观探针文章，跳过导出", 'DEBUG');
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
            $result = $this->do_export_post($post);
            $duration = round(microtime(true) - $start_time, 2);
            if ($result) {
                $this->log("文章 {$post_id} 导出成功，耗时 {$duration}s", 'INFO');
                update_option('cf_last_export_time', current_time('mysql'));
                $this->increment_exported_count();
                update_post_meta($post_id, '_cf_exported', time());
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

    private function do_export_post($post) {
        // 分类/标签取结构化数据：name + slug（供条目模板渲染分类/标签行）
        $categories = $this->get_post_terms($post->ID, 'category');
        $tags = $this->get_post_terms($post->ID, 'post_tag');

        $post_data = array(
            'wp_id' => $post->ID,
            'slug' => $post->post_name,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt ?: $this->extract_excerpt($post->post_content, 200),
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'categories' => wp_json_encode($categories, JSON_UNESCAPED_UNICODE),
            'tags' => wp_json_encode($tags, JSON_UNESCAPED_UNICODE),
            'featured_image' => get_the_post_thumbnail_url($post->ID, 'full') ?: '',
            'status' => 'published'
        );

        $html = $this->generate_post_html($post);
        if (!$html) throw new Exception('HTML生成失败');
        $this->log("HTML 生成成功，大小: " . strlen($html) . " 字节", 'DEBUG');

        $content_type = 'text/html; charset=utf-8';
        $content_encoding = null;
        if ($this->config['enable_compression']) {
            $html = gzencode($html, 6);
            $content_encoding = 'gzip';
        }

        // get_post_storage_path() 已返回含 index.html 的完整文件路径（如 made/index.html）
        $r2_path = $this->get_post_storage_path($post);

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

        $this->sync_media_files($post->post_content);

        $written = false;
        for ($i = 0; $i < $this->config['max_retries']; $i++) {
            if ($this->write_to_d1($post_data, $categories, $tags)) {
                $written = true;
                break;
            }
            $this->log("D1写入重试 " . ($i + 1) . "/{$this->config['max_retries']}", 'WARNING');
            usleep(500000);
        }
        if (!$written) throw new Exception('D1写入失败');

        // 导出成功即按设置页开关清除相关缓存（sidebar 默认清；列表页第一页默认关，靠缓存自然过期）
        $this->purge_page_caches();

        return true;
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
        try {
            wp_remote_post($site_url . '/purge-cache', array(
                'timeout'    => 5,
                'sslverify'  => false,
                'headers'    => array('X-Auth-Key' => $auth, 'Content-Type' => 'application/json'),
                'body'       => wp_json_encode(array('keys' => $keys)),
            ));
            $this->log('已请求清除页面缓存 ' . count($keys) . ' 个 key', 'DEBUG');
        } catch (Throwable $e) {
            $this->log('清除页面缓存失败: ' . $e->getMessage(), 'WARNING');
        }
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
        $post_url = add_query_arg('_cfse_internal', '1', get_permalink($post));
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
        if ($this->config['clean_scripts']) {
            $html = preg_replace('/<script[^>]*src="[^"]*wp-includes\/js\/[^"]*"[^>]*><\/script>/', '', $html);
            $html = preg_replace('/<script[^>]*src="[^"]*wp-content\/plugins\/[^"]*"[^>]*><\/script>/', '', $html);
            $html = preg_replace('/<div[^>]*id="comments"[^>]*>.*?<\/div>/s', '', $html);
            $html = preg_replace('/<script[^>]*wp-emoji[^>]*><\/script>/', '', $html);
        }
        $cache_ttl = $this->config['cache_ttl'];
        $html = str_replace('</head>', '<meta http-equiv="Cache-Control" content="public, max-age=' . (int) $cache_ttl . '"></head>', $html);
        $html = str_replace('</head>', '<meta name="x-static-export" content="true"></head>', $html);
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

    private function upload_to_r2_via_worker($path, $content, $content_type = 'text/html', $content_encoding = null) {
        try {
            if ($this->config['debug_mode']) {
                $debug_path = CFSE_DEBUG_DIR . '/' . $path;
                $debug_dir = dirname($debug_path);
                if (!is_dir($debug_dir)) @mkdir($debug_dir, 0755, true);
                @file_put_contents($debug_path, $content);
                $this->log("调试文件已保存: {$debug_path} (" . strlen($content) . " 字节)", 'DEBUG');
            }
            $worker_url = $this->config['site_url'] . '/upload';
            $auth_key = $this->config['auth_key'];
            if (empty($auth_key)) {
                $this->log('Worker Auth Key 未配置', 'ERROR');
                return false;
            }
            $body = array('path' => $path, 'content' => base64_encode($content), 'contentType' => $content_type, 'md5' => md5($content));
            if (!empty($content_encoding)) $body['contentEncoding'] = $content_encoding;
            $response = wp_remote_post($worker_url, array('timeout' => 30, 'headers' => array('X-Auth-Key' => $auth_key, 'Content-Type' => 'application/json'), 'body' => wp_json_encode($body)));
            if (is_wp_error($response)) {
                $this->log("Worker 上传请求失败: " . $response->get_error_message(), 'ERROR');
                return false;
            }
            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code !== 200) {
                $resp_body = wp_remote_retrieve_body($response);
                $this->log("Worker 上传失败，HTTP {$http_code}: {$resp_body}", 'ERROR');
                return false;
            }
            $up_resp = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($up_resp['skipped'])) {
                $this->log("Worker 上传跳过（R2 内容一致）: {$path}", 'DEBUG');
            } else {
                $this->log("Worker 上传成功: {$path}", 'INFO');
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
            'sql' => "INSERT OR REPLACE INTO posts (wp_id, slug, title, content, content_format, excerpt, date, modified, status, categories, tags, featured_image)
                      VALUES (?, ?, ?, ?, 'html', ?, ?, ?, 'published', ?, ?, ?)",
            'params' => array(
                $post_data['wp_id'],
                $post_data['slug'],
                $post_data['title'],
                $post_data['content'],
                $post_data['excerpt'],
                $post_data['date'],
                $post_data['modified'],
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
    private function d1_batch($statements) {
        $statements = array_values($statements);
        if (empty($statements)) return array();

        $site_url = isset($this->config['site_url']) ? rtrim($this->config['site_url'], '/') : '';
        $auth = isset($this->config['auth_key']) ? $this->config['auth_key'] : '';
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
        // R2：一次请求删除正文与 .gz（Worker /delete 支持 paths 数组）
        $this->delete_from_r2(array($r2_path, $r2_path . '.gz'));

        // 确保表结构存在后清理（防止表缺失时删除逻辑报错）
        $this->ensure_d1_schema();
        // D1：三条清理语句一次批量提交
        $this->d1_batch(array(
            array('sql' => 'DELETE FROM post_taxonomy WHERE post_id = ?', 'params' => array($post_id)),
            array('sql' => 'DELETE FROM posts WHERE wp_id = ?', 'params' => array($post_id)),
            array('sql' => 'DELETE FROM taxonomies WHERE slug NOT IN (SELECT DISTINCT taxonomy_slug FROM post_taxonomy)', 'params' => array()),
        ));

        $this->log("文章 {$post_id} 已删除", 'INFO');
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
            $worker_url = $this->config['site_url'] . '/delete';
            $auth_key = $this->config['auth_key'];
            if (empty($auth_key)) {
                $this->log('Worker Auth Key 未配置，无法删除 ' . implode(', ', $paths), 'ERROR');
                return false;
            }
            $response = wp_remote_request($worker_url, array('method' => 'DELETE', 'timeout' => 30, 'headers' => array('X-Auth-Key' => $auth_key, 'Content-Type' => 'application/json'), 'body' => wp_json_encode(array('paths' => $paths))));
            if (is_wp_error($response)) {
                $this->log("Worker 删除请求失败: " . $response->get_error_message(), 'ERROR');
                return false;
            }
            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code >= 400) {
                $resp_body = wp_remote_retrieve_body($response);
                $this->log("Worker 删除失败，HTTP {$http_code}: {$resp_body}", 'ERROR');
                return false;
            }
            $this->log("Worker 删除成功: " . implode(', ', $paths), 'INFO');
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
        $posts = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'meta_query' => array(array('key' => '_cf_exported', 'compare' => 'NOT EXISTS')), 'posts_per_page' => $this->config['batch_size']));
        foreach ($posts as $post) {
            if ($this->export_post($post->ID)) update_post_meta($post->ID, '_cf_exported', time());
        }
        $this->log("自动导出完成，处理了 " . count($posts) . " 篇文章", 'INFO');
    }

    public function add_export_column($columns) { $columns['cf_exported'] = '☁️ 已导出'; return $columns; }
    public function render_export_column($column, $post_id) {
        if ($column !== 'cf_exported') return;
        echo get_post_meta($post_id, '_cf_exported', true) ? '✅ 已导出' : '⏳ 待导出';
    }

    public function add_manual_export_button() {
        global $post;
        if (!$post || !isset($post->post_status) || $post->post_status !== 'publish' || !current_user_can('manage_options')) return;
        echo '<div class="misc-pub-section"><a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=cf_manual_export&post_id=' . $post->ID), 'cf_manual_export')) . '" class="button button-secondary">☁️ 重新导出到Cloudflare</a></div>';
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
        $all = array();
        $offset = 0;
        $chunk = 200;
        do {
            $ids = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $chunk, 'offset' => $offset, 'fields' => 'ids'));
            if (empty($ids)) break;
            $all = array_merge($all, $ids);
            $offset += $chunk;
        } while (count($ids) === $chunk);

        $this->log('全量导出开始，共 ' . count($all) . ' 篇，并发 ' . $this->config['export_concurrency'], 'INFO');
        list($success, $total) = $this->export_posts_concurrent($all, $this->config['export_concurrency']);
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
    private function export_posts_concurrent($post_ids, $workers = 4) {
        $post_ids = array_values(array_unique(array_map('intval', (array) $post_ids)));
        $post_ids = array_values(array_filter($post_ids, function ($id) { return $id > 0; }));
        $total = count($post_ids);
        if ($total === 0) return array(0, 0);

        $workers = max(1, min((int) $workers, 8));
        $script = CFSE_PLUGIN_DIR . 'tools/bulk-export-worker.php';
        if (!function_exists('exec') || $total <= 1 || !file_exists($script)) {
            $ok = 0;
            foreach ($post_ids as $id) { if ($this->export_post($id)) $ok++; }
            return array($ok, $total);
        }

        // 传给 CLI 子进程的原站地址：CLI 里没有 HTTP 上下文，若 wp-config.php 用
        // $_SERVER['HTTP_HOST'] 计算 WP_HOME，home_url()/get_permalink() 会退化成
        // http://localhost/，导致前台抓取连接失败、HTML 里 URL 替换失效。
        $worker_site = home_url('/');
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
            $cmd = escapeshellarg(PHP_BINARY)
                . ' ' . escapeshellarg($script)
                . ' ' . escapeshellarg('--site=' . $worker_site)
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
                if ($this->export_post($id)) $ok++;
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