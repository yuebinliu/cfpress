<?php
/**
 * 一次性数据恢复脚本（2026-10-25 事故恢复）—— v2
 *
 * 背景：验证 d1-batch 白名单时，误把 `DROP TABLE posts` 当测试用例实际执行了，
 *       导致线上 D1 的 posts 表被清空。
 *
 * v1 的问题：只用 WP 的 post ID 当 D1 主键，漏掉了「Worker 端诞生」的文章 ——
 *   那些文章在 WP 库里有对应 post（postmeta `_cfsh_remote_wp_id` 记录了 D1 主键），
 *   但 D1 的主键是**时间戳量级**（`Date.now() * 100`），不是 WP post ID。
 *   ⇒ 恢复后 post_taxonomy 里 59 条 Worker 端关联全部指向不存在的文章，
 *     分类/标签计数变 0（"使用指南" guide 分类文章数为 0 就是这个原因）。
 *
 * 这个脚本（v2）：
 *   1) 读 WP 的 `_cfsh_remote_wp_id` / `_cfse_remote_wp_id` 映射
 *   2) 对这些文章，用**映射里的远端 ID** 当 D1 主键写入（而不是 WP post ID）
 *   3) 其余文章用 WP post ID 写入
 *
 * 用法（服务器容器内）：docker exec web-php php .../restore-posts-d1.php
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// ---- 定位 wp-load.php ----
$wp_load = '';
$dir = __DIR__;
for ($i = 0; $i < 7; $i++) {
    $candidate = $dir . '/wp-load.php';
    if (file_exists($candidate)) { $wp_load = $candidate; break; }
    $dir = dirname($dir);
}
if ($wp_load === '') { fwrite(STDERR, "wp-load.php not found\n"); exit(1); }

if (!isset($_SERVER['HTTP_HOST'])) $_SERVER['HTTP_HOST'] = '192.168.2.2';
if (!isset($_SERVER['REQUEST_URI'])) $_SERVER['REQUEST_URI'] = '/';
if (!isset($_SERVER['SERVER_NAME'])) $_SERVER['SERVER_NAME'] = '192.168.2.2';

require_once $wp_load;

$account_id = (string) get_option('cf_account_id', '');
$api_token  = (string) get_option('cf_api_token', '');
$db_id      = (string) get_option('cf_d1_database', '');
if ($account_id === '' || $api_token === '' || $db_id === '') {
    fwrite(STDERR, "凭据缺失\n");
    exit(1);
}

function d1_exec($account_id, $db_id, $api_token, $sql, $params) {
    $url = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/d1/database/{$db_id}/query";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('sql' => $sql, 'params' => $params)));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $api_token, 'Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http >= 400) return array(false, "HTTP {$http}");
    $d = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($d['success'])) {
        return array(false, 'API error: ' . substr((string) $response, 0, 400));
    }
    return array(true, null);
}

function post_terms_json($post_id, $taxonomy) {
    $out = array();
    $terms = get_the_terms($post_id, $taxonomy);
    if (!is_array($terms)) return '[]';
    foreach ($terms as $t) $out[] = array('name' => $t->name, 'slug' => $t->slug);
    return wp_json_encode($out, JSON_UNESCAPED_UNICODE);
}

function post_content_format($post_id) {
    $v = get_post_meta($post_id, 'content_format', true);
    return ($v === 'md') ? 'md' : 'html';
}

function post_excerpt_of($post) {
    $src = !empty($post->post_excerpt) ? $post->post_excerpt : $post->post_content;
    $text = preg_replace('/<[^>]*>/', ' ', $src);
    $text = preg_replace('/\s+/u', ' ', strip_tags($text));
    $text = trim($text);
    if (mb_strlen($text, 'UTF-8') > 200) $text = mb_substr($text, 0, 200, 'UTF-8');
    return $text;
}

/**
 * 取文章在 D1 里的主键：
 *   优先用 meta 记录的远端 ID（Worker 端诞生的文章），
 *   没有就用 WP post ID。
 */
function d1_primary_id($post) {
    foreach (array('_cfsh_remote_wp_id', '_cfse_remote_wp_id') as $mk) {
        $v = get_post_meta($post->ID, $mk, true);
        if ($v !== '' && $v !== null && (int) $v > 0) return (int) $v;
    }
    return (int) $post->ID;
}

// ---- 取全部已发布文章 ----
$posts = get_posts(array(
    'post_type' => 'post', 'post_status' => 'publish',
    'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC',
));
$total = count($posts);
echo "待恢复 $total 篇\n";

$sql = "INSERT INTO posts (wp_id, slug, title, content, content_format, excerpt, date, modified, status, comment_status, categories, tags, featured_image, lang, translation_key)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(wp_id) DO UPDATE SET slug=excluded.slug, title=excluded.title, content=excluded.content,
        content_format=excluded.content_format, excerpt=excluded.excerpt, date=excluded.date, modified=excluded.modified,
        status=excluded.status, comment_status=excluded.comment_status, categories=excluded.categories,
        tags=excluded.tags, featured_image=excluded.featured_image,
        lang=excluded.lang, translation_key=excluded.translation_key";

$ok = 0; $fail = 0; $errors = array(); $remote = 0;

foreach ($posts as $p) {
    $pid = d1_primary_id($p);
    if ($pid !== (int) $p->ID) $remote++;
    $format = post_content_format($p->ID);
    $params = array(
        $pid,
        $p->post_name,
        $p->post_title,
        $p->post_content,
        $format,
        post_excerpt_of($p),
        $p->post_date,
        $p->post_modified,
        ($p->post_status === 'draft' ? 'draft' : 'published'),
        ($p->comment_status === 'open' ? 'open' : 'closed'),
        post_terms_json($p->ID, 'category'),
        post_terms_json($p->ID, 'post_tag'),
        (string) get_the_post_thumbnail_url($p->ID, 'full'),
        'zh',                      // lang：迁移脚本的默认值
        $p->post_name,             // translation_key：迁移脚本用自身 slug 作分组键
    );
    // ⚠️ 先删同 slug 的旧记录：v1 恢复时用 WP post ID 写进去过，
    //    而 slug 是 UNIQUE —— 直接插会撞约束（UNIQUE constraint failed: posts.slug）。
    //    这里按 slug 删（不管旧主键是 WP ID 还是远端 ID），再插正确的。
    list($dr, $derr) = d1_exec($account_id, $db_id, $api_token,
        "DELETE FROM posts WHERE slug = ? AND wp_id != ?",
        array($p->post_name, $pid));
    if (!$dr) {
        $fail++;
        if (count($errors) < 10) $errors[] = '#' . $p->ID . ' DELETE 失败: ' . $derr;
        continue;
    }
    list($r, $err) = d1_exec($account_id, $db_id, $api_token, $sql, $params);
    if ($r) { $ok++; } else {
        $fail++;
        if (count($errors) < 10) $errors[] = '#' . $p->ID . ' (' . $p->post_name . '): ' . $err;
    }
    if ($ok % 50 === 0) echo "  已写入 $ok / $total\n";
}

echo "\n完成：成功 $ok / 失败 $fail（共 $total，其中 $remote 篇用远端 ID 作主键）\n";
if (!empty($errors)) {
    echo "错误样例：\n";
    foreach ($errors as $e) echo "  $e\n";
}
exit($fail > 0 ? 1 : 0);
