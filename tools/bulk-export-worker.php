<?php
/**
 * CF Static Export Pro - 并发批量导出子进程 worker
 *
 * 由主进程（handle_bulk_export）以多进程方式并行启动：
 *   php bulk-export-worker.php 123,124,125 456,789
 * （所有非 flag 参数按逗号拆分，只接受正整数文章 ID）
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
    try {
        if ($plugin->export_post($id)) {
            $ok++;
        } else {
            $fail[] = $id;
        }
    } catch (Throwable $e) {
        $fail[] = $id;
    }
}

echo 'OK:' . $ok . ' FAIL:' . implode(',', $fail) . "\n";
exit(empty($fail) ? 0 : 2);
