<?php
/**
 * CF Static Export Pro - 并发批量导出子进程 worker
 *
 * 由主进程（handle_bulk_export / handle_generate_local / handle_publish_remote）以多进程方式并行启动：
 *   php bulk-export-worker.php --mode=auto 123,124,125 456,789
 * （所有非 flag 参数按逗号拆分，只接受正整数文章 ID）
 *
 * --mode 三态（默认 auto）：
 *   auto      生成 HTML + 上传 R2 + 写 D1（默认链路，与拆分前一致）
 *   generate  只渲染并落盘到 WP_CONTENT_DIR/cfse-static/（不联网，纯静态托管用）
 *   publish   读取本地已生成的 HTML → 上传 R2 + 写 D1（不上传前必须先生成）
 *
 * 每个 worker 独立加载 WordPress 并串行导出自己分到的文章；
 * R2 上传（不同路径）、D1 upsert（幂等）均支持多进程并发安全。
 * 输出一行摘要：OK:<成功数> FAIL:<失败ID逗号分隔>
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

// ---- 定位 wp-load.php（向上探测 2~7 层）----
$wp_load = '';
$dir = __DIR__;
for ($i = 0; $i < 7; $i++) {
    $candidate = $dir . '/wp-load.php';
    if (file_exists($candidate)) {
        $wp_load = $candidate;
        break;
    }
    $dir = dirname($dir);
}
if ($wp_load === '') {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(1);
}

// ---- 站点上下文（必须在 require wp-load.php 之前设置）----
// CLI 没有 HTTP 上下文：若 wp-config.php 用 $_SERVER['HTTP_HOST'] 计算 WP_HOME，
// home_url()/get_permalink() 会退化成 http://localhost/ —— 前台抓取必然连接失败，
// 输出 HTML 里的 URL 替换（home_url() -> 静态站）也会失效。主进程用 --site=<原站URL> 传入。
$cfse_site_arg = '';
$cfse_mode = 'auto';
foreach (array_slice($argv, 1) as $cfse_arg) {
    if (strpos($cfse_arg, '--site=') === 0) {
        $cfse_site_arg = substr($cfse_arg, 7);
    } elseif (strpos($cfse_arg, '--mode=') === 0) {
        $cfse_mode = substr($cfse_arg, 7);
    }
}
if (!in_array($cfse_mode, array('auto', 'generate', 'publish', 'manual'), true)) {
    $cfse_mode = 'auto';
}
if ($cfse_site_arg !== '') {
    $cfse_parts = @parse_url($cfse_site_arg);
    if (!empty($cfse_parts['host'])) {
        $cfse_scheme = isset($cfse_parts['scheme']) ? strtolower($cfse_parts['scheme']) : 'http';
        $_SERVER['HTTP_HOST'] = $cfse_parts['host'] . (empty($cfse_parts['port']) ? '' : ':' . $cfse_parts['port']);
        $_SERVER['SERVER_NAME'] = $cfse_parts['host'];
        $_SERVER['SERVER_PORT'] = empty($cfse_parts['port']) ? ($cfse_scheme === 'https' ? '443' : '80') : (string) $cfse_parts['port'];
        if ($cfse_scheme === 'https') {
            $_SERVER['HTTPS'] = 'on';
        }
    }
}
if (empty($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '/';
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

require $wp_load;

if (!class_exists('CF_Static_Export_Pro')) {
    fwrite(STDERR, "CF_Static_Export_Pro not loaded\n");
    exit(1);
}

// ---- 解析文章 ID（仅正整数，防注入）----
$ids = array();
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '') continue;
    foreach (explode(',', $arg) as $x) {
        $x = trim($x);
        if ($x !== '' && ctype_digit($x)) {
            $ids[] = (int) $x;
        }
    }
}
$ids = array_values(array_unique($ids));
if (empty($ids)) {
    fwrite(STDERR, "no valid post ids\n");
    exit(1);
}

// ---- 逐篇导出 ----
$plugin = CF_Static_Export_Pro::get_instance();
$ok = 0;
$fail = array();
foreach ($ids as $id) {
    $cfse_ok = false;
    try {
        if ($cfse_mode === 'generate') {
            $cfse_ok = $plugin->generate_post_local($id);
        } elseif ($cfse_mode === 'publish') {
            $cfse_ok = $plugin->publish_post_local($id);
        } elseif ($cfse_mode === 'manual') {
            $cfse_ok = $plugin->export_post_manual($id);
        } else {
            $cfse_ok = $plugin->export_post($id);
        }
    } catch (Throwable $e) {
        $cfse_ok = false;
    }
    if ($cfse_ok) {
        $ok++;
    } else {
        $fail[] = $id;
    }
}

echo 'OK:' . $ok . ' FAIL:' . implode(',', $fail) . ' MODE:' . $cfse_mode . "\n";
exit(empty($fail) ? 0 : 2);
