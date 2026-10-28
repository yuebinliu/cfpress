<?php
/**
 * CF Static Export Pro - uninstall
 *
 * 卸载时仅清理 WordPress 本地的 option / 文章 meta / cron。
 * ⚠️ 不会删除 Cloudflare R2 / D1 / Worker —— 如需删除请在 Cloudflare 控制台手动操作。
 * 提示：卸载后再重装插件会丢失此前的连接配置与模板，请先导出备份。
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$cfse_options = array(
    'cf_account_id',
    'cf_api_token',
    'cf_r2_bucket',
    'cf_d1_database',
    'cf_site_url',
    'cf_export_mode',
    'cf_cache_ttl',
    'cf_clean_dynamic_scripts',
    'cf_batch_size',
    'cf_max_retries',
    'cf_enable_logging',
    'cf_enable_compression',
    'cf_debug_mode',
    'cf_auth_key',
    'cf_posts_per_page',
    'cfse_custom_templates',
    'cfse_theme_analysis',
    'cf_exported_count',
    'cf_last_export_time',
    'cfse_db_version',
);

foreach ($cfse_options as $opt) {
    delete_option($opt);
}

// 清理文章上的导出标记 meta
delete_metadata('post', 0, '_cf_exported', '', true);

// 清理定时任务
wp_clear_scheduled_hook('cf_auto_export_cron');
