<?php
/**
 * CF Static Export Pro — 风格包内部渲染器（CLI）
 *
 * 由 WP 后台的风格生成流程调用，**每种页面一个进程**：
 *   php skin-render.php --site=http://192.168.2.2 --type=home     --probe=123 --out=/tmp/a.html
 *   php skin-render.php --site=http://192.168.2.2 --type=category --probe=123 --out=/tmp/b.html
 *
 * 为什么必须每类型一个进程：
 *   1. WordPress 的 load_template() 的 $load_once 默认 true，get_header()/get_footer()/
 *      get_sidebar() 内部也传 true（require_once 语义，记录是 per-PHP-进程的）——同一进程内
 *      第二次渲染同一模板不会执行，只会得到空/缺 header 的残片。
 *   2. 新进程里没有登录用户，不会把 admin bar 的 CSS/JS 带进模板。
 *
 * 退出码：0 成功；1 参数/环境错误；2 渲染结果里没找到探针（缓存或置顶干扰）。
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$opt = array('site' => '', 'type' => 'home', 'probe' => '0', 'out' => '');
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') !== 0) continue;
    $eq = strpos($arg, '=');
    if ($eq === false) continue;
    $k = substr($arg, 2, $eq - 2);
    if (array_key_exists($k, $opt)) $opt[$k] = substr($arg, $eq + 1);
}
$type  = $opt['type'];
$probe = (int) $opt['probe'];
$out   = $opt['out'];
if ($probe <= 0 || $out === '' || !in_array($type, array('home', 'category', 'tag', 'archive', 'single'), true)) {
    fwrite(STDERR, "usage: php skin-render.php --site=<url> --type=<home|category|tag|archive|single> --probe=<id> --out=<file>\n");
    exit(1);
}

// ---- 站点上下文（必须在 require wp-load.php 之前设置）----
// CLI 没有 HTTP 上下文：若 wp-config.php 用 $_SERVER['HTTP_HOST'] 计算 WP_HOME，
// home_url()/get_permalink() 会退化成 http://localhost/，模板里的 URL 就全错了。
$site = $opt['site'];
if ($site !== '') {
    $sp = @parse_url($site);
    if (!empty($sp['host'])) {
        $scheme = isset($sp['scheme']) ? strtolower($sp['scheme']) : 'http';
        $_SERVER['HTTP_HOST'] = $sp['host'] . (empty($sp['port']) ? '' : ':' . $sp['port']);
        $_SERVER['SERVER_NAME'] = $sp['host'];
        $_SERVER['SERVER_PORT'] = empty($sp['port']) ? ($scheme === 'https' ? '443' : '80') : (string) $sp['port'];
        if ($scheme === 'https') { $_SERVER['HTTPS'] = 'on'; }
    }
}
if (empty($_SERVER['REQUEST_URI'])) { $_SERVER['REQUEST_URI'] = '/'; }
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

// ---- 定位 wp-load.php（向上探测 2~7 层）----
$wp_load = '';
$dir = __DIR__;
for ($i = 0; $i < 7; $i++) {
    $candidate = $dir . '/wp-load.php';
    if (file_exists($candidate)) { $wp_load = $candidate; break; }
    $dir = dirname($dir);
}
if ($wp_load === '') {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(1);
}
require $wp_load;

// 渲染结果会直接当模板用：任何 PHP 提示都不能混进 HTML
@ini_set('display_errors', '0');
if (function_exists('wp_debug_mode')) { }

if (!function_exists('locate_template')) {
    fwrite(STDERR, "theme API unavailable\n");
    exit(1);
}

// ---- 伪造页面上下文 ----
global $wp_query, $post, $more;
$tpl_names = array();
$flags = array();
$q = null;

switch ($type) {
    case 'home':
        $q = new WP_Query(array('posts_per_page' => max(1, (int) get_option('posts_per_page', 10))));
        $flags = array('is_home' => true, 'is_front_page' => true);
        $tpl_names = array('home.php', 'index.php');
        break;

    case 'category':
        $cats = wp_get_post_categories($probe);
        $tid = !empty($cats) ? (int) $cats[0] : 0;
        if (!$tid) { fwrite(STDERR, "probe has no category\n"); exit(1); }
        $term = get_term($tid);
        $q = new WP_Query(array('cat' => $tid, 'posts_per_page' => max(1, (int) get_option('posts_per_page', 10))));
        $flags = array('is_category' => true, 'is_archive' => true, 'queried_object' => $term, 'queried_object_id' => $tid);
        $tpl_names = array('category.php', 'archive.php', 'index.php');
        break;

    case 'tag':
        $tags = wp_get_post_tags($probe, array('fields' => 'ids'));
        $tid = !empty($tags) ? (int) $tags[0] : 0;
        if (!$tid) { fwrite(STDERR, "probe has no tag\n"); exit(1); }
        $term = get_term($tid);
        $q = new WP_Query(array('tag_id' => $tid, 'posts_per_page' => max(1, (int) get_option('posts_per_page', 10))));
        $flags = array('is_tag' => true, 'is_archive' => true, 'queried_object' => $term, 'queried_object_id' => $tid);
        $tpl_names = array('tag.php', 'archive.php', 'index.php');
        break;

    case 'archive':
        $p = get_post($probe);
        if (!$p) { fwrite(STDERR, "probe not found\n"); exit(1); }
        $y = (int) substr($p->post_date, 0, 4);
        $m = (int) substr($p->post_date, 5, 2);
        $q = new WP_Query(array('year' => $y, 'monthnum' => $m, 'posts_per_page' => max(1, (int) get_option('posts_per_page', 10))));
        $flags = array('is_date' => true, 'is_archive' => true, 'is_month' => true, 'is_year' => false);
        $tpl_names = array('date.php', 'archive.php', 'index.php');
        break;

    case 'single':
    default:
        $p = get_post($probe);
        if (!$p) { fwrite(STDERR, "probe not found\n"); exit(1); }
        setup_postdata($p);
        $q = new WP_Query(array('p' => $probe, 'post_type' => 'post'));
        $flags = array('is_single' => true, 'is_singular' => true, 'is_page' => false);
        $tpl_names = array('single.php', 'singular.php', 'index.php');
        break;
}

foreach ($flags as $k => $v) { $q->$k = $v; }
$wp_query = $q;
$GLOBALS['wp_the_query'] = $q;
// 全局 $post 也要设（真实前台请求里它是当前/第一篇文章，主题 header.php 会读它；
// 不设会产生 "Attempt to read property ... on null" 的 PHP Warning 并混进模板）
$post = !empty($q->posts) ? $q->posts[0] : null;
$more = 1;
wp_set_current_user(0);   // 确保没有登录用户（不输出 admin bar 的 CSS/JS）

$tpl = locate_template($tpl_names);
ob_start();
if ($tpl) {
    load_template($tpl, false);
}
$html = ob_get_clean();

$len = strlen($html);
if ($len < 1000 || strpos($html, '<') === false) {
    fwrite(STDERR, "render too small (" . $len . " bytes)\n");
    exit(1);
}

// 探针必须出现在页面里（否则说明它没进这个归档/首页，映射会错位）
$in_page = (strpos($html, 'id="post-' . $probe . '"') !== false)
    || (strpos($html, 'post-' . $probe) !== false)
    || ($type === 'single' && strpos($html, (string) get_the_title($probe)) !== false);

if (!$in_page) {
    fwrite(STDERR, "probe #" . $probe . " not found in rendered " . $type . " page\n");
    exit(2);
}

if (@file_put_contents($out, $html) === false) {
    fwrite(STDERR, "cannot write " . $out . "\n");
    exit(1);
}

echo 'OK type=' . $type
    . ' tpl=' . ($tpl ? basename($tpl) : 'none')
    . ' len=' . $len
    . ' probe_in_page=' . ($in_page ? 'y' : 'n')
    . "\n";
exit(0);
