<?php
/**
 * FSE（块主题）风格提取器 —— 读主题文件直接产出 tpl.json（无需渲染/探针）。
 *
 * 用途：对 FSE/块主题（有 theme.json + templates/ + parts/）自动提取模板骨架。
 * 与经典主题的"内部渲染 + token 值锚"路径互补；产物统一为 tpl.json 结构，下游 worker 渲染端零改动。
 *
 * 原理：
 *   - 读 templates/*.html（页面骨架）+ parts/*.html（头部/页脚/侧边栏）+ patterns/*（模式引用）
 *   - 块标记（<!-- wp:xxx -->）→ 占位符的确定性映射（栈式递归解析嵌套）
 *   - wp:navigation → 用 wp_get_nav_menu_items() 渲染成完整菜单 HTML（静态，后续再做 CMS 管理）
 *   - social-links / social-link → 保留原文（具体链接，静态）
 *   - 未覆盖块 → 原样保留（不破坏），并记入 uncovered
 *
 * 用法（CLI）：
 *   php tools/fse-skin-extract.php [--theme=slug] [--out=/path/to/tpl.json]
 *   不带 --out 时打印摘要；带 --out 时写盘（JSON）。
 */
if (PHP_SAPI !== 'cli') { exit(1); }

$opt = array('theme' => '', 'out' => '', 'site' => '', 'switched' => '', 'assets' => '');
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--') !== 0) continue;
    $eq = strpos($arg, '=');
    if ($eq === false) continue;
    $k = substr($arg, 2, $eq - 2);
    if (array_key_exists($k, $opt)) $opt[$k] = substr($arg, $eq + 1);
}

// ---- 站点上下文（require wp-load 之前）----
// CLI 下没有 HTTP_HOST，wp-config 按 $_SERVER['HTTP_HOST'] 算 WP_HOME ⇒ home_url() 退化
// 成 http://localhost，菜单/分类等链接会写成 localhost 死链。--site 就是兜这个的。
$site = (string) $opt['site'];
if ($site !== '') {
    $sp = @parse_url($site);
    if (!empty($sp['host'])) {
        $_SERVER['HTTP_HOST'] = $sp['host'] . (empty($sp['port']) ? '' : ':' . $sp['port']);
        $_SERVER['SERVER_NAME'] = $sp['host'];
        $_SERVER['SERVER_PORT'] = empty($sp['port']) ? ((!empty($sp['scheme']) && $sp['scheme'] === 'https') ? 443 : 80) : (string) $sp['port'];
        if (!empty($sp['scheme']) && $sp['scheme'] === 'https') { $_SERVER['HTTPS'] = 'on'; }
    }
}
if (empty($_SERVER['REQUEST_URI'])) { $_SERVER['REQUEST_URI'] = '/'; }
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

$wp_load = '';
$dir = __DIR__;
for ($i = 0; $i < 7; $i++) {
    if (file_exists($dir . '/wp-load.php')) { $wp_load = $dir . '/wp-load.php'; break; }
    $dir = dirname($dir);
}
if ($wp_load === '') { fwrite(STDERR, "wp-load.php not found\n"); exit(1); }
require $wp_load;

// ============ 核心 ============

// ---- 复用经典链路的「条目骨架」提取（v2 对照驱动，与 skin-builder.php **同一份实现**）----
// 用于「全站分类 / 标签云 / 归档」列表块：块的外壳（<ul>…</ul> / <p>…</p>）留在模板里，
// 骨架只取「一级条目」（<li> 或 <a>），由 Worker 的 cfseSidebar* 结合 D1 数据逐条再生。
$fse_sb = __DIR__ . '/../views/skin-builder.php';
if (file_exists($fse_sb)) {
    require_once $fse_sb;
} else {
    // 找不到时**显式告警**（否则 sidebar 骨架会静默丢失，只剩 Worker 默认 <li>）
    fwrite(STDERR, "WARN: 未找到 {$fse_sb} —— 列表类骨架（categories/tags/archives）将缺失\n");
}

/**
 * 为列表类块提取「条目级骨架」→ $GLOBALS['fse_sidebar'][$key]（最终写进 tpl.json 的 sidebar 段）。
 * 复用经典链路 v2：cfse_skin_extract_units()（自动认"重复 ≥2 次"的单元标签）
 *                + cfse_skin_units_skeleton()（多单元对照：任一单元不同 ⇒ 该位是 per-item 数据）。
 * 拿不到骨架就什么都不做 —— Worker 端会退化成默认 <li>（对 categories/archives 恰好可用）。
 *
 * @param string $name  块名（categories / tag-cloud / archives）
 * @param array  $attrs 块属性
 * @param string $key   sidebar 段里的键（categories / tags / archives）
 */
function fse_sidebar_skeleton($name, $attrs, $key) {
    if (! function_exists('cfse_skin_extract_units')) {
        static $fse_sb_warned = false;
        if (! $fse_sb_warned) { $fse_sb_warned = true; fwrite(STDERR, "WARN: cfse_skin_extract_units 不可用 —— 跳过列表类骨架提取（仅剩 Worker 默认 <li>）\n"); }
        return;
    }
    $html = fse_render_block($name, $attrs);
    if ($html === '') return;

    // 条目区：优先 <ul> 内容（categories / archives）；否则 <p> 内容（tag-cloud 是 <p>+<a>）
    $content = $html;
    foreach (array('ul', 'p') as $tag) {
        if (! preg_match('~<' . $tag . '\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) continue;
        $gt = strpos($html, '>', $m[0][1]);
        if ($gt === false) continue;
        $close = cfse_html_find_close($html, $gt + 1, $tag);
        if ($close) { $content = substr($html, $gt + 1, $close['close_start'] - $gt - 1); break; }
    }

    $units = cfse_skin_extract_units($content, 4);
    if (empty($units)) return;
    $skel = cfse_skin_units_skeleton($units, array());
    if ($skel === '') return;

    if (! isset($GLOBALS['fse_sidebar']) || ! is_array($GLOBALS['fse_sidebar'])) $GLOBALS['fse_sidebar'] = array();
    $GLOBALS['fse_sidebar'][$key] = $skel;
}

function fse_read($p) { return (string) @file_get_contents($p); }

function fse_attrs($str) {
    $out = array();
    if (preg_match('/\{(.*)\}/s', $str, $m)) {
        $j = json_decode('{' . $m[1] . '}', true);
        if (is_array($j)) $out = $j;
    }
    return $out;
}

function fse_tokens($html) {
    $t = array();
    if (preg_match_all('/<!--\s*wp:([a-z0-9-]+)(\s+\{.*?\})?\s*(\/)?-->/s', $html, $ms, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            $self = isset($m[3][0]) && $m[3][0] === '/';
            $t[] = array('pos' => $m[0][1], 'type' => $self ? 'self' : 'open',
                'name' => $m[1][0], 'attrs' => isset($m[2][0]) ? $m[2][0] : '', 'len' => strlen($m[0][0]));
        }
    }
    if (preg_match_all('/<!--\s*\/wp:([a-z0-9-]+)\s*-->/s', $html, $ms2, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($ms2 as $m) {
            $t[] = array('pos' => $m[0][1], 'type' => 'close', 'name' => $m[1][0], 'attrs' => '', 'len' => strlen($m[0][0]));
        }
    }
    usort($t, function ($a, $b) { return $a['pos'] - $b['pos']; });
    return $t;
}

function fse_blocks($tokens) {
    $stack = array(); $blocks = array();
    foreach ($tokens as $t) {
        if ($t['type'] === 'self') {
            $blocks[] = array('name' => $t['name'], 'attrs' => $t['attrs'],
                'start' => $t['pos'], 'innerStart' => -1, 'innerEnd' => -1, 'end' => $t['pos'] + $t['len'], 'self' => true);
        } elseif ($t['type'] === 'open') {
            $stack[] = array('name' => $t['name'], 'attrs' => $t['attrs'], 'start' => $t['pos'], 'innerStart' => $t['pos'] + $t['len']);
        } else {
            for ($i = count($stack) - 1; $i >= 0; $i--) {
                if ($stack[$i]['name'] === $t['name']) {
                    $b = $stack[$i]; array_splice($stack, $i, 1);
                    $blocks[] = array('name' => $b['name'], 'attrs' => $b['attrs'], 'start' => $b['start'],
                        'innerStart' => $b['innerStart'], 'innerEnd' => $t['pos'], 'end' => $t['pos'] + $t['len'], 'self' => false);
                    break;
                }
            }
        }
    }
    usort($blocks, function ($a, $b) { return $a['start'] - $b['start']; });
    return $blocks;
}

/** 后台菜单 → 完整菜单 HTML（两级递归；无菜单时返回 {{MENU}} 占位符，后续 CMS 管理接管） */
function fse_menu_html($attrs) {
    $ref = isset($attrs['ref']) ? (int) $attrs['ref'] : 0;
    $items = array();
    if ($ref > 0) {
        $items = wp_get_nav_menu_items($ref);
    } else {
        $loc = get_nav_menu_locations();
        $primary = isset($loc['primary']) ? (int) $loc['primary'] : 0;
        if ($primary) $items = wp_get_nav_menu_items($primary);
    }
    if (empty($items)) return '{{MENU}}';
    $byParent = array();
    foreach ($items as $it) { $byParent[(int) $it->menu_item_parent][] = $it; }
    $render = function ($parent, $depth) use (&$render, $byParent) {
        if (empty($byParent[$parent])) return '';
        $h = '<ul class="menu' . ($depth > 0 ? ' sub-menu' : '') . '">';
        foreach ($byParent[$parent] as $it) {
            $cls = 'menu-item';
            if (!empty($byParent[(int) $it->ID])) $cls .= ' menu-item-has-children';
            $h .= '<li class="' . $cls . '"><a href="' . esc_url($it->url) . '">' . esc_html($it->title) . '</a>';
            $h .= $render((int) $it->ID, $depth + 1);
            $h .= '</li>';
        }
        return $h . '</ul>';
    };
    return $render(0, 0);
}

function fse_block_repl($b, $html) {
    $name = $b['name'];
    $attrs = fse_attrs($b['attrs']);
    $open = substr($html, $b['start'], ($b['self'] ? $b['end'] : $b['innerStart']) - $b['start']);
    $inner = $b['self'] ? '' : substr($html, $b['innerStart'], $b['innerEnd'] - $b['innerStart']);
    $close = $b['self'] ? '' : substr($html, $b['innerEnd'], $b['end'] - $b['innerEnd']);

    switch ($name) {
        // 条目级字段：包条件块，值空时整块隐藏（与经典链路一致；Worker 端 cfseIfBlock 消费）
        // ⚠️ 这些块核心渲染时会带外层包装（<h2 class="wp-block-post-title">、
        // <div class="wp-block-post-date"><time datetime="…">、<div class="entry-content wp-block-post-content …">…）
        // ⇒ 整块换成占位符会丢结构。改成：核心渲染拿结构 + 把值占位化。
        // isLink 形态：<a href="permalink">标题</a> ⇒ 保留 <a>，href 与文本分别占位化
        case 'post-title':   return fse_block_inner_token('post-title', $attrs, '{{POST_TITLE}}', '{{POST_URL}}');
        case 'post-date':    return fse_block_inner_token('post-date', $attrs, '{{POST_DATE}}');
        case 'post-excerpt': return fse_excerpt_token($attrs);
        case 'post-featured-image': return fse_block_inner_token('post-featured-image', $attrs, '{{#IF_THUMBNAIL}}{{POST_THUMBNAIL}}{{/IF_THUMBNAIL}}');
        // 核心的 post-author 整块是 <div class="wp-block-post-author">（含头像/名字/简介）；我们只有名字
        // ⇒ 至少保留包装与名字子块，别把它压成一段裸文本。
        case 'post-author': return '<div class="wp-block-post-author"><div class="wp-block-post-author__name">{{#IF_AUTHOR}}{{POST_AUTHOR}}{{/IF_AUTHOR}}</div></div>';
        case 'post-author-name': return fse_block_inner_token('post-author-name', $attrs, '{{#IF_AUTHOR}}{{POST_AUTHOR}}{{/IF_AUTHOR}}');   // 作者名（twentytwentyfour/five、blockwist…）
        // «阅读更多»：只把 href / 无障碍标题占位化，保留主题文案
        case 'read-more':    return fse_read_more_token($attrs);
        // ⚠️ 不能整块替换成占位符：核心渲染 site-title 会输出
        // <h1 class="wp-block-site-title"><a href="…" rel="home" aria-current="page">站点名</a></h1>，
        // 整块换成 {{SITE_NAME}} 会让前端只剩裸文本（丢 <h1>/<a>）。
        // 做法：核心渲染拿真实结构 + 把「值」换成占位符（不手工拼 class，免得与 attrs 不符）。
        case 'site-title':
            $__st = fse_render_block('site-title', $attrs);
            if ($__st === '') return '{{SITE_NAME}}';
            $__st = str_replace(home_url('/'), '{{SITE_URL}}/', $__st);
            return str_replace(get_bloginfo('name'), '{{SITE_NAME}}', $__st);
        case 'site-tagline':
            $__sl = fse_render_block('site-tagline', $attrs);
            if ($__sl === '') return '{{SITE_DESC}}';
            return str_replace(get_bloginfo('description'), '{{SITE_DESC}}', $__sl);
        case 'site-logo': return '{{#IF_LOGO}}{{SITE_LOGO}}{{/IF_LOGO}}';
        // 菜单交给 Worker/CMS：{{MENU}} 由 site:ctx.menu 渲染 —— 后台可编辑菜单项（分类/文章/自定义），
        // 不再静态渲染（静态化会让菜单变成抓取那一刻的死快照）。fse_menu_html() 保留但已不调用。
        case 'navigation': return '<nav class="wp-block-navigation">{{#IF_MENU}}{{MENU}}{{/IF_MENU}}</nav>';
        case 'navigation-link': return '{{#IF_MENU}}{{MENU}}{{/IF_MENU}}';   // 单条链接块极罕见（实测 35 个主题都没触发），按"这里放菜单"处理
        case 'query-title': return fse_block_inner_token('query-title', $attrs, '{{TITLE}}');
        // 核心的 search 块输出是 <form … class="wp-block-search__button-outside wp-block-search__text-button wp-block-search">
        // <label class="wp-block-search__label">Search</label><div class="wp-block-search__inside-wrapper">…</div></form>
        // —— 改写过的简版表单结构完全不同 ⇒ 核心渲染 + 只把 action 占位化。
        case 'search':
            $__se = fse_render_block('search', $attrs);
            if ($__se === '') {
                return '<form role="search" method="get" action="{{SEARCH_ACTION}}"><input type="search" name="s" /><button>搜索</button></form>';
            }
            return str_replace(home_url('/'), '{{SEARCH_ACTION}}', $__se);
        case 'template-part':
            // 核心渲染 wp:template-part 时会按 tagName 包一层（如 <header class="wp-block-template-part">…</header>）
            // ⇒ 展开时必须补上，否则 header/footer 的包裹标签整个丢失。
            $__slug = isset($attrs['slug']) ? (string) $attrs['slug'] : '';
            $__tag  = isset($attrs['tagName']) ? preg_replace('/[^a-z0-9]/i', '', (string) $attrs['tagName']) : '';
            $GLOBALS['fse_parts'][$__slug] = $__tag;
            return '{{PART:' . $__slug . '}}';
        case 'pattern': return '{{PATTERN:' . (isset($attrs['slug']) ? $attrs['slug'] : '') . '}}';
        case 'post-terms':
            $term = isset($attrs['term']) ? $attrs['term'] : '';
            return fse_block_inner_token('post-terms', $attrs, ($term === 'post_tag')
                ? '{{#IF_TAGS}}{{POST_TAGS}}{{/IF_TAGS}}'
                : '{{#IF_CATEGORIES}}{{POST_CATEGORIES}}{{/IF_CATEGORIES}}');
        // 全站分类 / 标签云 / 归档：**保留核心渲染的外壳**（<ul>…</ul> / <p>…</p>），
        // 内层交给 Worker 用 sidebar 骨架 + D1 数据逐条再生（cfseSidebarCategoryItems/ArchiveItems/TagItems）。
        // 骨架只取"一级条目"（<li> / <a>），外壳留在模板里 —— 与经典链路模板自包含 <ul> 的约定一致。
        case 'categories':
            fse_sidebar_skeleton('categories', $attrs, 'categories');
            return fse_block_inner_token('categories', $attrs, '{{SIDEBAR_CATEGORIES}}');
        case 'tag-cloud':
            fse_sidebar_skeleton('tag-cloud', $attrs, 'tags');
            return fse_block_inner_token('tag-cloud', $attrs, '{{SIDEBAR_TAGS}}');
        case 'archives':
            fse_sidebar_skeleton('archives', $attrs, 'archives');
            return fse_block_inner_token('archives', $attrs, '{{SIDEBAR_ARCHIVES}}');
        case 'post-content': return fse_block_inner_token('post-content', $attrs, '{{POST_CONTENT}}');
        // 核心渲染 comments 时会套 <div id="comments" class="wp-block-comments">…；包装保留，内容由我们的评论系统填
        case 'comments': return '<div id="comments" class="wp-block-comments">{{#IF_COMMENTS}}{{COMMENTS}}{{/IF_COMMENTS}}</div>';
        // 核心渲染 post-comments-form 会套 <div class="wp-block-post-comments-form">…（表单本身由我们的评论系统产出）
        case 'post-comments-form': return '<div class="wp-block-post-comments-form">{{#IF_COMMENTS}}{{COMMENTS}}{{/IF_COMMENTS}}</div>';
        case 'comments-query-loop': return '{{#IF_COMMENTS}}{{COMMENTS}}{{/IF_COMMENTS}}';   // raft 用 query-loop 渲染评论
        case 'query-pagination': return '<nav class="wp-block-query-pagination">{{PAGINATION_PREV}}{{PAGINATION_NEXT}}</nav>';
        case 'query-pagination-previous': return '{{PAGINATION_PREV}}';
        case 'query-pagination-next': return '{{PAGINATION_NEXT}}';
        case 'query-no-results': return '';
        // 社交链接：保留 <ul> 外壳，条目由 Worker 从 site:ctx.social 生成（后台可配）。
        // ⚠️ $open/$close 是**块注释**（不是 <ul>）—— 直接拼 "$open{{SOCIAL_LINKS}}$close"
        // 会把 <ul> 外壳整个丢掉（实测渲染出来没有 <ul>，只有一堆 li 凭空消失）。
        // 所以要从 $inner 里取回真正的开标签，并按标签名闭合。
        case 'social-links':
            if (preg_match('#^(\s*<([a-z]+)[^>]*>)#i', $inner, $m)) {
                return '{{#IF_SOCIAL}}' . $m[1] . '{{SOCIAL_LINKS}}</' . $m[2] . '>{{/IF_SOCIAL}}';
            }
            return '{{#IF_SOCIAL}}{{SOCIAL_LINKS}}{{/IF_SOCIAL}}';
        case 'social-link': return '';
        case 'group': case 'columns': case 'column': case 'buttons': case 'button':
        case 'paragraph': case 'heading': case 'list': case 'list-item': case 'quote':
        case 'image': case 'cover': case 'spacer': case 'separator':
        case 'table': case 'details': case 'row': case 'stack': case 'media-text':
        case 'post-navigation-link': case 'loginout': case 'page-list':
            // 容器块：核心渲染时会补 wp-container-* 类（layout support）并生成对应 CSS。
            // 文件层没有这些类 ⇒ 复用核心的同名 filter 回调补上，否则布局走样。
            // ⚠️ 必须喂**真实开标签**（$open 只是块注释，HTML_Tag_Processor 找不到标签会原样返回）。
            list($open_tag, $rest) = fse_first_open_tag($inner);
            $open_tag = fse_layout_support($name, $attrs, $open_tag);
            return $open . $open_tag . fse_render($rest) . $close;
        case 'query':
            // 核心渲染 query 时会套 <div class="wp-block-query">…</div>，内部是 <ul class="wp-block-post-template">。
            // 两者都是"渲染时才加的包装" ⇒ 必须补，否则列表页丢掉外层结构与布局（实测原来这两层全丢）。
            // 另外：若 query 内部没有 post-template，核心会把内容整体包成一个 post-template 再渲染。
            if (strpos($inner, 'wp:post-template') === false) {
                $GLOBALS['fse_item'] = '<li class="wp-block-post">' . fse_render($inner) . '</li>';
                return '<div class="wp-block-query"><ul class="wp-block-post-template">{{CONTENT}}</ul></div>';
            }
            return '<div class="wp-block-query">' . fse_render($inner) . '</div>';
        case 'post-template':
            // 核心会给每个条目套 <li class="wp-block-post">，整体套 <ul class="wp-block-post-template">
            // ⇒ item 骨架补上 <li>，占位处补上 <ul>（Worker 渲染时往 {{CONTENT}} 填 N 个 <li>）。
            $GLOBALS['fse_item'] = '<li class="wp-block-post">' . fse_render($inner) . '</li>';
            return '<ul class="wp-block-post-template">{{CONTENT}}</ul>';
        case 'latest-posts':
            fse_render($inner);
            return '{{CONTENT}}';
        default:
            $GLOBALS['fse_uncovered'][$name] = (isset($GLOBALS['fse_uncovered'][$name]) ? $GLOBALS['fse_uncovered'][$name] : 0) + 1;
            return substr($html, $b['start'], $b['end'] - $b['start']);
    }
}

/**
 * 补核心在**渲染时**才会加上的容器类（wp-container-*，layout support 生成）。
 *
 * 文件层模板（templates/*.html）里没有这些类，但 head 里的 core-block-supports-inline-css
 * 依赖它们（gap / justify-content / align / "> *" 的 margin 重置都在那些规则里）⇒
 * 不补的话 DOM 上没有类、规则匹配不到，布局细节会走样。
 *
 * wp_render_layout_support_flag() 正是核心挂在 render_block 上的那个 filter 回调，直接复用；
 * 它没有 layout 属性时原样返回，安全。
 */
function fse_layout_support($name, $attrs, $open_tag) {
    if (! function_exists('wp_render_layout_support_flag') || $open_tag === '') return $open_tag;
    $block = array(
        'blockName'   => 'core/' . $name,
        'attrs'       => is_array($attrs) ? $attrs : array(),
        'innerBlocks' => array(),
        'innerHTML'   => '',
        'innerContent' => array(),
    );
    $out = wp_render_layout_support_flag($open_tag, $block);
    return is_string($out) ? $out : $open_tag;
}

/** 用核心渲染一个块 —— 拿它**真实的结构**（含核心渲染时才加的 class/包装）；attrs 原样传 */
function fse_render_block($name, $attrs) {
    if (! function_exists('render_block')) return '';
    try {
        $out = render_block(array(
            'blockName'   => 'core/' . $name,
            'attrs'       => is_array($attrs) ? $attrs : array(),
            'innerBlocks' => array(),
            'innerHTML'   => '',
            'innerContent' => array(),
        ));
    } catch (Throwable $e) { return ''; }
    return is_string($out) ? $out : '';
}

/**
 * 核心渲染「返回空」时的回退包装。
 * 空值在静态站很常见（站点没写描述、文章没特色图、探针上下文不是 archive…），
 * 这时核心直接 return ''，包装就丢了 ⇒ 这里按核心源码里的稳定形态补上。
 * （有数据时仍优先用核心渲染 ⇒ attrs 影响的细节不会丢。）
 */
function fse_block_wrap_fallback($name, $attrs, $inner) {
    $lvl = isset($attrs['level']) ? (int) $attrs['level'] : 0;
    $vt  = ($lvl === 0) ? 'p' : 'h' . max(1, min(6, $lvl));
    switch ($name) {
        case 'site-tagline':        return '<p class="wp-block-site-tagline">' . $inner . '</p>';
        case 'query-title':         return '<' . $vt . ' class="wp-block-query-title">' . $inner . '</' . $vt . '>';
        case 'post-title':          return '<h2 class="wp-block-post-title">' . $inner . '</h2>';
        case 'post-date':           return '<div class="wp-block-post-date">' . $inner . '</div>';
        case 'post-excerpt':        return '<div class="wp-block-post-excerpt"><p class="wp-block-post-excerpt__excerpt">' . $inner . '</p></div>';
        case 'post-content':        return '<div class="entry-content wp-block-post-content">' . $inner . '</div>';
        case 'post-terms':          return '<div class="wp-block-post-terms">' . $inner . '</div>';
        case 'post-author-name':    return '<div class="wp-block-post-author-name">' . $inner . '</div>';
        case 'post-featured-image': return '<figure class="wp-block-post-featured-image">' . $inner . '</figure>';
        case 'read-more':           return '<a class="wp-block-read-more" href="{{POST_URL}}">' . $inner . '</a>';
        default:                    return $inner;
    }
}

/**
 * 用核心渲染该块，把「值」占位化，同时保留核心生成的外层结构与 class。
 *
 * ⚠️ 2026-09-21 修：旧正则 `(</[a-z]+>\s*)$` **不认带数字的标签名** —— 而 post-title 的
 * 默认外层是 `<h1>`~`<h6>` ⇒ 永远匹配不上 ⇒ 落到最后那个 `return $html;`，把核心渲染出的
 * **真实标题 / 真实 permalink** 原样留在产物里（实测 56/57 个块主题中招）。
 *
 * 三级策略：
 *   ① 默认（单个外层标签包住全部） → 保留外壳标签与 class，内层整体换 $token
 *   ② 单层标签内是「一个 <a>」      → 保留 <a>，href → $link_token，链接文本 → $token
 *   ③ 连"单个外层标签"都匹配不上    → **老路径**：整块换成 $token（丢结构，但绝不残留真实值）
 *
 * @param string $link_token 链接型块（post-title 的 isLink）的 URL 占位符；空串 = 不处理链接
 */
function fse_block_inner_token($name, $attrs, $token, $link_token = '') {
    $html = fse_render_block($name, $attrs);
    if ($html === '') return fse_block_wrap_fallback($name, $attrs, $token);

    // ①/② 的前提：单个外层标签包住全部内容（标签名允许含数字：h1~h6）
    if (! preg_match('#^(\s*<[a-z][a-z0-9]*[^>]*>)([\s\S]*)(</[a-z][a-z0-9]*>\s*)$#i', $html, $m)) {
        return $token;                                              // ③ 老路径
    }
    $open = $m[1]; $inner = $m[2]; $close = $m[3];

    if ($link_token !== '' && preg_match('#^(\s*<a\b[^>]*\bhref=")[^"]*("[^>]*>)[\s\S]*?(</a>\s*)$#i', $inner, $a)) {
        return $open . $a[1] . $link_token . $a[2] . $token . $a[3] . $close;   // ② 链接形态
    }
    // ① 默认：保留外壳标签与 class，内层整体换 token
    //    （例：<div class="wp-block-post-date"><time datetime="…">…</time></div> ⇒ <div …>{{POST_DATE}}</div>；
    //          <div class="entry-content wp-block-post-content …">正文</div> ⇒ <div …>{{POST_CONTENT}}</div>）
    return $open . $token . $close;
}

/**
 * post-excerpt：**按结构**占位化。
 * 不能走 fse_block_value_token()（那条路要求"真实值 == 渲染值"，而核心摘要是 wp_trim_words
 * 处理过的、与 get_the_excerpt() 不等 ⇒ 必然失败 ⇒ 真实摘要漏进产物）。
 * 保留核心结构：<div class="wp-block-post-excerpt">…<p class="…__excerpt">…</p>[<p class="…__more-text"><a href>…</a></p>]</div>
 */
function fse_excerpt_token($attrs) {
    $html = fse_render_block('post-excerpt', $attrs);
    if ($html === '') return fse_block_wrap_fallback('post-excerpt', $attrs, '{{POST_EXCERPT}}');
    // ① more 链接的 href → {{POST_URL}}（主题文案保留）
    $html = preg_replace_callback(
        '#(<a\b[^>]*?\bhref=")[^"]*(")#i',
        function ($m) { return $m[1] . '{{POST_URL}}' . $m[2]; },
        $html
    );
    // ② 摘要正文段 → {{POST_EXCERPT}}（整体替换，含 &hellip; 之类）
    $out = preg_replace_callback(
        '#(<p\b[^>]*wp-block-post-excerpt__excerpt[^>]*>)[\s\S]*?(</p>)#i',
        function ($m) { return $m[1] . '{{POST_EXCERPT}}' . $m[2]; },
        $html
    );
    if (is_string($out) && $out !== $html) return $out;
    return '{{POST_EXCERPT}}';                                      // ③ 老路径
}

/**
 * read-more：核心输出 <a class="wp-block-read-more" href="permalink" target="_self">文案
 * <span class="screen-reader-text">: 标题</span></a>
 * ⇒ 只把 href 与无障碍文本里的标题占位化，**保留主题文案**。
 */
function fse_read_more_token($attrs) {
    $html = fse_render_block('read-more', $attrs);
    if ($html === '') return fse_block_wrap_fallback('read-more', $attrs, '阅读更多');
    $out = preg_replace_callback(
        '#(<a\b[^>]*?\bhref=")[^"]*(")#i',
        function ($m) { return $m[1] . '{{POST_URL}}' . $m[2]; },
        $html
    );
    if (! is_string($out)) return '阅读更多';
    $title = (string) esc_html(get_the_title());
    if ($title !== '') {
        $out = str_replace(':' . $title, ': {{POST_TITLE}}', $out);
        $out = str_replace($title, '{{POST_TITLE}}', $out);
    }
    return strpos($out, '{{POST_URL}}') !== false ? $out : '阅读更多';
}

/**
 * **C 层兜底**（对齐经典链路的 cfse_skin_sanitize_template()）：
 * 把「上下文文章的真实值」——标题 / permalink / 摘要——在产物里再扫一遍，替换成占位符。
 *
 * 为什么需要它：路 A（读文件 + 逐块 render_block）的值占位化是**按块的 token 映射**，
 * 只有"每个块的映射都正确"才不漏真实内容（实测 post-title 因 `</[a-z]+>` 不认 `<h1>` 而漏了 56/57 个主题）。
 * 经典链路有三层防御（探针 + 值锚 + sanitize），这里补上第三层 ⇒ 任何映射失效都能兜住。
 *
 * ⚠️ 必须在 **host 归一化之前**调用 —— 那时 permalink 还是完整 URL（含本站 host）。
 * 只替换**够长**的串（标题 ≥4 字、摘要 ≥10 字），避免误伤模板里恰好相同的短文本。
 *
 * @return int 被替换掉的处数（用于日志）
 */
function fse_strip_context_values(&$out) {
    $ctx = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;
    if (! $ctx) return 0;
    $title = (string) get_the_title($ctx);
    $link  = (string) get_permalink($ctx);
    $ex    = (string) get_the_excerpt($ctx);

    $map = array();
    if ($title !== '' && mb_strlen($title) >= 4) {
        $map[esc_html($title)] = '{{POST_TITLE}}';      // 核心渲染多半做了 esc_html
        $map[$title]           = '{{POST_TITLE}}';
    }
    if ($link !== '') {
        $map[esc_url($link)] = '{{POST_URL}}';
        $map[$link]          = '{{POST_URL}}';
    }
    if ($ex !== '' && mb_strlen($ex) >= 10) {
        $map[$ex]                                 = '{{POST_EXCERPT}}';
        $map[wp_trim_words($ex, 55, '…')]         = '{{POST_EXCERPT}}';   // 核心默认省略号形态
        $map[wp_trim_words($ex, 55, '&hellip;')]  = '{{POST_EXCERPT}}';
    }
    // 长串优先，避免短串先把长串切坏
    uksort($map, function ($a, $b) { return strlen($b) - strlen($a); });

    $n = 0;
    foreach ($out as $k => $v) {
        foreach ($map as $real => $tok) {
            if ($real === '') continue;
            $c = substr_count($v, $real);
            if ($c > 0) { $v = str_replace($real, $tok, $v); $n += $c; }
        }
        $out[$k] = $v;
    }
    return $n;
}

/** 用核心渲染该块，再把真实值替换成占位符（保留全部内部结构，如摘要外的 Read more 链接） */
function fse_block_value_token($name, $attrs, $map, $fallback) {
    $html = fse_render_block($name, $attrs);
    if ($html === '') return $fallback;
    foreach ($map as $real => $token) {
        $real = (string) $real;
        if ($real !== '') { $html = str_replace($real, $token, $html); }
    }
    return $html;
}

/**
 * 模拟一个单页上下文（body_class / 条件标签 / render_block 取文章值都需要）。
 * ⚠️ 必须在**文件解析之前**调用 —— site-title / post-title 这类块要渲染出真实结构。
 */
function fse_setup_context() {
    $q = new WP_Query(array('posts_per_page' => 1));
    global $wp_query, $post;
    $wp_query = $q;
    $GLOBALS['wp_the_query'] = $q;
    $post = ! empty($q->posts) ? $q->posts[0] : null;
}

/** 从 inner 里取出开标签（$open 只有块注释，不是标签 —— 见 docs §4.3） */
function fse_first_open_tag($inner) {
    if (preg_match('#^(\s*<[a-z][^>]*>)#i', $inner, $m)) {
        return array($m[1], substr($inner, strlen($m[0])));
    }
    return array('', $inner);
}

/**
 * 取一个 pattern 的内容（不含 PHP 头）。
 *
 * 优先走 **WP 核心的 pattern 注册表** —— 它覆盖所有注册来源：主题 patterns/*.php 及其**子目录**
 * （实测 mroya 把模板 pattern 放在 patterns/template/ 下）、functions.php 里 register_block_pattern()、
 * 插件注册的 pattern。文件系统查找只在注册表取不到时兜底（且递归扫子目录）。
 *
 * @return array{0:string,1:string} [内容, 来源 registry|file|miss]
 */
function fse_get_pattern($slug, $theme_root) {
    if (class_exists('WP_Block_Patterns_Registry')) {
        $reg = WP_Block_Patterns_Registry::get_instance();
        $p = $reg->get_registered($slug);
        if (is_array($p) && ! empty($p['content'])) {
            return array((string) $p['content'], 'registry');
        }
    }
    $base = basename($slug);
    $dir  = rtrim($theme_root, '/') . '/patterns';
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! $f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if ($ext !== 'php' && $ext !== 'html') continue;
            if (pathinfo($f->getFilename(), PATHINFO_FILENAME) !== $base) continue;
            $c = (string) file_get_contents($f->getPathname());
            $c = preg_replace('/^<\?php[\s\S]*?\?>/', '', $c);
            return array($c, 'file');
        }
    }
    return array('', 'miss');
}

function fse_render($html) {
    $tokens = fse_tokens($html);
    $blocks = fse_blocks($tokens);
    if (!$blocks) return $html;
    $out = '';
    $cursor = 0;
    foreach ($blocks as $b) {
        if ($b['start'] < $cursor) continue;
        $out .= substr($html, $cursor, $b['start'] - $cursor);
        $out .= fse_block_repl($b, $html);
        $cursor = $b['end'];
    }
    $out .= substr($html, $cursor);
    return $out;
}

/**
 * 补「文档外壳」：块主题的 templates/*.html 只有 body，head / footer 是 wp_head()/wp_footer()
 * 运行时生成的 ⇒ 必须由这一步拿。
 *
 * 机制（wp-includes/template-canvas.php 的原注释）：块渲染时会**登记**自己需要的样式，
 * wp_head() 再统一输出 —— 所以这里先 get_the_block_template_html() 跑一次"登记"（输出丢弃），
 * 再分别取 wp_head()/wp_body_open()/wp_footer()。一行 head 都不用自己拼。
 *
 * 前置：调用方需已 switch_theme() 到目标主题（wp_head 会跑主题的 enqueue）。
 *
 * @return array{open:string,close:string,head:string,footer:string}
 */
function fse_shell_parts($template_file) {
    // 1) 文章上下文（主流程已建好；这里兜底再调一次，幂等）
    fse_setup_context();

    // 2) 告诉核心"当前块模板内容"，供 get_the_block_template_html() 使用
    global $_wp_current_template_content;
    $_wp_current_template_content = is_file($template_file) ? (string) file_get_contents($template_file) : '';

    // 3) 先登记样式（返回值是渲染后的 body，这里丢弃 —— body 我们自己有占位符版）
    get_the_block_template_html();

    // 4) 清空"已打印"标记再取 head。
    // 核心的 wp_print_styles / wp_print_head_scripts 用 WP_Styles::$done（全局）去重 ⇒
    // 不清空的话第二次 wp_head() 什么都不输出（实测：第一个模板 9 个 <style>、后面的 0 个）。
    if (function_exists('wp_styles'))  { wp_styles()->done  = array(); }
    if (function_exists('wp_scripts')) { wp_scripts()->done = array(); }

    // 4) 分别取三段的"固定格式"输出
    ob_start(); wp_head();      $head      = (string) ob_get_clean();
    ob_start(); wp_body_open(); $body_open = (string) ob_get_clean();
    ob_start(); wp_footer();    $footer    = (string) ob_get_clean();

    $lang      = get_language_attributes();
    $charset   = esc_attr(get_bloginfo('charset'));
    $bodyclass = implode(' ', get_body_class());

    return array(
        'head'   => $head,
        'footer' => $footer,
        'open'   => "<!DOCTYPE html>\n<html {$lang}>\n<head>\n<meta charset=\"{$charset}\" />\n{$head}\n</head>\n\n<body class=\"{$bodyclass}\">\n{$body_open}",
        'close'  => "\n{$footer}\n</body>\n</html>\n",
    );
}

/**
 * 把产物里**实际引用**的主题资源拷进风格包（不整目录搬）。
 * 提取 head / @font-face 里出现的 /wp-content/themes/<slug>/... 路径逐个拷贝 ——
 * Worker 端 assets 命中即直出，未命中会落 R2，两者都没有就是 404。
 *
 * @return array{0:int,1:array} [已拷贝数, 缺失清单]
 */
function fse_copy_assets($theme_root, $dst, $payload) {
    $slug   = basename(rtrim($theme_root, '/'));
    $prefix = 'wp-content/themes/' . $slug . '/';
    $json   = (string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // ⚠️ 字符类必须包含 , ( ) [ ] —— 不少主题的变体字体文件名带逗号/方括号
    //    （Geologica-VariableFont_CRSV,SHRP,slnt,wght.ttf、source-sans-pro[wght].woff2），旧字符类会把它截断 ⇒ 拷贝失败 ⇒ 前端 404
    if (! preg_match_all('#' . preg_quote($prefix, '#') . '[A-Za-z0-9._%+/,\[\]-]*#', $json, $m)) {
        return array(0, array());
    }
    $copied = 0;
    $missing = array();
    foreach (array_unique($m[0]) as $p) {
        $rel = preg_replace('/\?.*$/', '', substr($p, strlen($prefix)));   // 去掉 ?ver=…
        if ($rel === '' || $rel === false) continue;
        $rel = rawurldecode($rel);                                        // %xx → 原文（与 s3/local 同规矩）
        $src = rtrim($theme_root, '/') . '/' . $rel;
        if (! is_file($src)) { $missing[] = $rel; continue; }
        $out = rtrim($dst, '/') . '/' . $rel;
        if (! is_dir(dirname($out))) { @mkdir(dirname($out), 0755, true); }
        if (@copy($src, $out)) { $copied++; }
    }
    return array($copied, $missing);
}

// ============ 主流程 ============
$theme = $opt['theme'] !== '' ? $opt['theme'] : get_option('stylesheet');
$root = get_theme_root() . '/' . $theme;

// 探测是否为 FSE
$isFse = is_dir($root . '/templates') && is_file($root . '/theme.json');
echo "theme=$theme isFSE=" . ($isFse ? 'y' : 'n') . "\n";
if (!$isFse) { fwrite(STDERR, "NOT an FSE theme (no templates/ + theme.json)\n"); exit(2); }

$GLOBALS['fse_uncovered'] = array();
$GLOBALS['fse_missing'] = array();
$GLOBALS['fse_parts'] = array();
$GLOBALS['fse_item'] = '';

// 主题必须在这个进程「启动前」就已经是目标主题。
// WP 只在 wp-settings.php 里加载一次主题的 functions.php；同请求内 switch_theme() 只改 option
// ⇒ wp_head() 会跑「上个主题的 enqueue」，却用「新主题的路径」输出 href —— 混合 head
//   （实测：id 是 twentyten 的，href 却指向 neve-fse/blocks.css，而该文件并不存在）。
// 所以先切主题，再另起一个进程真正干活（与「切插件副本必须分两次请求」同型）。
if ($theme !== (string) get_option('stylesheet') && empty($opt['switched'])) {
    $prev_theme = (string) get_option('stylesheet');
    switch_theme($theme);
    wp_cache_flush();

    $args = array();
    foreach ($opt as $k => $v) {
        if ($k === 'switched') continue;
        $args[] = '--' . $k . '=' . $v;
    }
    $args[] = '--switched=1';
    $cmd = escapeshellarg(PHP_BINARY);
    foreach (array_merge(array(__FILE__), $args) as $a) { $cmd .= ' ' . escapeshellarg($a); }

    echo "主题切换 {$prev_theme} → {$theme}，另起进程渲染…\n";
    $lines = array();
    $rc = 0;
    exec($cmd . ' 2>&1', $lines, $rc);
    echo implode("\n", $lines) . "\n";

    switch_theme($prev_theme);
    wp_cache_flush();
    exit($rc);
}

// 文件解析阶段就要有文章上下文（site-title / post-title 这类块要渲染真实结构）
fse_setup_context();

$out = array();
$files = array('front-page.html' => 'pageHome', 'home.html' => 'pageHome', 'index.html' => 'pageHome',
    'archive.html' => 'pageArchive', 'single.html' => 'single');
$used = array();
foreach ($files as $f => $key) {
    $fp = $root . '/templates/' . $f;
    if (!is_file($fp)) continue;
    $GLOBALS['fse_item'] = '';
    $conv = fse_render(fse_read($fp));
    // 外壳（head + body 开始 / footer + body 结束）；item 是列表条目片段，不加外壳
    $shell = fse_shell_parts($fp);
    $out[$key] = $shell['open'] . $conv . $shell['close'];
    $used[$key] = true;
    if ($GLOBALS['fse_item'] !== '' && !isset($out['item'])) { $out['item'] = $GLOBALS['fse_item']; }
}


// 展开 parts + patterns（**多轮交替**）。
// ⚠️ 顺序敏感：pattern 里可以引 part（bluehost-blueprint / lexionic / booknest 等把 header/footer
// 放在 pattern 里）、part 里也可以引 pattern、pattern 里还能再引 pattern ⇒
// 旧版「先展开 parts、再展开 patterns」的单向顺序会把 pattern 引出的 {{PART:header}} 永远留在产物里
// （首批实测 8 个主题中招）。现在同一个循环里交替展开，直到没有变化（上限 8 轮）。
for ($__round = 0; $__round < 8; $__round++) {
    $__any = false;

    // 1) parts（{slug => tagName}；核心按 tagName 包一层，这里补上）
    foreach ($GLOBALS['fse_parts'] as $p => $tag) {
        $__needle = '{{PART:' . $p . '}}';
        $__hit = false;
        foreach ($out as $__v) { if (strpos($__v, $__needle) !== false) { $__hit = true; break; } }
        if (! $__hit) continue;
        $__pp = $root . '/parts/' . $p . '.html';
        $__content = is_file($__pp) ? fse_render(fse_read($__pp)) : '';
        if ($tag !== '') {
            $__content = '<' . $tag . ' class="wp-block-template-part">' . $__content . '</' . $tag . '>';
        }
        foreach ($out as $k => $v) { $out[$k] = str_replace($__needle, $__content, $v); }
        $__any = true;
    }

    // 2) patterns。
    // ⚠️ 展开出来的内容必须过一遍块转换：官方主题（twentytwentyfour/five、blockwist…）的列表
    // 不写 wp:query，而是 wp:pattern 引一个 pattern，query + post-template 都在里面。
    // 只做原文替换的话：item 段为空（列表页没条目）且产物里残留块标记。
    foreach ($out as $k => $v) {
        if (! preg_match_all('/\{\{PATTERN:([^}]+)\}\}/', $v, $mm)) continue;
        $__any = true;
        foreach (array_unique($mm[1]) as $ps) {
            list($__content, $__from) = fse_get_pattern($ps, $root);
            if ($__content === '') {
                $GLOBALS['fse_missing'][] = $ps;   // 取不到就记下来（别静默）
                // 且清空，不把 {{PATTERN:x}} 文本挂到线上（首批实测 ona-architecture 8 个）
                $out[$k] = str_replace('{{PATTERN:' . $ps . '}}', '', $out[$k]);
                continue;
            }
            $__content = fse_render($__content);
            $out[$k] = str_replace('{{PATTERN:' . $ps . '}}', $__content, $out[$k]);
        }
    }

    // 3) 兜底：仍无法解析的中间态占位符一律清空
    //    （防线上/预览出现 {{PART:x}} / {{PATTERN:x}} 文本）
    foreach ($out as $k => $v) {
        if (strpos($v, '{{PART:') !== false || strpos($v, '{{PATTERN:') !== false) {
            $out[$k] = preg_replace('/\{\{(?:PART|PATTERN):[^}]*\}\}/', '', $v);
        }
    }

    if (! $__any) break;
}

// pattern 里若含 post-template，条目骨架是在上面这一步才拿到的 ⇒ 补写 item。
// ⚠️ 但 item 不是 $out 里的“已展开”段（它在循环之后才补写）⇒ 必须**再走一遍展开 + 兜底清理**，
// 否则：循环里拿到的条目骨架中的 {{PART:post-meta}} 会原样残留
// （实测 twentytwentyfour：多轮修e然剩 1 个）。
if (! isset($out['item']) && $GLOBALS['fse_item'] !== '') {
    $out['item'] = $GLOBALS['fse_item'];
    for ($__round2 = 0; $__round2 < 8; $__round2++) {
        $__changed = false;
        // parts
        foreach ($GLOBALS['fse_parts'] as $p => $tag) {
            $__needle = '{{PART:' . $p . '}}';
            if (strpos($out['item'], $__needle) === false) continue;
            $__pp = $root . '/parts/' . $p . '.html';
            $__c  = is_file($__pp) ? fse_render(fse_read($__pp)) : '';
            if ($tag !== '') { $__c = '<' . $tag . ' class="wp-block-template-part">' . $__c . '</' . $tag . '>'; }
            $out['item'] = str_replace($__needle, $__c, $out['item']);
            $__changed = true;
        }
        // patterns
        if (preg_match_all('/\{\{PATTERN:([^}]+)\}\}/', $out['item'], $mm)) {
            $__changed = true;
            foreach (array_unique($mm[1]) as $ps) {
                list($__c, $__f) = fse_get_pattern($ps, $root);
                if ($__c === '') {
                    $GLOBALS['fse_missing'][] = $ps;
                    $out['item'] = str_replace('{{PATTERN:' . $ps . '}}', '', $out['item']);
                    continue;
                }
                $out['item'] = str_replace('{{PATTERN:' . $ps . '}}', fse_render($__c), $out['item']);
            }
        }
        // 兜底：仍无法解析的中间态占位符清空
        $out['item'] = preg_replace('/\{\{(?:PART|PATTERN):[^}]*\}\}/', '', $out['item']);
        if (! $__changed) break;
    }
}

// ---- C 层兜底：上下文文章的真实值（标题 / permalink / 摘要）→ 占位符 ----
// 必须在 host 归一化之前（那时 permalink 还是完整 URL）。见 fse_strip_context_values()。
$__stripped = fse_strip_context_values($out);
if ($__stripped > 0) { echo "context_values_stripped: {$__stripped}\n"; }

// ---- 站点 host 归一化：菜单/分类等链接里的本站 URL → {{SITE_URL}} ----
// wp:navigation 由 fse_menu_html() 用 wp_get_nav_menu_items() 渲染，链接带 home_url() 前缀；
// CLI 下它可能退化成 localhost/内网 IP ⇒ 这里统一归一，与经典链路的 normalize_source_hosts 同思路。
$__hosts = array();
foreach (array($site, home_url('/')) as $__u) {
    $__u = rtrim((string) $__u, '/');
    if ($__u === '') continue;
    $__hosts[] = $__u;
    $__hosts[] = str_replace(array(':', '/'), array('%3A', '%2F'), $__u);   // percent-encoded 形态
}
$__hosts = array_unique($__hosts);
foreach ($out as $k => $v) {
    foreach ($__hosts as $__h) { $v = str_replace($__h, '{{SITE_URL}}', $v); }
    $out[$k] = $v;
}

// 组装 tpl.json 结构
$payload = array(
    'name' => $theme,
    'tpl' => array(
        'single' => isset($out['single']) ? $out['single'] : '',
        'item' => isset($out['item']) ? $out['item'] : '',
        'pageHome' => isset($out['pageHome']) ? $out['pageHome'] : '',
        'pageCategory' => isset($out['pageArchive']) ? $out['pageArchive'] : '',
        'pageTag' => isset($out['pageArchive']) ? $out['pageArchive'] : '',
        'pageArchive' => isset($out['pageArchive']) ? $out['pageArchive'] : '',
    ),
);

// 列表类骨架（categories / archives / tags）：Worker 端 cfseSidebar* 用它再生条目
// （无此段时 Worker 会用默认 <li>，对 categories/archives 恰好可用，但 tag-cloud 形态不匹配）
if (! empty($GLOBALS['fse_sidebar'])) { $payload['sidebar'] = $GLOBALS['fse_sidebar']; }

echo "=== 摘要 ===\n";
echo "templates: " . implode(',', array_keys($used)) . "\n";
echo "item_len: " . (isset($out['item']) ? strlen($out['item']) : 0) . "\n";
echo "uncovered: " . (empty($GLOBALS['fse_uncovered']) ? '(无)' : json_encode($GLOBALS['fse_uncovered'])) . "\n";
echo "missing_patterns: " . (empty($GLOBALS['fse_missing']) ? '(无)' : json_encode(array_values(array_unique($GLOBALS['fse_missing'])))) . "\n";
foreach (array('single', 'item', 'pageHome', 'pageArchive') as $k) {
    $len = isset($payload['tpl'][$k]) ? strlen($payload['tpl'][$k]) : 0;
    echo "  tpl.$k len=$len\n";
}

if ($opt['out'] !== '') {
    $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($opt['out'], $json) === false) {
        fwrite(STDERR, "cannot write " . $opt['out'] . "\n");
        exit(1);
    }
    echo "written: " . $opt['out'] . " (" . strlen($json) . "B)\n";
}

// 资源随包拷贝（--assets=<风格包目录>）
if ($opt['assets'] !== '') {
    list($n_copied, $n_missing) = fse_copy_assets($root, $opt['assets'], $payload);
    echo "assets: copied={$n_copied}";
    if (! empty($n_missing)) {
        echo "  missing(" . count($n_missing) . "): " . implode(', ', $n_missing);
    }
    echo "\n";
}
exit(0);
