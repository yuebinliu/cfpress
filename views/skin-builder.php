<?php
/**
 * CF Static Export Pro — 风格包生成 v2（内部渲染 + 探针 token 驱动映射）
 *
 * 与旧流程（发探针 + HTTP 抓首页/单页 + 主题类名正则清洗）的区别：
 *   1. HTML 来源 = tools/skin-render.php 的内部渲染（每种页面一个进程），无 HTTP、无缓存干扰、
 *      无登录用户（不会带 admin bar）；
 *   2. 结构映射靠**探针内容 token**（标题/正文/摘要/日期/分类名/标签名/permalink）定位，
 *      不再依赖 twentyten 的 `h2.entry-title` / `<!-- #post-N -->` / `#nav-above` 之类类名。
 *      所有替换都在原始 HTML 字符串上做（不经过 DOMDocument::saveHTML），保证格式零改动。
 *   3. 站点级区域（侧边栏分类/归档/标签云、分页容器）保留"容器占位"策略。
 *
 * 产出 6 类模板：pageHome / pageCategory / pageTag / pageArchive / item / single。
 */

/**
 * 在 $pos 处向上找"包含该位置的元素开始标签"。
 * 返回 array(tag, start, content_start)；找不到返回 null。
 */
function cfse_html_loc_tag($html, $pos) {
    $search = $pos;
    $guard = 0;
    while ($guard++ < 50) {
        $lt = strrpos(substr($html, 0, $search), '<');
        if ($lt === false) return null;
        $gt = strpos($html, '>', $lt);
        if ($gt === false) return null;
        if ($gt < $pos) {
            $inner = substr($html, $lt + 1, $gt - $lt - 1);
            if ($inner === '' || $inner[0] === '/' || $inner[0] === '!' || $inner[0] === '?') return null;
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9]*)/', $inner, $m)) {
                return array('tag' => strtolower($m[1]), 'start' => $lt, 'content_start' => $gt + 1);
            }
            return null;
        }
        $search = $lt;   // $pos 落在标签内部，继续向前
    }
    return null;
}

/**
 * 从元素内容起点向后找匹配的结束标签（嵌套计数；跳过注释与自闭合）。
 * 返回 array(close_start, close_end)；找不到返回 null。
 */
function cfse_html_find_close($html, $content_start, $tag) {
    static $void = array('br', 'img', 'input', 'hr', 'meta', 'link', 'area', 'base', 'col', 'embed', 'param', 'source', 'track', 'wbr');
    if (in_array($tag, $void, true)) return array('close_start' => $content_start, 'close_end' => $content_start);
    $depth = 1;
    $offset = $content_start;
    $len = strlen($html);
    while ($offset < $len) {
        $lt = strpos($html, '<', $offset);
        if ($lt === false) break;
        if (substr($html, $lt, 4) === '<!--') {
            $end = strpos($html, '-->', $lt);
            if ($end === false) break;
            $offset = $end + 3;
            continue;
        }
        $gt = strpos($html, '>', $lt);
        if ($gt === false) break;
        $inner = substr($html, $lt + 1, $gt - $lt - 1);
        if ($inner !== '' && $inner[0] === '/') {
            if (preg_match('~^/\s*([a-zA-Z][a-zA-Z0-9]*)~', $inner, $m) && strtolower($m[1]) === $tag) {
                $depth--;
                if ($depth === 0) return array('close_start' => $lt, 'close_end' => $gt + 1);
            }
        } elseif ($inner !== '' && $inner[0] !== '!') {
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9]*)/', $inner, $m)) {
                $t2 = strtolower($m[1]);
                if ($t2 === $tag && substr(rtrim($inner), -1) !== '/') $depth++;
            }
        }
        $offset = $gt + 1;
    }
    return null;
}

/** 用唯一标记定位一个元素区间：array(tag, start, content_start, close_start, close_end) */
function cfse_html_element_by_marker($html, $marker) {
    $p = strpos($html, $marker);
    if ($p === false) return null;
    $lt = strrpos(substr($html, 0, $p), '<');
    if ($lt === false) return null;
    $gt = strpos($html, '>', $p);
    if ($gt === false) return null;
    $inner = substr($html, $lt + 1, $gt - $lt - 1);
    if (!preg_match('/^([a-zA-Z][a-zA-Z0-9]*)/', $inner, $m)) return null;
    $tag = strtolower($m[1]);
    $close = cfse_html_find_close($html, $gt + 1, $tag);
    if (!$close) return null;
    return array('tag' => $tag, 'start' => $lt, 'content_start' => $gt + 1, 'close_start' => $close['close_start'], 'close_end' => $close['close_end']);
}

/**
 * 定位探针的条目容器。
 * 1) id="post-<probe_id>"（WordPress 惯例，唯一）
 * 2) 祖先链里 class/id 含 post 的元素
 * 3) 最近的 article / 带 id 的 div
 */
function cfse_skin_locate_container($html, $probe_id, $token_pos) {
    $r = cfse_html_element_by_marker($html, 'id="post-' . $probe_id . '"');
    if ($r) { $r['how'] = 'id="post-' . $probe_id . '"'; return $r; }
    $r = cfse_html_element_by_marker($html, "id='post-" . $probe_id . "'");
    if ($r) { $r['how'] = "id='post-N'"; return $r; }

    $cur = $token_pos;
    for ($i = 0; $i < 10 && $cur > 0; $i++) {
        $loc = cfse_html_loc_tag($html, $cur);
        if (!$loc) break;
        $tag_html = substr($html, $loc['start'], $loc['content_start'] - $loc['start']);
        if (preg_match('~(id|class)\s*=\s*"[^"]*(post-|\bpost\b|type-post|hentry)[^"]*"~', $tag_html)) {
            $close = cfse_html_find_close($html, $loc['content_start'], $loc['tag']);
            if ($close) {
                return array('tag' => $loc['tag'], 'start' => $loc['start'], 'content_start' => $loc['content_start'],
                    'close_start' => $close['close_start'], 'close_end' => $close['close_end'], 'how' => 'ancestor class/id ~ post');
            }
        }
        $cur = $loc['start'] - 1;
    }
    // 3) 退回 article
    $cur = $token_pos;
    for ($i = 0; $i < 10 && $cur > 0; $i++) {
        $loc = cfse_html_loc_tag($html, $cur);
        if (!$loc) break;
        if ($loc['tag'] === 'article' || $loc['tag'] === 'li') {
            $close = cfse_html_find_close($html, $loc['content_start'], $loc['tag']);
            if ($close) {
                return array('tag' => $loc['tag'], 'start' => $loc['start'], 'content_start' => $loc['content_start'],
                    'close_start' => $close['close_start'], 'close_end' => $close['close_end'], 'how' => 'fallback ' . $loc['tag']);
            }
        }
        $cur = $loc['start'] - 1;
    }
    return null;
}

/** 枚举页面里的所有条目容器（用于把列表区替换成 {{CONTENT}}） */
function cfse_skin_enum_containers($html) {
    $out = array();
    if (preg_match_all('~id\s*=\s*"post-(\d+)"~', $html, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $i => $cap) {
            $el = cfse_html_element_by_marker($html, 'id="post-' . $cap[0] . '"');
            if ($el) { $el['id'] = (int) $cap[0]; $out[] = $el; }
        }
    }
    return $out;
}

/** 生成日期候选文本（主题可能用不同格式输出日期；含 ISO 形态，供 <time datetime> 用） */
function cfse_skin_date_candidates($post_date, $post_modified = '', $post_modified_gmt = '') {
    $out = array();
    $times = array();
    foreach (array($post_date, $post_modified, $post_modified_gmt) as $d) {
        $ts = strtotime((string) $d);
        if ($ts) { $times[] = $ts; }
    }
    if (!$times) return array();
    foreach ($times as $ts) {
        foreach (array('Y-m-d', 'Y/m/d', 'F j, Y', 'j F Y', 'M j, Y', 'd M Y', 'm/d/Y', 'Y-n-j', 'Y年n月j日',
                       'c', 'Y-m-d\TH:i:sP') as $f) {
            $s = date_i18n($f, $ts);
            if ($s !== '') $out[$s] = true;
        }
    }
    return array_keys($out);
}

/** 在容器片段内做 token → 占位符替换（原地字符串替换，保持格式） */
function cfse_skin_replace_item($item, $ctx, $is_single) {
    $warns = array();
    // 1) permalink 的各种形态 → {{POST_URL}}（在站点 URL 占位化之前做）
    $perm = $ctx['permalink'];
    $rel = '/' . trim((string) parse_url($perm, PHP_URL_PATH), '/') . '/';
    $item = str_replace(array($perm, rtrim($perm, '/'), $rel), '{{POST_URL}}', $item);
    // 2) 标题 token → {{POST_TITLE}}
    $item = str_replace($ctx['probe_title'], '{{POST_TITLE}}', $item);
    // 3) 正文/摘要 token 所在元素的**内容** → 占位符
    $ph = $is_single ? '{{POST_CONTENT}}' : '{{POST_EXCERPT}}';
    foreach (array($ctx['token'] . '-CONTENT', $ctx['token'] . '-EXCERPT') as $tk) {
        $pos = strpos($item, $tk);
        if ($pos === false) continue;
        $loc = cfse_html_loc_tag($item, $pos);
        if ($loc) {
            $close = cfse_html_find_close($item, $loc['content_start'], $loc['tag']);
            if ($close && $close['close_start'] > $loc['content_start']) {
                // 保留元素本身（含 class），内容换成占位符；列表页正文里的多段合并成一个占位符
                $item = substr($item, 0, $loc['content_start']) . $ph . substr($item, $close['close_start']);
            } else {
                $item = str_replace($tk, $ph, $item);
            }
        } else {
            $item = str_replace($tk, $ph, $item);
        }
    }
    // 4) 日期 → {{POST_DATE}}
    $done_date = false;
    foreach ($ctx['date_candidates'] as $dc) {
        if ($dc !== '' && strpos($item, $dc) !== false) {
            $item = str_replace($dc, '{{POST_DATE}}', $item);
            $done_date = true;
            break;
        }
    }
    if (!$done_date) $warns[] = '未在条目容器内匹配到日期文本（' . implode(' / ', array_slice($ctx['date_candidates'], 0, 3)) . '），{{POST_DATE}} 可能缺失';
    // 5) 分类名 / 标签名 → 占位符（只在本容器内，避免误伤侧边栏同名文本）
    // 注意要**全量**替换：探针可挂多个分类/标签，每个都会产出一个占位符
    foreach ($ctx['cat_names'] as $name) {
        if ($name === '') continue;
        $item = preg_replace('~<a\b[^>]*>\s*' . preg_quote($name, '~') . '\s*</a>~u', '{{POST_CATEGORIES}}', $item, -1);
        $item = str_replace($name, '{{POST_CATEGORIES}}', $item);
    }
    foreach ($ctx['tag_names'] as $name) {
        if ($name === '') continue;
        $item = preg_replace('~<a\b[^>]*>\s*' . preg_quote($name, '~') . '\s*</a>~u', '{{POST_TAGS}}', $item, -1);
        $item = str_replace($name, '{{POST_TAGS}}', $item);
    }
    // 多个分类/标签留下的重复占位符合并成单个（分隔符只允许逗号/顿号/and/和等）
    for ($pass = 0; $pass < 3; $pass++) {
        $item = preg_replace('~\{\{POST_CATEGORIES\}\}(?:\s*[,、/&]?\s*(?:and|和)?\s*\{\{POST_CATEGORIES\}\})+~u', '{{POST_CATEGORIES}}', $item);
        $item = preg_replace('~\{\{POST_TAGS\}\}(?:\s*[,、/&]?\s*(?:and|和)?\s*\{\{POST_TAGS\}\})+~u', '{{POST_TAGS}}', $item);
    }
    // 5b) 分类/标签区 → 条件块（{{#IF_CATEGORIES}} / {{#IF_TAGS}}）
    // 在占位符层面处理（不依赖主题类名/文案）：把「紧邻的说明文字 / 分隔符 + 占位符序列」一起包进 IF，
    // 这样没有分类/标签的文章不会留下 “and tagged .” / 孤零零的 “Tagged” / 孤立的 “|” 这类残句。
    foreach (array('{{POST_CATEGORIES}}' => '{{#IF_CATEGORIES}}', '{{POST_TAGS}}' => '{{#IF_TAGS}}') as $ph => $open_tag) {
        $p1 = strpos($item, $ph);
        if ($p1 === false) continue;
        $p2 = strrpos($item, $ph) + strlen($ph);
        $close_tag = str_replace('#IF_', '/IF_', $open_tag);

        // 向前扩展条件块起点：
        //   ① 紧邻的完整 span（接头语 “Tagged” / 分隔符 “|”）→ 纳入；
        //   ② 紧邻的容器开标签（未闭合的 <span …>）→ 跨过它并记账，稍后吃掉对应的 </span>，
        //      保证条件块自身 HTML 平衡；
        //   ③ 其他情况停止（例如遇到别的容器的闭合 </span>）。
        // 于是 twentyten 的「分类 | 标签 | 评论」结构里，标签前那个 | 会被纳入（无标签即消失），
        // 而评论前那个无条件的 | 不会被吞。
        $back = $p1;
        $spans = 0;
        for ($guard = 0; $guard < 8; $guard++) {
            $pre = substr($item, 0, $back);
            if (preg_match('~<span\b[^>]*>[^<>]{0,60}</span>\s*$~i', $pre, $m, PREG_OFFSET_CAPTURE)) {
                $back = $m[0][1];
                continue;
            }
            if (preg_match('~<span\b[^>]*>\s*$~i', $pre, $m2, PREG_OFFSET_CAPTURE)) {
                $back = $m2[0][1];
                $spans++;
                continue;
            }
            break;
        }
        // 向后补齐：每个被跨过的开标签吃掉一个对应的 </span>
        if ($spans > 0) {
            $rest = substr($item, $p2);
            if (preg_match('~^(?:\s*</span>\s*){' . (int) $spans . '}~i', $rest, $mr)) {
                $p2 += strlen($mr[0]);
            }
        }
        $item = substr($item, 0, $back) . $open_tag . substr($item, $back, $p2 - $back) . $close_tag . substr($item, $p2);
    }

    // 6) 站点级替换。作者归档等站点链接**保留** {{SITE_URL}} 占位符：
    //    渲染端 cfseRenderItem() 会填充它，而 /author/<slug>/ 这类路径由 Worker 路由文件支持。
    $item = cfse_skin_replace_site($item, $ctx);
    // 7) 作者链接 → 占位符（item 也有作者块）
    $item = cfse_skin_replace_author_link($item, $ctx);
    // 8) 容器自身的 post-N id/class 去掉（避免模板里写死某一篇）
    // 评论数：条目里的 "N Comments" 是**探针文章**当时的评论数（原样固化在骨架里 ⇒ 列表每篇都显示同一个数字）
    // ⇒ 占位化，交给渲染端按本篇真实数填。渲染端 cfseRenderItem() 另有兜底正则，所以已存在的老骨架也正确。
    $item = preg_replace('~(#comments["\'][^>]*>)(?:\d+\s*Comments?|No Comments)~i', '$1{{COMMENT_COUNT_TEXT}}', $item);
    $item = preg_replace('~(<[a-zA-Z]+[^>]*)\s+id="post-' . (int) $ctx['probe_id'] . '"~', '$1', $item, 1);
    $item = preg_replace('~(<[a-zA-Z]+[^>]*class="[^"]*)\bpost-' . (int) $ctx['probe_id'] . '\b\s*~', '$1', $item, 1);
    // 9) 根元素 class 里的 per-post 数据去掉：category-<slug> / tag-<slug|id> / has-post-thumbnail
    //    （探针特有的，换文章就错；Worker 渲染端也不生成它们）
    if (preg_match('~^\s*(<[a-zA-Z]+\b[^>]*>)~', $item, $rm)) {
        $root = $rm[1];
        $clean = preg_replace('~\s+(?:category-[^\s"]+|tag-[^\s"]+|has-post-thumbnail)\b~', '', $root);
        $clean = preg_replace('~\s{2,}~', ' ', $clean);
        if ($clean !== $root) {
            $item = preg_replace('~^\s*' . preg_quote($root, '~') . '~', $clean, $item, 1);
        }
    }
    if (strpos($item, '{{POST_TITLE}}') === false) {
        $warns[] = '条目模板缺少 {{POST_TITLE}}（标题 token 可能没匹配上）';
    }
    return array($item, $warns);
}

if (!function_exists('cfse_skin_sanitize_template')) {
    function cfse_skin_sanitize_template($html) {
        if (!is_string($html) || $html === '') { return $html; }
        // 1) source-site host, 4 spellings -> {{SITE_URL}}
        $hosts = array();
        $cands = array();
        if (function_exists('home_url')) { $cands[] = home_url('/'); }
        if (function_exists('site_url')) { $cands[] = site_url('/'); }
        if (function_exists('get_option')) { $cands[] = (string) get_option('siteurl'); }
        foreach ($cands as $u) {
            if (!is_string($u) || $u === '') { continue; }
            $h = parse_url($u, PHP_URL_HOST);
            if ($h) { $hosts[$h] = true; }
        }
        foreach (array_keys($hosts) as $h) {
            $q = preg_quote($h, '~');
            $html = preg_replace('~https?:\\?/\\?/' . $q . '~i', '{{SITE_URL}}', $html); // JSON-escaped http:\/\/host
            $html = preg_replace('~https?://' . $q . '~i', '{{SITE_URL}}', $html);
            $html = preg_replace('~//' . $q . '~i', '{{SITE_URL}}', $html);
        }
        $html = str_replace(array('//{{SITE_URL_HOST}}', '{{SITE_URL_HOST}}'), '{{SITE_URL}}', $html);
        // 2) probe clock tooltip: title="8:41 pm" / title="20:41"
        $html = preg_replace('~\s+title=(["\'])\s*\d{1,2}:\d{2}(?::\d{2})?\s*(?:[ap]\.?m\.?)?\s*\1~i', '', $html);
        // 2b) <body> class 里的 per-term class（category-<slug> / tag-<id|slug>）去掉
        //     （探针的分类/标签不适用于其它页面；body class 里不会有 tag-links 之类合法 class）
        $html = preg_replace_callback('~(<body\b[^>]*\bclass=["\'])([^"\']*)(["\'])~i', function ($m) {
            $c = preg_replace('~\s*\b(?:category-[a-zA-Z0-9%_-]+|tag-[a-zA-Z0-9%_-]+)\b~', '', $m[2]);
            return $m[1] . trim(preg_replace('~\s+~', ' ', $c)) . $m[3];
        }, $html, 1);
        // 3) probe ids + "current page" highlight classes
        $html = preg_replace('~\bpostid-\d+\b~', '', $html);
        $html = preg_replace('~\b(?:current-menu-item|current_page_item|current-post-ancestor|current-post-parent|current-menu-parent|current-menu-ancestor)\b~', '', $html);
        $html = preg_replace_callback('~class=(["\'])([^"\']*)\1~i', function ($m) {
            $clean = trim(preg_replace('~\s+~', ' ', $m[2]));
            return $clean === '' ? '' : 'class="' . $clean . '"';
        }, $html);
        // 4) links that embed the probe slug
        $html = preg_replace('~<link\b[^>]*cfseprobe[^>]*>~i', '', $html);
        // 4b) 探针/动态端点 link：单篇 wp-json（含探针文章 id）、RSD、站点 API 发现
        $html = preg_replace('~<link\b[^>]*wp-json/wp/v2/posts/[0-9]+[^>]*>~i', '', $html);
        $html = preg_replace('~<link\b[^>]*rel=["\']EditURI["\'][^>]*>~i', '', $html);
        $html = preg_replace('~<link\b[^>]*rel=["\']https://api\.w\.org/["\'][^>]*>~i', '', $html);
        // 5) 探针残留（token 标题 / 探针评论文本 / 探针 slug）→ 清空
        //    兜住「最近评论 / 最近文章」widget 之类会把探针内容渲染进模板的场景
        $html = preg_replace('~CFSEPROBE[A-Z0-9]*-TITLE~', '', $html);
        $html = preg_replace('~CFSEPROBE-COMMENT-[A-Z]\b~', '', $html);
        $html = preg_replace('~CFSEPROBE-C[A-Z]\b~', '', $html);
        $html = preg_replace('~cfseprobe[a-z0-9-]*~', '', $html);
        return $html;
    }
}

/**
 * 主导航 → {{#IF_MENU}}{{MENU}}{{/IF_MENU}}
 *
 * 值锚：WP 给每个菜单项输出 menu-item-<ID>（id 与 class 各一次），据此定位「第一个 → 最后一个菜单项」
 * 的范围，再向上取最近的 <ul> 作菜单根，整段换成占位符。**保留 <ul> 的父容器外壳**
 * （与 FSE 提取器对 wp:navigation 的处理一致：<nav>{{#IF_MENU}}…{{/IF_MENU}}</nav>）。
 *
 * 返回 null 表示「没动」（没菜单信息 / 定位失败）—— 调用方据此决定要不要记警告。
 */
function cfse_skin_nav_menu($html, $ctx) {
    $ids = isset($ctx['menu_item_ids']) ? (array) $ctx['menu_item_ids'] : array();
    if (!$ids) return null;

    $first = null; $last = null;
    foreach ($ids as $id) {
        $needle = 'menu-item-' . (int) $id;
        $p = strpos($html, $needle);
        if ($p !== false && ($first === null || $p < $first)) $first = $p;
        $q = strrpos($html, $needle);
        if ($q !== false && ($last === null || $q > $last)) $last = $q;
    }
    if ($first === null || $last === null || $last <= $first) return null;

    // 从第一个菜单项向上找最近的 <ul>
    $ul = strrpos(substr($html, 0, $first), '<ul');
    if ($ul === false) return null;
    $gt = strpos($html, '>', $ul);
    if ($gt === false) return null;
    $close = cfse_html_find_close($html, $gt + 1, 'ul');
    if (!$close || $close['close_start'] < $last) return null;   // 没包住全部菜单项 ⇒ 放弃（宁可不换）

    return substr($html, 0, $ul) . '{{#IF_MENU}}{{MENU}}{{/IF_MENU}}' . substr($html, $close['close_end']);
}

/** 站点级占位替换（URL / 名称 / 描述） */
function cfse_skin_replace_site($html, $ctx) {
    if (!empty($ctx['site_url'])) {
        $enc = str_replace(array(':', '/'), array('%3A', '%2F'), $ctx['site_url']);
        $esc = str_replace('/', '\/', $ctx['site_url']);
        $html = str_replace(array($ctx['site_url'], $enc, $esc), array('{{SITE_URL}}', '{{SITE_URL}}', '{{SITE_URL}}'), $html);
        $host = parse_url($ctx['site_url'], PHP_URL_HOST);
        if ($host) {
            $html = str_replace(array('//' . $host, '\/\/' . $host), array('//{{SITE_URL_HOST}}', '\/\/{{SITE_URL_HOST}}'), $html);
        }
    }
    if (!empty($ctx['site_name'])) {
        $variants = array_unique(array($ctx['site_name'], htmlspecialchars($ctx['site_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
        foreach ($variants as $v) {
            if ($v !== '') $html = str_replace($v, '{{SITE_NAME}}', $html);
        }
    }
    if (!empty($ctx['site_desc'])) {
        $variants = array_unique(array($ctx['site_desc'], htmlspecialchars($ctx['site_desc'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));
        foreach ($variants as $v) {
            if ($v !== '') $html = str_replace($v, '{{SITE_DESC}}', $html);
        }
    }
    // 探针自身的 permalink 路径（slug）→ {{POST_URL}}。
    // 必须放在 site_url 替换之后：此时 host 已是 {{SITE_URL}}，只需再换 slug 段。
    // 实测漏网形态：twentynineteen 的 <a rel="bookmark" href="{{SITE_URL}}/cfseprobeXX-title/"> 及其
    // #respond 变体（长形态先替换，避免被短的吃掉）。
    if (!empty($ctx['permalink'])) {
        $pp = trim((string) parse_url($ctx['permalink'], PHP_URL_PATH), '/');
        if ($pp !== '') {
            $html = str_replace(
                array(
                    '{{SITE_URL}}/' . $pp . '/#respond',
                    '{{SITE_URL}}/' . $pp . '/feed/',
                    '{{SITE_URL}}/' . $pp . '/',
                ),
                array(
                    '{{POST_URL}}/#respond',
                    '{{POST_URL}}/feed/',
                    '{{POST_URL}}/',
                ),
                $html
            );
        }
    }
    return $html;
}
function cfse_skin_strip_probe_links($html, $ctx) {
    $html = preg_replace('~<link[^>]*rel=["\']alternate["\'][^>]*type=["\']application/json\+oembed["\'][^>]*>~i', '', $html);
    $html = preg_replace('~<link[^>]*type=["\']application/json\+oembed["\'][^>]*rel=["\']alternate["\'][^>]*>~i', '', $html);
    $html = preg_replace('~<link[^>]*type=["\']text/xml\+oembed["\'][^>]*>~i', '', $html);
    $html = preg_replace('~<link[^>]*rel=["\']shortlink["\'][^>]*>~i', '', $html);
    $probe_feed = '{{SITE_URL}}/' . trim((string) parse_url($ctx['permalink'], PHP_URL_PATH), '/') . '/feed/';
    $html = str_replace(array('?_cfse_internal=1', '&_cfse_internal=1', '_cfse_internal=1'), '', $html);
    $html = preg_replace('~<link[^>]*href=["\']' . preg_quote($probe_feed, '~') . '["\'][^>]*>~i', '', $html);
    return $html;
}

/**
 * 侧边栏某区块 → 占位符（主题无关回退）：定位含指定 widget class 的容器，
 * 把它内部第一个 <ul> 的内容换成占位符。命中返回 true。
 */
function cfse_skin_sidebar_replace(&$html, $classes, $ph) {
    foreach ((array) $classes as $cls) {
        $pat = '~<(li|div|section)\b[^>]*class=["\'][^"\']*' . preg_quote($cls, '~') . '[^"\']*["\'][^>]*>~i';
        if (!preg_match($pat, $html, $m, PREG_OFFSET_CAPTURE)) continue;
        $cstart = $m[0][1];
        $gt = strpos($html, '>', $cstart);
        if ($gt === false) continue;
        $inner = substr($html, $cstart + 1, $gt - $cstart - 1);
        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9]*)/', $inner, $tm)) continue;
        $cclose = cfse_html_find_close($html, $gt + 1, strtolower($tm[1]));
        if (!$cclose) continue;
        $seg_start = $gt + 1;
        $seg = substr($html, $seg_start, $cclose['close_start'] - $seg_start);
        if (!preg_match('~<ul\b[^>]*>~i', $seg, $um, PREG_OFFSET_CAPTURE)) continue;
        $ul_content_start = $seg_start + $um[0][1] + strlen($um[0][0]);
        $ul_close = cfse_html_find_close($html, $ul_content_start, 'ul');
        if (!$ul_close) continue;
        $html = substr($html, 0, $ul_content_start) . $ph . substr($html, $ul_close['close_start']);
        return true;
    }
    return false;
}

/** 定位侧边栏某 widget（按 widget id），提取完整 <li> 骨架，并把 <ul> 内部换成占位符。
 * @param string &$html      页面 HTML（原地改）
 * @param string $wid_prefix widget id 前缀，如 'categories'（匹配 id="categories-N"）
 * @param string $ph         占位符（如 {{SIDEBAR_CATEGORIES}}）
 * @param string $store_key  $GLOBALS['cfse_sidebar_tpls'] 里的键名（如 'categories'）
 * @param array  $ctx        上下文（站点占位替换用）
 * @return bool 是否命中
 */
function cfse_skin_sidebar_by_widget(&$html, $wid_prefix, $ph, $store_key, $ctx) {
    // a. 按 widget id 定位块（<li id="xxx-N" ...> 或 <div id="xxx-N" ...>）
    $pat = '~<(li|div|section|aside)\b[^>]*id=["\']' . preg_quote($wid_prefix, '~') . '-\d+["\'][^>]*>~i';
    if (!preg_match($pat, $html, $m, PREG_OFFSET_CAPTURE)) return false;
    $tag = strtolower($m[1][0]);
    $start = $m[0][1];
    $gt = strpos($html, '>', $start);
    if ($gt === false) return false;
    $close = cfse_html_find_close($html, $gt + 1, $tag);
    if (!$close) return false;
    $block = substr($html, $start, $close['close_end'] - $start);

    // b. 块内找 <ul>，取其内容（$um 偏移是相对 $block，$block 起点 = $start）
    if (!preg_match('~<ul\b[^>]*>~i', $block, $um, PREG_OFFSET_CAPTURE)) return false;
    $ul_start = $start + $um[0][1];
    $ul_gt = strpos($html, '>', $ul_start);
    if ($ul_gt === false) return false;
    $ul_close = cfse_html_find_close($html, $ul_gt + 1, 'ul');
    if (!$ul_close) return false;
    $list_content = substr($html, $ul_gt + 1, $ul_close['close_start'] - $ul_gt - 1);

    // c. 提取第一个 <li> 完整骨架（含嵌套，找第一个 <li> 到其闭合）
    $skeleton = '';
    if (preg_match('~<li\b[^>]*>~i', $list_content, $lm, PREG_OFFSET_CAPTURE)) {
        $li_start = $lm[0][1];
        $li_gt = strpos($list_content, '>', $li_start);
        if ($li_gt !== false) {
            $li_close = cfse_html_find_close($list_content, $li_gt + 1, 'li');
            if ($li_close) {
                $skeleton = substr($list_content, $li_start, $li_close['close_end'] - $li_start);
            }
        }
    }
    if ($skeleton === '') {
        // 没有 <li>（flat 输出），取第一个 <a> 到闭合
        if (preg_match('~<a\b[^>]*>~i', $list_content, $am, PREG_OFFSET_CAPTURE)) {
            $a_start = $am[0][1];
            $a_gt = strpos($list_content, '>', $a_start);
            if ($a_gt !== false) {
                $a_close = cfse_html_find_close($list_content, $a_gt + 1, 'a');
                if ($a_close) $skeleton = substr($list_content, $a_start, $a_close['close_end'] - $a_start);
            }
        }
    }
    if ($skeleton === '') return false;

    // d. 骨架里数据 → 占位符（URL / 名字 / 计数）。用第一个条目的实际值做基准不可靠，
    //    统一用正则：href="..." → {{ITEM_URL}}，标签文本 → {{ITEM_NAME}}，括号计数 → {{ITEM_COUNT}}
    $sk2 = $skeleton;
    $sk2 = preg_replace('~(href|action)=(["\'])[^"\']*\2~i', '$1=$2{{ITEM_URL}}$2', $sk2, 1);
    // 计数括号（如 (6) / (6 篇文章)）→ {{ITEM_COUNT}}
    $sk2 = preg_replace('~\(\s*\d+[^)]*\)~u', '({{ITEM_COUNT}})', $sk2, 1);
    // 标签文本 → {{ITEM_NAME}}（匹配标签闭合前的文本）
    $sk2 = preg_replace('~>\s*[^<>]{1,80}\s*</a>~u', '>{{ITEM_NAME}}</a>', $sk2, 1);
    // term id 类名（cat-item-N / tag-link-N）无对应静态数据 → 去掉（保留基类，避免所有条目都带同一个 id）
    $sk2 = preg_replace('~\s*\bcat-item-\d+\b~', '', $sk2);
    $sk2 = preg_replace('~\s*\btag-link-\d+\b~', '', $sk2);
    // 排序位置 / 字号 → 占位符（Worker 端按序/按算法填）
    $sk2 = preg_replace('~\btag-link-position-\d+\b~', 'tag-link-position-{{ITEM_POSITION}}', $sk2);
    $sk2 = preg_replace('~font-size:\s*[\d.]+pt~', 'font-size: {{ITEM_SIZE}}pt', $sk2);
    // aria-label 里的首条目名称 → {{ITEM_NAME}}
    $sk2 = preg_replace('~(aria-label=["\'])[^"\']*(["\'])~', '$1{{ITEM_NAME}} ({{ITEM_COUNT}} items)$2', $sk2);
    if ($sk2 !== $skeleton) $skeleton = $sk2;

    // e. 站点级替换（骨架里可能含站点 URL）
    $skeleton = cfse_skin_replace_site($skeleton, $ctx);

    // f. 存骨架
    if (!isset($GLOBALS['cfse_sidebar_tpls']) || !is_array($GLOBALS['cfse_sidebar_tpls'])) {
        $GLOBALS['cfse_sidebar_tpls'] = array();
    }
    $GLOBALS['cfse_sidebar_tpls'][$store_key] = $skeleton;

    // g. 把 <ul> 内部换成占位符（保留 <ul> 属性与外层包装 = 主题模板）
    $html = substr($html, 0, $ul_gt + 1) . $ph . substr($html, $ul_close['close_start']);
    return true;
}

/** 从条目区内容里提取前 N 个「同级重复单元」（用嵌套计数找每个单元闭合）。 */
function cfse_skin_extract_units($content, $max = 4) {
    $units = array();
    if (!preg_match_all('~<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>~', $content, $mm)) return $units;
    $cnt = array();
    foreach ($mm[1] as $t) { $t = strtolower($t); $cnt[$t] = isset($cnt[$t]) ? $cnt[$t] + 1 : 1; }
    // 单元标签 = 优先块级容器且「重复出现 ≥2 次」的（避免把首个 <h2>标题 当单元）
    $cands = array('li', 'article', 'tr', 'figure', 'div', 'section', 'p', 'a', 'span');
    $tag = '';
    foreach ($cands as $t) { if (isset($cnt[$t]) && $cnt[$t] >= 2) { $tag = $t; break; } }
    if ($tag === '') return $units;
    $pos = 0;
    while (count($units) < $max) {
        if (!preg_match('~<' . $tag . '\b[^>]*>~i', $content, $m2, PREG_OFFSET_CAPTURE, $pos)) break;
        $st = $m2[0][1];
        $g = strpos($content, '>', $st);
        if ($g === false) break;
        $c = cfse_html_find_close($content, $g + 1, $tag);
        if (!$c) break;
        $units[] = substr($content, $st, $c['close_end'] - $st);
        $pos = $c['close_end'];
    }
    return $units;
}

/** 以第一个单元为骨架，用「其余单元」对照确认「哪些值确属 per-item 数据」——
 *  只替换「确属变化」的值，**格式原样保留**（不像 v1 那样重建 aria-label）。
 *  替换顺序：长/具体的值优先（href>名称>字号>位置>括号数字），避免短值误伤。
 */
function cfse_skin_units_skeleton($units, $ctx) {
    $u1 = $units[0];
    $others = array_slice($units, 1);
    $s = $u1;
    // 「值级对照」：用同一个正则在各单元取「同位置」的值；只要任一单元不同 ⇒ 该位是 per-item 数据。
    // （不能用「值是否出现过」判断——值碰巧相同会误判，如两个 tag 都 3 items / 同字号）
    $varies = function ($pat, $val, $idx = 1) use ($others) {
        foreach ($others as $o) {
            if (preg_match($pat, $o, $om) && isset($om[$idx]) && $om[$idx] !== $val) return true;
        }
        return false;
    };

    // 1) href 的完整 URL → {{ITEM_URL}}
    if (preg_match('~\bhref=["\']([^"\']+)["\']~i', $u1, $hm)) {
        if ($varies('~\bhref=["\']([^"\']+)["\']~i', $hm[1])) $s = str_replace($hm[1], '{{ITEM_URL}}', $s);
    }
    // 2) <a> 标签文本 → {{ITEM_NAME}}（一并替换 aria-label 里同名处）
    if (preg_match('~<a\b[^>]*>([^<>]+)</a>~i', $u1, $am)) {
        $name = trim($am[1]);
        if ($name !== '' && $varies('~<a\b[^>]*>([^<>]+)</a>~i', $name)) $s = str_replace($name, '{{ITEM_NAME}}', $s);
    }
    // 3) font-size: Npt → {{ITEM_SIZE}}
    if (preg_match('~font-size:\s*([\d.]+)pt~', $u1, $fm)) {
        if ($varies('~font-size:\s*([\d.]+)pt~', $fm[1])) $s = str_replace($fm[1] . 'pt', '{{ITEM_SIZE}}pt', $s);
    }
    // 4) tag-link-position-N → {{ITEM_POSITION}}
    if (preg_match('~\btag-link-position-(\d+)\b~', $u1, $pm)) {
        if ($varies('~\btag-link-position-(\d+)\b~', $pm[1])) $s = str_replace('tag-link-position-' . $pm[1], 'tag-link-position-{{ITEM_POSITION}}', $s);
    }
    // 5) 括号内数字 → {{ITEM_COUNT}}（只替换括号里的那个数字，保留 "(N items)" 的 " items"）
    if (preg_match('~\(\s*(\d+)\s*[^)]*\)~', $u1, $cm)) {
        if ($varies('~\(\s*(\d+)\s*[^)]*\)~', $cm[1])) {
            $s = preg_replace('~(\(\s*)' . preg_quote($cm[1], '~') . '(\s*[^)]*\))~', '$1{{ITEM_COUNT}}$2', $s, 1);
        }
    }
    // 6) term id 类名（cat-item-N / tag-link-N）去掉（静态站无对应数据）
    $s = preg_replace('~\s*\b(?:cat-item|tag-link)-\d+\b~', '', $s);
    return $s;
}

/** 侧边栏骨架 v2（对照驱动）：块级 = 重复单元框架；值级 = 单元间差异。
 *  与 cfse_skin_sidebar_by_widget()（v1）并存，v1 保留可回退。
 */
function cfse_skin_sidebar_by_units(&$html, $wid_prefix, $ph, $store_key, $ctx) {
    $pat = '~<(li|div|section|aside)\b[^>]*id=["\']' . preg_quote($wid_prefix, '~') . '-\d+["\'][^>]*>~i';
    if (!preg_match($pat, $html, $m, PREG_OFFSET_CAPTURE)) return false;
    $tag = strtolower($m[1][0]);
    $start = $m[0][1];
    $gt = strpos($html, '>', $start);
    if ($gt === false) return false;
    $close = cfse_html_find_close($html, $gt + 1, $tag);
    if (!$close) return false;
    $block = substr($html, $start, $close['close_end'] - $start);

    // 条目区：优先 <ul>；无则退回 widget 内容
    $list_start = -1; $list_end = -1; $list_outer_start = -1;
    if (preg_match('~<ul\b[^>]*>~i', $block, $um, PREG_OFFSET_CAPTURE)) {
        $ul_start = $start + $um[0][1];
        $ul_gt = strpos($html, '>', $ul_start);
        if ($ul_gt !== false) {
            $ul_close = cfse_html_find_close($html, $ul_gt + 1, 'ul');
            if ($ul_close) { $list_start = $ul_gt + 1; $list_end = $ul_close['close_start']; $list_outer_start = $ul_start; }
        }
    }
    if ($list_start < 0) { $list_start = $gt + 1; $list_end = $close['close_start']; }
    $list_content = substr($html, $list_start, $list_end - $list_start);

    $units = cfse_skin_extract_units($list_content, 4);
    if (empty($units)) return false;

    $skeleton = cfse_skin_units_skeleton($units, $ctx);

    if (!isset($GLOBALS['cfse_sidebar_tpls']) || !is_array($GLOBALS['cfse_sidebar_tpls'])) {
        $GLOBALS['cfse_sidebar_tpls'] = array();
    }
    $GLOBALS['cfse_sidebar_tpls'][$store_key] = cfse_skin_replace_site($skeleton, $ctx);
    // 记录单元数/是否只取到一个（置信度线索）
    $GLOBALS['cfse_sidebar_units_count'][$store_key] = count($units);

    $html = substr($html, 0, $list_start) . $ph . substr($html, $list_end);
    return true;
}

/** 侧边栏三块（分类/归档/标签云）→ 占位符 + 完整 <li> 骨架提取。
 * 列表页（cfse_skin_page_shell）与单页（cfse_skin_single_shell）共用。返回 warns 数组。
 */
function cfse_skin_replace_sidebars(&$html, $ctx) {
    $warns = array();
    $n = 0;
    if (cfse_skin_sidebar_by_units($html, 'categories', '{{SIDEBAR_CATEGORIES}}', 'categories', $ctx)) $n = 1;
    if (!$n) $html = preg_replace('~(<ul\b[^>]*class=["\'][^"\']*wp-block-categories-list[^"\']*["\'][^>]*>).*?(</ul>)~s', '$1{{SIDEBAR_CATEGORIES}}$2', $html, -1, $n);
    if (!$n) $html = preg_replace('~(<ul\b[^>]*class=["\'][^"\']*cat-item[^"\']*["\'][^>]*>).*?(</ul>)~s', '$1{{SIDEBAR_CATEGORIES}}$2', $html, -1, $n);
    if (!$n && cfse_skin_sidebar_replace($html, array('widget_categories', 'wp-block-categories'), '{{SIDEBAR_CATEGORIES}}')) $n = 1;
    if (!$n) $warns[] = '未找到侧边栏分类列表容器，{{SIDEBAR_CATEGORIES}} 缺失';
    $n = 0;
    if (cfse_skin_sidebar_by_units($html, 'archives', '{{SIDEBAR_ARCHIVES}}', 'archives', $ctx)) $n = 1;
    if (!$n) $html = preg_replace('~(<ul\b[^>]*class=["\'][^"\']*(wp-block-archives|widget_archive)[^"\']*["\'][^>]*>).*?(</ul>)~s', '$1{{SIDEBAR_ARCHIVES}}$2', $html, -1, $n);
    if (!$n) $html = preg_replace('~(<li id="archives-2"[^>]*>.*?<ul>).*?(</ul>)~s', '$1{{SIDEBAR_ARCHIVES}}$2', $html, -1, $n);
    if (!$n && cfse_skin_sidebar_replace($html, array('widget_archive', 'wp-block-archives'), '{{SIDEBAR_ARCHIVES}}')) $n = 1;
    if (!$n) $warns[] = '未找到侧边栏归档列表容器，{{SIDEBAR_ARCHIVES}} 缺失';
    $n = 0;
    if (cfse_skin_sidebar_by_units($html, 'tag_cloud', '{{SIDEBAR_TAGS}}', 'tags', $ctx)) $n = 1;
    if (!$n) $html = preg_replace('~(<ul\b[^>]*class=["\'][^"\']*(wp-tag-cloud|tagcloud)[^"\']*["\'][^>]*>).*?(</ul>)~s', '$1{{SIDEBAR_TAGS}}$2', $html, -1, $n);
    if (!$n && cfse_skin_sidebar_replace($html, array('widget_tag_cloud', 'wp-block-tag-cloud', 'wp-tag-cloud', 'tagcloud'), '{{SIDEBAR_TAGS}}')) $n = 1;
    if (!$n) $warns[] = '未找到侧边栏标签云容器，{{SIDEBAR_TAGS}} 缺失';
    return $warns;
}

/** 找同时包含 $posA/$posB 的最小公共祖先元素（用于定位“分类+标签”所在的那个容器） */
function cfse_skin_lca($html, $posA, $posB) {
    $cur = $posA;
    for ($i = 0; $i < 12 && $cur > 0; $i++) {
        $loc = cfse_html_loc_tag($html, $cur);
        if (!$loc) return null;
        $close = cfse_html_find_close($html, $loc['content_start'], $loc['tag']);
        if ($close && $close['close_end'] >= $posB) return $loc;
        $cur = $loc['start'] - 1;
    }
    return null;
}

/** 页面壳：列表区 → {{CONTENT}}、分页 → 占位符、侧边栏 → 占位符、站点级替换 */
function cfse_skin_page_shell($html, $ctx, $type) {
    $warns = array();
    $html = cfse_skin_strip_probe_links($html, $ctx);

    // 1) 列表区 → {{CONTENT}}（第一个条目容器开始 → 最后一个条目容器结束）
    $containers = cfse_skin_enum_containers($html);
    if (count($containers) >= 1) {
        $first = $containers[0];
        $last = $containers[count($containers) - 1];
        $html = substr($html, 0, $first['start']) . "\n\t{{CONTENT}}\n" . substr($html, $last['close_end']);
    } else {
        $warns[] = '未枚举到条目容器（id="post-N"），{{CONTENT}} 未自动放置';
    }
    // 条目注释锚（<!-- #post-N -->）紧跟在结束标签之后，不在上面的替换区间里，这里清掉
    $html = preg_replace('~<!--\s*#post-\d+\s*-->~', '', $html);

    // 分类/标签 feed link（静态站没有这些路由，访问返回 404）→ 删除
    $html = preg_replace('~<link\b[^>]*rel=["\']alternate["\'][^>]*href=["\'][^"\']*/(category|tag)/[^"\']*/feed/["\'][^>]*/?>~i', '', $html);
    $html = preg_replace('~<link\b[^>]*href=["\'][^"\']*/(category|tag)/[^"\']*/feed/["\'][^>]*rel=["\']alternate["\'][^>]*/?>~i', '', $html);

    // 页面标题 → {{TITLE}}
    // 主题无关做法：用"分类名 / 标签名 / 年月文本"反查承载它的标题元素（h1~h6），不依赖主题 class；
    // 没命中再退回常见的 h1.page-title。
    $title_done = false;
    foreach ((array) $ctx['title_needles'] as $needle) {
        if ($needle === '') continue;
        $pos = strpos($html, $needle);
        if ($pos === false) continue;
        $loc = cfse_html_loc_tag($html, $pos);
        $guard = 0;
        while ($loc && !preg_match('/^h[1-6]$/', $loc['tag']) && $guard++ < 8) {
            $loc = cfse_html_loc_tag($html, $loc['start'] - 1);
        }
        if ($loc && preg_match('/^h[1-6]$/', $loc['tag'])) {
            $close = cfse_html_find_close($html, $loc['content_start'], $loc['tag']);
            if ($close) {
                $html = substr($html, 0, $loc['content_start']) . '{{TITLE}}' . substr($html, $close['close_start']);
                $title_done = true;
                break;
            }
        }
    }
    if (!$title_done && preg_match('~<h1\b[^>]*class=["\'][^"\']*page-title[^"\']*["\'][^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $p0 = $m[0][1];
        $gt = strpos($html, '>', $p0);
        if ($gt !== false) {
            $inner = substr($html, $p0 + 1, $gt - $p0 - 1);
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9]*)/', $inner, $mm)) {
                $close = cfse_html_find_close($html, $gt + 1, strtolower($mm[1]));
                if ($close) {
                    $html = substr($html, 0, $gt + 1) . '{{TITLE}}' . substr($html, $close['close_start']);
                    $title_done = true;
                }
            }
        }
    }
    if (!$title_done && $type !== 'home') {
        $warns[] = '未定位到归档标题元素（token 与 h1.page-title 均未命中），{{TITLE}} 缺失';
    }

    // <title> 里的分类 / 标签名 → {{TITLE}}（如 "Resourse | 站点名"）
    $name_hits = array_merge((array) $ctx['cat_names'], (array) $ctx['tag_names']);
    if (!empty($name_hits)) {
        $html = preg_replace_callback('~<title>(.*?)</title>~is', function ($m) use ($name_hits) {
            $t = $m[1];
            foreach ($name_hits as $nm) {
                if ($nm !== '') $t = str_replace($nm, '{{TITLE}}', $t);
            }
            return '<title>' . $t . '</title>';
        }, $html);
    }

    // 日期归档页的 <title> 会把「月份 + 年份」固化成抓取当月（实测 "September | 2026 | 站点名" /
    // "September 2026 &#8211; 站点名"）⇒ 换成 {{TITLE}}，与分类/标签页对齐。
    // 仅对 archive 生效：single 的 <title> 是 {{POST_TITLE}}，文章标题里的月份年份不该被改写。
    if ($type === 'archive') {
        $html = preg_replace_callback('~<title>(.*?)</title>~is', function ($m) {
            $t = preg_replace(
                '~\b(January|February|March|April|May|June|July|August|September|October|November|December)\s*(?:[|]\s*|(?:&#8211;|&#8212;|&ndash;|&mdash;|[-–—])\s*)?\d{4}\b~i',
                '{{TITLE}}',
                $m[1]
            );
            return '<title>' . $t . '</title>';
        }, $html);
    }

    // 2) 分页容器 → {{PAGINATION_PREV}} / {{PAGINATION_NEXT}}
    // 主题无关做法：用 href 里形如 /page/N/（或 paged=N）的分页链接反查其容器；
    // 没命中再退回 nav-previous / nav-next 类名。
    $cnt_prev = 0; $cnt_next = 0;
    if (preg_match('~<a\b[^>]*href=["\'][^"\']*(?:/page/\d+/?|paged=\d+)[^"\']*["\'][^>]*>~i', $html, $pgm, PREG_OFFSET_CAPTURE)) {
        $loc = cfse_html_loc_tag($html, $pgm[0][1]);
        $guard = 0;
        while ($loc && !in_array($loc['tag'], array('div', 'nav', 'section', 'p'), true) && $guard++ < 6) {
            $loc = cfse_html_loc_tag($html, $loc['start'] - 1);
        }
        if ($loc) {
            $close = cfse_html_find_close($html, $loc['content_start'], $loc['tag']);
            if ($close) {
                $html = substr($html, 0, $loc['content_start'])
                    . "\n\t<div class=\"nav-previous\">{{PAGINATION_PREV}}</div>\n\t<div class=\"nav-next\">{{PAGINATION_NEXT}}</div>\n"
                    . substr($html, $close['close_start']);
                $cnt_prev = 1;
                $cnt_next = 1;
            }
        }
    }
    if (!$cnt_prev && !$cnt_next) {
        $html = preg_replace_callback('~(<(div|nav|span)\b[^>]*class=["\'][^"\']*nav-previous[^"\']*["\'][^>]*>).*?(</\2>)~s', function ($m) use (&$cnt_prev) {
            $cnt_prev++;
            return '<div class="nav-previous">{{PAGINATION_PREV}}</div>';
        }, $html);
        $html = preg_replace_callback('~(<(div|nav|span)\b[^>]*class=["\'][^"\']*nav-next[^"\']*["\'][^>]*>).*?(</\2>)~s', function ($m) use (&$cnt_next) {
            $cnt_next++;
            return '<div class="nav-next">{{PAGINATION_NEXT}}</div>';
        }, $html);
    }

    // 3) 侧边栏三块 → 占位符（公共函数，单页共用）
    foreach (cfse_skin_replace_sidebars($html, $ctx) as $w) { $warns[] = $w; }

    // 4) 站点级替换
    $html = cfse_skin_replace_site($html, $ctx);

    // 4b) 主导航 → {{MENU}}（值锚：menu-item-<ID>）
    $nav = cfse_skin_nav_menu($html, $ctx);
    if ($nav !== null) {
        $html = $nav;
    } elseif (!empty($ctx['menu_item_ids'])) {
        $warns[] = '未定位到导航菜单容器（menu-item-<ID> 值锚未命中），{{MENU}} 缺失';
    }

    // 5) 头部里可能残留的探针标题（<title> 等）
    $html = str_replace($ctx['probe_title'], '{{POST_TITLE}}', $html);

    return array($html, $warns);
}

/**
 * 从页面壳里提取"分页容器"整段（含 {{PAGINATION_PREV}} / {{PAGINATION_NEXT}}），
 * 用于给"文章少、WP 没输出分页容器"的归档页壳补齐分页结构。
 */
function cfse_skin_extract_pagination($ref) {
    $pos = strpos($ref, '{{PAGINATION_NEXT}}');
    if ($pos === false) $pos = strpos($ref, '{{PAGINATION_PREV}}');
    if ($pos === false) return '';
    $inner = cfse_html_loc_tag($ref, $pos);
    if (!$inner) return '';
    $outer = cfse_html_loc_tag($ref, $inner['start']);
    if ($outer && $outer['tag'] !== 'body' && $outer['tag'] !== 'html') {
        $close = cfse_html_find_close($ref, $outer['content_start'], $outer['tag']);
        if ($close) return substr($ref, $outer['start'], $close['close_end'] - $outer['start']);
    }
    $close = cfse_html_find_close($ref, $inner['content_start'], $inner['tag']);
    if ($close) return substr($ref, $inner['start'], $close['close_end'] - $inner['start']);
    return '';
}

/** 单页壳：token → 占位符 + 去掉评论/回应/上下篇导航 */
/** 单页评论区：保留结构 + 元数据 ID 占位化 + 条件块。
 * - #comments 容器保留（{{#IF_COMMENTS}}...{{/IF_COMMENTS}}，Worker 端有评论则填充）
 * - 评论条目的元数据 ID（li-comment-N / comment-N / replytocom / data-*）占位化/去掉
 */
/**
 * 单页上下篇导航（值锚优先）。
 *
 * A2（主探针）的相邻两篇 A1（更新）/ A3（更早）在抓取时就是已知字符串：直接拿它们的
 * permalink / 标题 token 当锚点，从渲染结果里精确定位那两条链接 —— 与主题的 class 名、
 * 容器标签（div|nav|span|section…）、箭头位置全都无关。命中后保留主题结构（rel / 箭头
 * span / 文案），只做 href → {{PREV_URL}}/{{NEXT_URL}}、标题 token → {{PREV_TITLE}}/
 * {{NEXT_TITLE}}，并用 {{#IF_PREV}}/{{#IF_NEXT}} 包住该侧；某侧无上下篇时不显示。
 * ctx 可选字段：prev_permalink / prev_title / next_permalink / next_title。
 * 未命中的一侧回退旧启发式（class 含 nav-previous/nav-next 的 div|nav 容器 + 去 meta-nav 猜标题）。
 */
/** 邻篇 permalink 的 href 候选：原样 / 去尾斜杠 / 相对路径 / percent-encoded 变体。 */
function cfse_skin_prevnext_candidates($permalink) {
    $out = array();
    $p = trim((string) $permalink);
    if ($p === '') return $out;
    $out[] = $p;
    $out[] = rtrim($p, '/');
    $path = (string) parse_url($p, PHP_URL_PATH);
    if ($path !== '' && $path !== '/') {
        $out[] = $path;
        $out[] = rtrim($path, '/');
        $segs = explode('/', $path);
        $enc = array();
        foreach ($segs as $seg) { $enc[] = rawurlencode($seg); }
        $encp = implode('/', $enc);
        $out[] = $encp;
        $out[] = rtrim($encp, '/');
        $out[] = strtolower($encp);
        $out[] = rtrim(strtolower($encp), '/');
    }
    $seen = array();
    $res = array();
    foreach ($out as $s) {
        if (strlen($s) < 4) continue;
        if (isset($seen[$s])) continue;
        $seen[$s] = true;
        $res[] = $s;
    }
    return $res;
}

/** $href 是否指向 $permalink（忽略 query/fragment；站内相对路径也算命中）。 */
function cfse_skin_prevnext_href_matches($href, $permalink) {
    $href = trim((string) $href);
    if ($href === '') return false;
    $parts = preg_split('~[?#]~', $href);
    $base = isset($parts[0]) ? $parts[0] : $href;
    foreach (cfse_skin_prevnext_candidates($permalink) as $c) {
        if ($href === $c || $base === $c) return true;
    }
    return false;
}

/** 页面里所有 <a>（起点 / 标签 / href / rel），供上下篇值锚定位。 */
function cfse_skin_prevnext_anchors($html) {
    $out = array();
    if (!preg_match_all('~<a\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) return $out;
    foreach ($m[0] as $cap) {
        $tag = $cap[0];
        $href = '';
        $rel = '';
        if (preg_match('~\bhref\s*=\s*["\']([^"\']*)["\']~i', $tag, $hm)) { $href = $hm[1]; }
        if (preg_match('~\brel\s*=\s*["\']([^"\']*)["\']~i', $tag, $rm)) { $rel = strtolower(trim($rm[1])); }
        $out[] = array('start' => $cap[1], 'tag' => $tag, 'href' => $href, 'rel' => $rel);
    }
    return $out;
}

/** 从 $pos 向上找 class 含 nav/pagination/pager 的祖先（= 这一侧的导航容器）。 */
function cfse_skin_prevnext_nav_ancestor($html, $pos) {
    $cur = $pos;
    for ($i = 0; $i < 12 && $cur > 0; $i++) {
        $loc = cfse_html_loc_tag($html, $cur);
        if (!$loc) return null;
        $close = cfse_html_find_close($html, $loc['content_start'], $loc['tag']);
        if ($close && $close['close_end'] >= $pos) {
            $tag_html = substr($html, $loc['start'], $loc['content_start'] - $loc['start']);
            if (preg_match('~class\s*=\s*["\'][^"\']*(nav|pagination|pager)[^"\']*["\']~i', $tag_html)) return $loc;
        }
        if ($loc['start'] <= 0) break;
        $cur = $loc['start'] - 1;
    }
    return null;
}

/** 把 $a_start 处的 <a>（或它所在的导航容器）占位化 + 包条件块。
 * 返回 array($html, $why, $title_ok)；$why === 'ok' 表示已应用。 */
function cfse_skin_prevnext_apply($html, $a_start, $if, $title_token) {
    if (!preg_match('~^<a[\s>]~i', substr($html, $a_start, 4))) return array($html, 'not-anchor', false);
    $gt = strpos($html, '>', $a_start);
    if ($gt === false) return array($html, 'no-tag-end', false);
    $ac = cfse_html_find_close($html, $gt + 1, 'a');
    if (!$ac) return array($html, 'no-close', false);
    $a_end = $ac['close_end'];
    $a_html = substr($html, $a_start, $a_end - $a_start);
    if (!preg_match('~(\bhref\s*=\s*["\'])([^"\']*)(["\'])~i', $a_html, $hm)) return array($html, 'no-href', false);

    // 容器：class 含 nav 的祖先，且其内部只有这一个 <a>
    // （prev/next 同处一个容器时各自只包 <a>，避免条件块互相嵌套）
    $inner_start = $a_start;
    $inner_end = $a_end;
    $up = cfse_skin_prevnext_nav_ancestor($html, $a_start);
    if ($up && $up['start'] < $a_start) {
        $uc = cfse_html_find_close($html, $up['content_start'], $up['tag']);
        if ($uc) {
            $cand = substr($html, $up['content_start'], $uc['close_start'] - $up['content_start']);
            if (preg_match_all('~<a\b~i', $cand, $am2) && count($am2[0]) <= 1) {
                $inner_start = $up['content_start'];
                $inner_end = $uc['close_start'];
            }
        }
    }
    $inner = substr($html, $inner_start, $inner_end - $inner_start);
    $inner = str_replace($hm[0], $hm[1] . '{{' . $if . '_URL}}' . $hm[3], $inner);
    $title_ok = false;
    if ($title_token !== '' && strpos($inner, $title_token) !== false) {
        $inner = str_replace($title_token, '{{' . $if . '_TITLE}}', $inner);
        $title_ok = true;
    }
    $html = substr($html, 0, $inner_start) . '{{#IF_' . $if . '}}' . $inner . '{{/IF_' . $if . '}}' . substr($html, $inner_end);
    return array($html, 'ok', $title_ok);
}

function cfse_skin_single_prevnext($html, $ctx) {
    $log = array();
    $pairs = array(
        'PREV' => array(
            'rel'       => 'prev',
            'permalink' => isset($ctx['prev_permalink']) ? trim((string) $ctx['prev_permalink']) : '',
            'title'     => isset($ctx['prev_title']) ? (string) $ctx['prev_title'] : '',
        ),
        'NEXT' => array(
            'rel'       => 'next',
            'permalink' => isset($ctx['next_permalink']) ? trim((string) $ctx['next_permalink']) : '',
            'title'     => isset($ctx['next_title']) ? (string) $ctx['next_title'] : '',
        ),
    );
    $done = array('PREV' => '', 'NEXT' => '');

    // 1) 值锚。同一篇的上下篇链接在页面里可能有多处（twentyten 的 #nav-above + #nav-below
    //    指向同一篇），所以：先按优先级**定层级**（rel → 导航容器内 → 任意），再在**该层级内
    //    处理全部命中** —— 既不会漏掉第二处，也不会因为降级而误伤侧边栏「最近文章」里的同链接。
    foreach ($pairs as $if => $info) {
        if ($info['permalink'] === '') { $log[] = $if . ': no neighbour permalink in ctx -> legacy'; continue; }
        $anchors = cfse_skin_prevnext_anchors($html);
        $level = '';
        foreach (array('rel', 'url-in-nav', 'url') as $lv) {
            foreach ($anchors as $a) {
                if ($a['href'] === '') continue;
                if (!cfse_skin_prevnext_href_matches($a['href'], $info['permalink'])) continue;
                if ($lv === 'rel' && ($a['rel'] === '' || strpos($a['rel'], $info['rel']) === false)) continue;
                if ($lv === 'url-in-nav' && !cfse_skin_prevnext_nav_ancestor($html, $a['start'])) continue;
                $level = $lv;
                break 2;
            }
        }
        if ($level === '') { $log[] = $if . ': neighbour link not found (' . $info['permalink'] . ') -> legacy'; continue; }

        $applied = 0;
        $title_ok_any = false;
        for ($guard = 0; $guard < 8; $guard++) {
            $anchors = cfse_skin_prevnext_anchors($html);
            $target = -1;
            foreach ($anchors as $a) {
                if ($a['href'] === '') continue;
                if (!cfse_skin_prevnext_href_matches($a['href'], $info['permalink'])) continue;
                if ($level === 'rel' && ($a['rel'] === '' || strpos($a['rel'], $info['rel']) === false)) continue;
                if ($level === 'url-in-nav' && !cfse_skin_prevnext_nav_ancestor($html, $a['start'])) continue;
                $target = $a['start'];
                break;
            }
            if ($target < 0) break;
            list($new_html, $why, $title_ok) = cfse_skin_prevnext_apply($html, $target, $if, $info['title']);
            if ($why !== 'ok') { $log[] = $if . ': anchor found but not applied (' . $why . ')'; break; }
            $html = $new_html;
            $applied++;
            if ($title_ok) { $title_ok_any = true; }
        }
        if ($applied === 0) { $log[] = $if . ': nothing applied -> legacy'; continue; }
        $done[$if] = $level;
        $log[] = $if . ': exact value anchor (' . $level . '), ' . $applied . ' link(s)'
            . ($title_ok_any ? ', title token replaced' : ', title token absent (theme copy kept)');
    }

    // 2) 未命中的一侧：旧启发式（class 名 + 去 meta-nav 猜标题）——保证既有行为不回归
    if ($done['PREV'] === '' || $done['NEXT'] === '') {
        $legacy = function ($html, $url_ph, $title_ph, $if) {
            return preg_replace_callback(
                '~(<(div|nav)\b[^>]*class=["\'][^"\']*nav-' . strtolower($if) . '[^"\']*["\'][^>]*>)(.*?)(</\2>)~s',
                function ($m) use ($url_ph, $title_ph, $if) {
                    $inner = $m[3];
                    if (strpos($inner, '{{') !== false) return $m[0];   // 该容器已由值锚处理过，勿重复包裹
                    if (preg_match('~(<a\b[^>]*\bhref=["\'])[^"\']*(["\'][^>]*>)(.*?)(</a>)~s', $inner, $am)) {
                        $body = $am[3];
                        $no_meta = preg_replace('~<span\b[^>]*class=["\'][^"\']*meta-nav[^"\']*["\'][^>]*>.*?</span>~s', '', $body);
                        $title = trim(strip_tags($no_meta));
                        $body2 = ($title !== '') ? str_replace($title, $title_ph, $body) : $body;
                        $inner = str_replace($am[0], $am[1] . $url_ph . $am[2] . $body2 . $am[4], $inner);
                    }
                    return $m[1] . '{{#IF_' . $if . '}}' . $inner . '{{/IF_' . $if . '}}' . $m[4];
                },
                $html, -1
            );
        };
        if ($done['PREV'] === '') { $html = $legacy($html, '{{PREV_URL}}', '{{PREV_TITLE}}', 'PREV'); }
        if ($done['NEXT'] === '') { $html = $legacy($html, '{{NEXT_URL}}', '{{NEXT_TITLE}}', 'NEXT'); }
    }

    if (!empty($log) && function_exists('cfse_log_message')) {
        foreach ($log as $x) { cfse_log_message('skin prev/next: ' . $x, 'INFO'); }
    }
    return $html;
}

/** 评论条目骨架（单条）：把「已知的探针评论值」换成占位符。
 * $c 为该评论的已知字段（id / author / url / content / date）。$li 是这一条的原始 <li>…</li>。
 * 与缩略图/作者的处理同理：尽量按「值 + URL 特征」定位，不依赖主题类名；结构原样保留。 */
function cfse_skin_comment_entry_skeleton($li, $c) {
    $id = (int) $c['id'];
    $s = $li;

    // 1) 锚点 / 锚链接
    $s = str_replace(array('id="li-comment-' . $id . '"', "id='li-comment-" . $id . "'"), 'id="li-comment-{{COMMENT_ID}}"', $s);
    $s = str_replace(array('id="div-comment-' . $id . '"', "id='div-comment-" . $id . "'"), 'id="div-comment-{{COMMENT_ID}}"', $s);
    $s = str_replace(array('id="comment-' . $id . '"', "id='comment-" . $id . "'"), 'id="comment-{{COMMENT_ID}}"', $s);
    $s = str_replace(array('#comment-' . $id, '#div-comment-' . $id), '#comment-{{COMMENT_ID}}', $s);

    // 2) 头像（gravatar URL 特征，s=40 / s=80 两档）
    $s = preg_replace('~(src=(["\']))https?://[^"\']*gravatar\.com/avatar/[^"\']*\2~i', '$1{{COMMENT_AVATAR}}$2', $s, 1);
    $s = preg_replace('~(srcset=(["\']))https?://[^"\']*gravatar\.com/avatar/[^"\']*["\'][^>]*~i', '$1{{COMMENT_AVATAR_2X}} 2x$2', $s, 1);

    // 3) 作者：被 <a> 包住（= 有作者 URL 的形态）→ 整段包 IF 块，块外再给一个纯文本占位
    //    （我们的 IF 是整块替换，包不了半个标签，所以用「块内整元素 + 块外纯文本」二选一）
    $author = isset($c['author']) ? (string) $c['author'] : '';
    $url = isset($c['url']) ? (string) $c['url'] : '';
    if ($url !== '') {
        $s = str_replace(array('href="' . $url . '"', "href='" . $url . "'"), array('href="{{COMMENT_AUTHOR_URL}}"', "href='{{COMMENT_AUTHOR_URL}}'"), $s);
    }
    if ($author !== '') {
        if (preg_match('~<a\b[^>]*>\s*' . preg_quote($author, '~') . '\s*</a>~u', $s, $am)) {
            $a_tpl = str_replace($author, '{{COMMENT_AUTHOR}}', $am[0]);
            $s = str_replace($am[0], '{{#IF_COMMENT_AUTHOR_URL}}' . $a_tpl . '{{/IF_COMMENT_AUTHOR_URL}}{{COMMENT_AUTHOR_PLAIN}}', $s);
        } else {
            $s = str_replace($author, '{{COMMENT_AUTHOR}}', $s);
        }
    }

    // 4) 日期文本 + datetime 属性
    $ts = !empty($c['date']) ? strtotime((string) $c['date']) : 0;
    if ($ts) {
        $fd = (string) get_option('date_format');
        $ft = (string) get_option('time_format');
        $cands = array();
        if ($fd !== '' && $ft !== '') { $cands[] = date_i18n($fd, $ts) . ' at ' . date_i18n($ft, $ts); }
        if ($fd !== '') { $cands[] = date_i18n($fd, $ts); }
        $cands[] = date_i18n('F j, Y', $ts);
        $cands[] = date_i18n('Y-m-d H:i', $ts);
        foreach (array_unique($cands) as $d) {
            if ($d !== '' && strpos($s, $d) !== false) { $s = str_replace($d, '{{COMMENT_DATE}}', $s); break; }
        }
        $iso = date('Y-m-d\TH:i:sP', $ts);
        $s = str_replace(array('datetime="' . $iso . '"', "datetime='" . $iso . "'"), 'datetime="{{COMMENT_DATETIME}}"', $s);
    }

    // 5) 正文：定位承载它的元素、替换其内容（保留元素与类名）
    $content = isset($c['content']) ? (string) $c['content'] : '';
    if ($content !== '' && strpos($s, $content) !== false) {
        $pos = strpos($s, $content);
        $loc = cfse_html_loc_tag($s, $pos);
        $done = false;
        if ($loc) {
            $cl = cfse_html_find_close($s, $loc['content_start'], $loc['tag']);
            if ($cl && $cl['close_start'] > $loc['content_start']) {
                $s = substr($s, 0, $loc['content_start']) . '{{COMMENT_CONTENT}}' . substr($s, $cl['close_start']);
                $done = true;
            }
        }
        if (!$done) { $s = str_replace($content, '{{COMMENT_CONTENT}}', $s); }
    }

    // 6) 根元素 class 里的「交替类 / 深度类」→ 占位符（只动第一个 class，且只在 class 内替换，
    //    避免误伤 alt="" 之类的同名属性）
    $s = preg_replace_callback('~class=(["\'])([^"\']*)\1~i', function ($m) {
        $c2 = $m[2];
        $c2 = preg_replace('~\bthread-(?:even|odd|alt)\b~', '{{COMMENT_THREAD_CLASS}}', $c2, -1);
        $c2 = preg_replace('~\b(?:even|odd)\b~', '{{COMMENT_ODD_EVEN}}', $c2, -1);
        $c2 = preg_replace('~\balt\b~', '{{COMMENT_ALT}}', $c2, -1);
        // 同一个 class 里可能同时有 thread-odd 与 thread-alt ⇒ 会出现两个相同占位符，合并成一个
        $c2 = preg_replace('~\s*\{\{COMMENT_THREAD_CLASS\}\}(?:\s+\{\{COMMENT_THREAD_CLASS\}\})+~', ' {{COMMENT_THREAD_CLASS}}', $c2);
        $c2 = preg_replace('~\s*\{\{COMMENT_ODD_EVEN\}\}(?:\s+\{\{COMMENT_ODD_EVEN\}\})+~', ' {{COMMENT_ODD_EVEN}}', $c2);
        $c2 = preg_replace('~\s*\{\{COMMENT_ALT\}\}(?:\s+\{\{COMMENT_ALT\}\})+~', ' {{COMMENT_ALT}}', $c2);
        $c2 = preg_replace('~\bdepth-\d+\b~', 'depth-{{COMMENT_DEPTH}}', $c2, 1);
        return 'class=' . $m[1] . trim(preg_replace('~\s+~', ' ', $c2)) . $m[1];
    }, $s, 1);

    // 7) 回复块：**保留**（静态站由注入的 JS 接管：点"回复"→ 记住父评论 → 聚焦表单）
    //    回复链接的 href → 页内表单锚点；并给 <a> 打上 data-cfse-parent（渲染端会把 {{COMMENT_ID}} 换成真实 id）
    $s = preg_replace_callback(
        '~(<div\b[^>]*class=["\'][^"\']*\breply\b[^"\']*["\'][^>]*>)(.*?)(</div>\s*(?:<!--\s*\.reply\s*-->)?)~is',
        function ($m) {
            $inner = preg_replace('~(\bhref\s*=\s*(["\'])[^"\']*)\2~i', 'href="#respond"', $m[2], 1);
            $inner = preg_replace('~<a\b~i', '<a data-cfse-parent="{{COMMENT_ID}}"', $inner, 1);
            return $m[1] . $inner . $m[3];
        },
        $s, 1
    );
    $s = preg_replace('~\s+data-commentid=["\']\d+["\']~', '', $s);
    $s = preg_replace('~\s+data-postid=["\']\d+["\']~', '', $s);
    $s = preg_replace('~\s+data-belowelement=["\'][^"\']+["\']~', '', $s);
    $s = preg_replace('~\s+data-respondelement=["\'][^"\']+["\']~', '', $s);
    $s = preg_replace('~\s+data-replyto=["\'][^"\']*["\']~', '', $s);
    $s = preg_replace('~\s+aria-label=["\'][^"\']*["\']~', '', $s);
    $s = preg_replace('~replytocom=\d+~', '', $s);

    return $s;
}

/** 取列表内容里的**顶层 <li> 单元**（评论条目一定是 li 结构）。
 * 不用 cfse_skin_extract_units()：那个要求「同标签重复 ≥2 次」才认定为单元，
 * 子层只有 1 条回复时会退化成 div ⇒ 丢掉 <li> 外层。 */
function cfse_skin_comment_top_lis($content, $max = 4) {
    $out = array();
    $pos = 0;
    while (count($out) < $max) {
        if (!preg_match('~<li\b[^>]*>~i', $content, $m, PREG_OFFSET_CAPTURE, $pos)) break;
        $start = $m[0][1];
        $gt = strpos($content, '>', $start);
        if ($gt === false) break;
        $close = cfse_html_find_close($content, $gt + 1, 'li');
        if (!$close) break;
        $out[] = substr($content, $start, $close['close_end'] - $start);
        $pos = $close['close_end'];
    }
    return $out;
}

/** 评论「同级重复单元」→ 骨架（顶层与嵌套共用，children 递归）。
 * 选骨架基础时**优先挑带作者 URL 的那条**（信息最全 ⇒ 能生成 {{#IF_COMMENT_AUTHOR_URL}} 双形态）。
 * 返回 array($skeleton, $children_tpl, $warns)。 */
function cfse_skin_comment_units_skeleton($list_content, $by_id, $warns) {
    $units = cfse_skin_comment_top_lis($list_content, 4);
    if (empty($units)) return array($list_content, '', $warns);

    $pick = 0;
    foreach ($units as $i => $u) {
        if (!preg_match('~id=["\'](?:li-)?comment-(\d+)["\']~', $u, $m)) continue;
        $cid = (int) $m[1];
        if (!empty($by_id[$cid]['url'])) { $pick = $i; break; }
    }
    $unit = $units[$pick];
    $c = null;
    if (preg_match('~id=["\'](?:li-)?comment-(\d+)["\']~', $unit, $m)) {
        $cid = (int) $m[1];
        if (isset($by_id[$cid])) { $c = $by_id[$cid]; }
    }
    if (!$c) {
        $warns[] = '评论单元没能对到探针评论（值锚缺失），该层骨架未提取';
        return array($unit, '', $warns);
    }

    // 先剥离 children 子树（递归），再对本体做值锚替换 —— 否则子评论的内容会被当成父条目的内容
    $children_tpl = '';
    if (preg_match('~<(ol|ul)\b[^>]*class=["\'][^"\']*\bchildren\b[^"\']*["\'][^>]*>~i', $unit, $cm, PREG_OFFSET_CAPTURE)) {
        $ctag = strtolower($cm[1][0]);
        $cgt = strpos($unit, '>', $cm[0][1]);
        $cclose = ($cgt === false) ? null : cfse_html_find_close($unit, $cgt + 1, $ctag);
        if ($cclose) {
            $child_inner = substr($unit, $cgt + 1, $cclose['close_start'] - $cgt - 1);
            list($children_tpl, $deep_tpl, $warns) = cfse_skin_comment_units_skeleton($child_inner, $by_id, $warns);
            if ($deep_tpl !== '' && $children_tpl !== '') { /* 更深层沿用同一骨架 */ }
            $unit = substr($unit, 0, $cgt + 1) . '{{#IF_COMMENT_CHILDREN}}{{COMMENT_CHILDREN}}{{/IF_COMMENT_CHILDREN}}' . substr($unit, $cclose['close_start']);
        }
    }

    $skel = cfse_skin_comment_entry_skeleton($unit, $c);
    return array($skel, $children_tpl, $warns);
}

/** 评论区 → 「评论骨架」+ 单一注入点。
 * 产出：$GLOBALS['cfse_comments_tpl'] = array('list' => …, 'children' => …)（进 tpl.json 的 comments 段），
 * 单页模板里评论区只留 {{#IF_COMMENTS}}{{COMMENTS}}{{/IF_COMMENTS}} ——
 * 于是「第三方嵌入（路线 A）」与「骨架再生（路线 B）」**共用同一个位置**。
 * 没有探针评论信息时退回旧行为（整体保留 + ID 占位），不回归。 */
function cfse_skin_single_comments($html, $ctx) {
    // 先定位 #comments 容器
    $pat = '~<(div|section|ol|ul)\b[^>]*id=["\']comments["\'][^>]*>~i';
    if (!preg_match($pat, $html, $m, PREG_OFFSET_CAPTURE)) return $html;
    $tag = strtolower($m[1][0]);
    $start = $m[0][1];
    $gt = strpos($html, '>', $start);
    if ($gt === false) return $html;
    $close = cfse_html_find_close($html, $gt + 1, $tag);
    if (!$close) return $html;
    $inner = substr($html, $gt + 1, $close['close_start'] - $gt - 1);

    $probe = isset($ctx['probe_comments']) && is_array($ctx['probe_comments']) ? $ctx['probe_comments'] : array();
    if (!empty($probe)) {
        $by_id = array();
        foreach ($probe as $c) { $by_id[(int) $c['id']] = $c; }
        $warns = array();
        $list_tpl = $inner;

        // 标题里的评论数 → {{COMMENT_COUNT}}（标题里的文章名由上层 {{POST_TITLE}} 替换接管）
        $list_tpl = preg_replace('~(<h[1-6]\b[^>]*id=["\']comments-title["\'][^>]*>)\s*\d+\s*~i', '$1{{COMMENT_COUNT}} ', $list_tpl, 1);

        // 列表容器：第一个「内部含 <li>」的 ol|ul（twentyten 用 ol.commentlist，别写死类名）
        $children_tpl = '';
        $entry_tpl = '';
        $found = false;
        if (preg_match_all('~<(ol|ul)\b[^>]*>~i', $list_tpl, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as $i => $cap) {
                $ltag = strtolower($mm[1][$i][0]);
                $lgt = strpos($list_tpl, '>', $cap[1]);
                if ($lgt === false) continue;
                $lclose = cfse_html_find_close($list_tpl, $lgt + 1, $ltag);
                if (!$lclose) continue;
                $seg = substr($list_tpl, $lgt + 1, $lclose['close_start'] - $lgt - 1);
                if (strpos($seg, '<li') === false) continue;
                list($seg_tpl, $children_tpl, $warns) = cfse_skin_comment_units_skeleton($seg, $by_id, $warns);
                // 单条骨架（顶层）单独存：渲染端要按每条评论重复它；外壳里只留 {{COMMENTS}} 占位
                $entry_tpl = $seg_tpl;
                $list_tpl = substr($list_tpl, 0, $lgt + 1) . '{{COMMENTS}}' . substr($list_tpl, $lclose['close_start']);
                $found = true;
                break;
            }
        }
        if (!$found) { $warns[] = '未找到含条目的评论列表容器（ol|ul），骨架未提取'; }

        // 骨架是**独立产物**（单独进 tpl.json 的 comments 段，不经过单页模板的替换流程），
        // 所以这里要自己再做一遍：
        // ① #respond 表单：**保留**（静态站原本不能提交，但我们有 POST /comments 端点）
        //    从列表里提取出来单独存为 comments.form；改 action → /comments（由注入的 JS 接管提交），
        //    并去掉 WP 的 nonce / 真实 post_ID（静态页里它们没有意义）。
        $form_tpl = '';
        if (preg_match('~<(div|section)\b[^>]*id=["\']respond["\'][^>]*>~i', $list_tpl, $rm, PREG_OFFSET_CAPTURE)) {
            $rtag = strtolower($rm[1][0]);
            $rgt = strpos($list_tpl, '>', $rm[0][1]);
            $rclose = ($rgt === false) ? null : cfse_html_find_close($list_tpl, $rgt + 1, $rtag);
            if ($rclose) {
                $form_tpl = substr($list_tpl, $rm[0][1], $rclose['close_end'] - $rm[0][1]);
                $list_tpl = substr($list_tpl, 0, $rm[0][1]) . substr($list_tpl, $rclose['close_end']);
            }
        } else {
            // 没有 #respond 容器时，退一步：直接取 <form id="commentform"> 整块
            if (preg_match('~<form\b[^>]*id=["\']commentform["\'][^>]*>~i', $list_tpl, $fm, PREG_OFFSET_CAPTURE)) {
                $fgt = strpos($list_tpl, '>', $fm[0][1]);
                $fclose = ($fgt === false) ? null : cfse_html_find_close($list_tpl, $fgt + 1, 'form');
                if ($fclose) {
                    $form_tpl = substr($list_tpl, $fm[0][1], $fclose['close_end'] - $fm[0][1]);
                    $list_tpl = substr($list_tpl, 0, $fm[0][1]) . substr($list_tpl, $fclose['close_end']);
                }
            }
        }
        $list_tpl = preg_replace('~<!--\s*#respond\s*-->~', '', $list_tpl);
        if ($form_tpl !== '') {
            $form_tpl = preg_replace('~<!--\s*#respond\s*-->~', '', $form_tpl);
            // action → /comments（同一域名下的公开端点）
            $form_tpl = preg_replace('~(action=)(["\'])[^"\']*\2~i', '$1$2/comments$2', $form_tpl, 1);
            // 去掉 nonce 与真实 post_ID（JS 会用页面里的 id 填）
            $form_tpl = preg_replace('~<input\b[^>]*name=["\']_wpnonce["\'][^>]*>~i', '', $form_tpl);
            $form_tpl = preg_replace('~(<input\b[^>]*name=["\']comment_post_ID["\'][^>]*\bvalue=)(["\'])[^"\']*\2~i', '$1$2{{COMMENT_POST_ID}}$2', $form_tpl);
        }
        // ② 评论锚链接里的**探针文章 permalink** → {{POST_URL}}（否则线上是指向探针的死链）
        if (!empty($ctx['permalink'])) {
            $perm = (string) $ctx['permalink'];
            $repl = array($perm, rtrim($perm, '/'));
            $list_tpl = str_replace($repl, '{{POST_URL}}', $list_tpl);
            $entry_tpl = str_replace($repl, '{{POST_URL}}', $entry_tpl);
            $children_tpl = str_replace($repl, '{{POST_URL}}', $children_tpl);
        }
        // ③ 探针标题 → {{POST_TITLE}}；站点级占位（{{SITE_URL}} / {{SITE_NAME}}）
        if (!empty($ctx['probe_title'])) {
            $list_tpl = str_replace($ctx['probe_title'], '{{POST_TITLE}}', $list_tpl);
            $entry_tpl = str_replace($ctx['probe_title'], '{{POST_TITLE}}', $entry_tpl);
            $children_tpl = str_replace($ctx['probe_title'], '{{POST_TITLE}}', $children_tpl);
        }
        $list_tpl = cfse_skin_replace_site($list_tpl, $ctx);
        $entry_tpl = cfse_skin_replace_site($entry_tpl, $ctx);
        $children_tpl = ($children_tpl === '') ? '' : cfse_skin_replace_site($children_tpl, $ctx);
        $form_tpl = ($form_tpl === '') ? '' : cfse_skin_replace_site($form_tpl, $ctx);
        if (!empty($ctx['probe_title']) && $form_tpl !== '') {
            $form_tpl = str_replace($ctx['probe_title'], '{{POST_TITLE}}', $form_tpl);
        }

        // children 骨架补「更深一层」的占位：探针只嵌套一层 ⇒ 手工补上，保证多层回复也能渲染
        if ($children_tpl !== '' && strpos($children_tpl, '{{COMMENT_CHILDREN}}') === false) {
            $p_li = strrpos($children_tpl, '</li>');
            if ($p_li !== false) {
                $children_tpl = substr($children_tpl, 0, $p_li)
                    . '{{#IF_COMMENT_CHILDREN}}{{COMMENT_CHILDREN}}{{/IF_COMMENT_CHILDREN}}'
                    . substr($children_tpl, $p_li);
            }
        }

        $GLOBALS['cfse_comments_tpl'] = array(
            'list'     => $list_tpl,
            'entry'    => $entry_tpl,
            'children' => $children_tpl,
            'form'     => $form_tpl,
        );
        foreach ($warns as $w) { cfse_log_message('skin comments: ' . $w, 'WARNING'); }

        // ② 半动态注入的定位标记：Worker 单页响应时按它把评论区换成"现场从 D1 生成"的版本
        //    ⇒ 新评论/审核结果立即可见，不必重建单页（标记本身是注释，渲染后依然保留）
        return substr($html, 0, $gt + 1)
            . '<!--CFSE-COMMENTS-START id={{CFSE_POST_ID}}-->'
            . "{{#IF_COMMENTS}}{{COMMENTS}}{{/IF_COMMENTS}}"
            . '<!--CFSE-COMMENTS-END-->'
            . substr($html, $close['close_start']);
    }

    // ---- 兜底：无探针评论信息（旧行为）----
    $block2 = $inner;
    $block2 = preg_replace('~id=["\']li-comment-\d+["\']~', 'id="li-comment-{{COMMENT_ID}}"', $block2);
    $block2 = preg_replace('~id=["\']comment-\d+["\']~', 'id="comment-{{COMMENT_ID}}"', $block2);
    $block2 = preg_replace('~\s+data-commentid=["\']\d+["\']~', '', $block2);
    $block2 = preg_replace('~\s+data-postid=["\']\d+["\']~', '', $block2);
    $block2 = preg_replace('~\s+data-belowelement=["\'][^"\']+["\']~', '', $block2);
    $block2 = preg_replace('~\s+data-respondelement=["\'][^"\']+["\']~', '', $block2);
    $block2 = preg_replace('~\s+data-replyto=["\'][^"\']*["\']~', '', $block2);
    $block2 = preg_replace('~\s+aria-label=["\'][^"\']*["\']~', '', $block2);
    $block2 = preg_replace('~replytocom=\d+~', '', $block2);
    $block2 = "{{#IF_COMMENTS}}" . $block2 . "{{/IF_COMMENTS}}";
    return substr($html, 0, $gt + 1) . $block2 . substr($html, $close['close_start']);
}

/** 作者链接 → {{POST_AUTHOR_URL}} + {{POST_AUTHOR}}（带 {{#IF_AUTHOR}} 条件块）。
 * item 与 single 共用（主题把作者输出成 <a href="…/author/…">name</a> 或 cite.fn / span.author）。
 */
function cfse_skin_replace_author_link($html, $ctx) {
    $author_name = isset($ctx['author_name']) ? $ctx['author_name'] : '';
    if ($author_name === '') return $html;
    // <a … href="…/author/…" …>name</a> → href 占位 + 文本占位
    $pat = '~(<a\b[^>]*href=["\'])[^"\']*/author/[^"\']*(["\'][^>]*>)\s*' . preg_quote($author_name, '~') . '\s*(</a>)~u';
    $html = preg_replace($pat, '{{#IF_AUTHOR}}$1{{POST_AUTHOR_URL}}$2{{POST_AUTHOR}}$3{{/IF_AUTHOR}}', $html);
    // 作者链接的 title="View all posts by NAME" → 去掉（per-author 数据，避免硬编码作者名）
    $html = preg_replace('~\s+title=["\'][^"\']*\b' . preg_quote($author_name, '~') . '\b[^"\']*["\']~u', '', $html, 1);
    if (strpos($html, '{{POST_AUTHOR}}') === false) {
        // 兜底：<cite class="fn">NAME</cite> / <span class="author …">NAME</span>
        $html = preg_replace('~(<cite\b[^>]*>\s*)' . preg_quote($author_name, '~') . '(\s*</cite>)~u', '{{#IF_AUTHOR}}$1{{POST_AUTHOR}}$2{{/IF_AUTHOR}}', $html, 1);
        $html = preg_replace('~(<span\b[^>]*class=["\'][^"\']*\bauthor\b[^"\']*["\'][^>]*>)\s*' . preg_quote($author_name, '~') . '\s*(</span>)~u', '{{#IF_AUTHOR}}$1{{POST_AUTHOR}}$2{{/IF_AUTHOR}}', $html, 1);
    }
    return $html;
}

/** 单页作者块/缩略图 → 占位符（作者名 / 作者归档 URL / 特色图）。
 * 用探针文章的作者与特色图基准定位（theme 输出位置），替换为占位符。
 */
function cfse_skin_single_author_thumb($html, $ctx) {
    // 作者块：只改「作者链接」这一个 <a>，避免全局替换误伤 URL 路径 / 内联脚本里的同名文本
    // （曾用 str_replace($author_name, ...) 全局替换 ⇒ 把 /author/admin/ 与 speculationrules
    //   里的 /wp-admin/* 也换成了 {{POST_AUTHOR}}，是 bug）
    // 作者链接 → 占位符（与 item 共用 cfse_skin_replace_author_link）
    $html = cfse_skin_replace_author_link($html, $ctx);
    // 缩略图：探针特色图 URL → {{POST_THUMBNAIL}}（站点 URL 可能已被占位化，用 uploads 路径特征匹配）
    $thumb = isset($ctx['thumbnail_url']) ? $ctx['thumbnail_url'] : '';
    if ($thumb !== '') {
        // <img ... src="…wp-content/uploads/…" ...> → src 占位（整段 <img> 保留）
        $html = preg_replace(
            '~(<img\b[^>]*\bsrc=["\'])[^"\']*wp-content/uploads/[^"\']*(["\'][^>]*>)~u',
            '{{#IF_THUMBNAIL}}$1{{POST_THUMBNAIL}}$2{{/IF_THUMBNAIL}}',
            $html
        );
        // 图片链接 <a href="…uploads…"><img …></a> 的 href 也一并占位（避免残余探针 URL）
        $html = preg_replace(
            '~(<a\b[^>]*href=["\'])[^"\']*wp-content/uploads/[^"\']*(["\'][^>]*>)(\s*<img\b[^>]*\bsrc=["\']\{\{POST_THUMBNAIL\}\})~u',
            '$1{{POST_THUMBNAIL}}$2$3',
            $html
        );
    }
    return $html;
}

function cfse_skin_single_shell($html, $ctx) {
    $warns = array();
    $html = cfse_skin_strip_probe_links($html, $ctx);

    // 评论区：保留结构（{{COMMENTS}} 条件块，Worker 端按评论快照填充），元数据 ID 占位化；
    // #respond（评论表单）是动态功能，静态站删除。
    // 上下篇导航：删除 → 提取为 {{PREV_POST}}/{{NEXT_POST}}（探针 A2 居中时两者都出现，主题结构保留）。
    $html = cfse_skin_single_comments($html, $ctx);
    $html = preg_replace('~<div[^>]*id="respond"[^>]*>.*?</div><!--\s*#respond\s*-->~s', '', $html, 1);
    $html = cfse_skin_single_prevnext($html, $ctx);

    // 正文/标题/日期/分类/标签/URL：整页当一个容器处理
    // 只对「文章主体容器」做占位替换：整页处理会波及导航菜单/侧边栏里的同名分类名与标签名
    // （菜单会被替换成 {{POST_CATEGORIES}}/{{#IF_...}}，而 entry-utility 的那处反而被 limit 吃掉）
    $box = cfse_html_element_by_marker($html, 'id="post-' . (int) $ctx['probe_id'] . '"');
    if ($box) {
        $seg = substr($html, $box['start'], $box['close_end'] - $box['start']);
        list($seg2, $warns2) = cfse_skin_replace_item($seg, $ctx, true);
        $html = substr($html, 0, $box['start']) . $seg2 . substr($html, $box['close_end']);
    } else {
        $warns[] = '未找到文章主体容器（id="post-N"），已退回整页替换（可能波及菜单/侧边栏）';
        list($html, $warns2) = cfse_skin_replace_item($html, $ctx, true);
    }
    // 条目注释锚同样清掉
    $html = preg_replace('~<!--\s*#post-\d+\s*-->~', '', $html);
    // 分类 / 标签 feed link（静态站无该路由）
    $html = preg_replace('~<link\b[^>]*rel=["\']alternate["\'][^>]*href=["\'][^"\']*/(category|tag)/[^"\']*/feed/["\'][^>]*/?>~i', '', $html);
    $html = preg_replace('~<link\b[^>]*href=["\'][^"\']*/(category|tag)/[^"\']*/feed/["\'][^>]*rel=["\']alternate["\'][^>]*/?>~i', '', $html);
    $warns = array_merge($warns, $warns2);
    $html = str_replace($ctx['probe_title'], '{{POST_TITLE}}', $html);
    // 侧边栏三块 → 占位符（单页也有侧边栏；与列表页共用公共函数）
    foreach (cfse_skin_replace_sidebars($html, $ctx) as $w) { $warns[] = $w; }
    // 作者块 / 缩略图 → 占位符
    $html = cfse_skin_single_author_thumb($html, $ctx);
    // 站点级「全页」替换（站点名/URL/描述）—— 单页壳此前漏了这步（列表页 cfse_skin_page_shell 有）
    $html = cfse_skin_replace_site($html, $ctx);
    // 主导航 → {{MENU}}（单页也有菜单；与 page_shell 共用同一函数）
    $nav = cfse_skin_nav_menu($html, $ctx);
    if ($nav !== null) {
        $html = $nav;
    } elseif (!empty($ctx['menu_item_ids'])) {
        $warns[] = '未定位到导航菜单容器（menu-item-<ID> 值锚未命中），{{MENU}} 缺失';
    }
    return array($html, $warns);
}

/**
 * 主入口：用 5 份内部渲染结果生成 6 类模板。
 * $ctx 需含：token, probe_id, probe_title, permalink, post_date, cat_names, tag_names,
 *            site_url, site_name, site_desc, html => array(home|category|tag|archive|single)
 */
function cfse_skin_map_templates($ctx) {
    $warns = array();
    // 日期候选同时纳入 post_modified：探针更新后 modified 与 post_date 可能相差若干秒，
    // 而部分主题（实测 twentynineteen 的 <time class="entry-date published updated">）输出的是 modified。
    $__probe_post = !empty($ctx['probe_id']) ? get_post((int) $ctx['probe_id']) : null;
    $ctx['date_candidates'] = cfse_skin_date_candidates(
        $ctx['post_date'],
        $__probe_post ? $__probe_post->post_modified : '',
        $__probe_post ? $__probe_post->post_modified_gmt : ''
    );

    // 归档页标题里可能出现的文本：分类名/标签名 + 年月（用于主题无关地定位标题元素）
    $ctx['title_needles'] = array();
    foreach (array_merge((array) $ctx['cat_names'], (array) $ctx['tag_names']) as $nm) {
        if ($nm !== '' && !in_array($nm, $ctx['title_needles'], true)) $ctx['title_needles'][] = $nm;
    }
    $ts = !empty($ctx['post_date']) ? strtotime($ctx['post_date']) : 0;
    if ($ts) {
        foreach (array('F Y', 'Y年n月', 'Y F', 'F, Y', 'Y/m') as $f) {
            $s = date_i18n($f, $ts);
            if ($s !== '' && !in_array($s, $ctx['title_needles'], true)) $ctx['title_needles'][] = $s;
        }
    }

    // ---- item 模板：取自首页条目（首页条目用 the_content，统一映射为 {{POST_EXCERPT}}）----
    $item = '';
    $home = isset($ctx['html']['home']) ? $ctx['html']['home'] : '';
    if ($home === '') {
        $warns[] = '缺少首页渲染结果，无法生成条目模板';
    } else {
        $token_pos = strpos($home, $ctx['probe_title']);
        if ($token_pos === false) {
            $warns[] = '首页渲染结果里找不到探针标题 token，无法定位条目容器';
        } else {
            $box = cfse_skin_locate_container($home, $ctx['probe_id'], $token_pos);
            if (!$box) {
                $warns[] = '无法定位探针条目容器（既没有 id="post-N"，祖先也没有 post 类名）';
            } else {
                $raw_item = substr($home, $box['start'], $box['close_end'] - $box['start']);
                list($item, $w) = cfse_skin_replace_item($raw_item, $ctx, false);
                $warns = array_merge($warns, array('条目容器定位：' . (isset($box['how']) ? $box['how'] : '?')));
                $warns = array_merge($warns, $w);
            }
        }
    }

    // ---- 4 个页面壳 ----
    $out = array('item' => $item, 'single' => '');
    $map = array('home' => 'pageHome', 'category' => 'pageCategory', 'tag' => 'pageTag', 'archive' => 'pageArchive');
    foreach ($map as $src => $target) {
        if (empty($ctx['html'][$src])) {
            $warns[] = '缺少 ' . $src . ' 渲染结果，' . $target . ' 未生成';
            $out[$target] = '';
            continue;
        }
        list($shell, $w) = cfse_skin_page_shell($ctx['html'][$src], $ctx, $src);
        foreach ($w as $x) $warns[] = $target . ': ' . $x;
        $out[$target] = $shell;
    }

    // ---- 单页壳 ----
    if (empty($ctx['html']['single'])) {
        $warns[] = '缺少 single 渲染结果，单页壳未生成';
    } else {
        list($single, $w) = cfse_skin_single_shell($ctx['html']['single'], $ctx);
        foreach ($w as $x) $warns[] = 'single: ' . $x;
        $out['single'] = $single;
    }

    // ---- 分页结构同步：文章少的归档页 WP 不输出分页容器，从有分页的页面壳借结构补齐 ----
    $ref_shell = '';
    foreach (array('pageHome', 'pageCategory', 'pageTag', 'pageArchive') as $k) {
        if ($out[$k] !== '' && strpos($out[$k], '{{PAGINATION_') !== false) { $ref_shell = $out[$k]; break; }
    }
    if ($ref_shell !== '') {
        $block = cfse_skin_extract_pagination($ref_shell);
        if ($block !== '') {
            // 提取到的分页块可能只含一个占位符（例如列表第一页没有 "Newer posts"）→ 补齐两个
            if (strpos($block, '{{PAGINATION_PREV}}') === false) {
                $block = "\n\t<div class=\"nav-previous\">{{PAGINATION_PREV}}</div>" . $block;
            }
            if (strpos($block, '{{PAGINATION_NEXT}}') === false) {
                $block = $block . "\n\t<div class=\"nav-next\">{{PAGINATION_NEXT}}</div>";
            }
            foreach (array('pageHome', 'pageCategory', 'pageTag', 'pageArchive') as $k) {
                if ($out[$k] !== '' && strpos($out[$k], '{{PAGINATION_') === false && strpos($out[$k], '{{CONTENT}}') !== false) {
                    $out[$k] = str_replace('{{CONTENT}}', '{{CONTENT}}' . "\n" . $block, $out[$k]);
                    $warns[] = $k . '：原页面无分页容器，已补齐分页结构（借自有分页的页面壳）';
                }
            }
        } else {
            $warns[] = '未能从页面壳里提取分页容器结构，文章少的归档页可能没有分页';
        }
    }

    // ---- 侧边栏条目级骨架（widget 定位时提取，Worker 端按主题形态再生）----
    $out['sidebar'] = array();
    if (isset($GLOBALS['cfse_sidebar_tpls']) && is_array($GLOBALS['cfse_sidebar_tpls'])) {
        $out['sidebar'] = $GLOBALS['cfse_sidebar_tpls'];
    }

    // ---- 评论骨架（探针评论提取；Worker 端按主题形态再生，或由第三方嵌入替换同一个位置）----
    $out['comments'] = array();
    if (isset($GLOBALS['cfse_comments_tpl']) && is_array($GLOBALS['cfse_comments_tpl'])) {
        $out['comments'] = $GLOBALS['cfse_comments_tpl'];
    }

    $out['warns'] = $warns;
    return $out;
}

/**
 * 调 tools/skin-render.php 渲染 5 种页面（每种一个进程，规避 WP load-once）。
 * 返回 array(type => html)；失败的类型不出现。
 */
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

function cfse_skin_render_all($probe_id, $site_url, $types = array('home', 'category', 'tag', 'archive', 'single'), $timeout = 90) {
    $script = CFSE_PLUGIN_DIR . 'tools/skin-render.php';
    $GLOBALS['cfse_skin_render_fails'] = array();
    $out = array();
    if (!file_exists($script)) {
        $GLOBALS['cfse_skin_render_fails']['_setup'] = '缺少 tools/skin-render.php';
        return $out;
    }
    $probe_id = (int) $probe_id;
    $base = rtrim((string) $site_url, '/');
    if ($base === '') {
        $GLOBALS['cfse_skin_render_fails']['_setup'] = '站点 URL 为空';
        return $out;
    }
    if (!function_exists('exec')) {
        $GLOBALS['cfse_skin_render_fails']['_setup'] = 'PHP exec() 不可用';
        return $out;
    }

    // 单进程超时：优先用系统 timeout，没有则依赖 PHP 自身超时
    $timeout_bin = trim((string) @shell_exec('command -v timeout 2>/dev/null'));
    $per_type = max(10, (int) floor($timeout / max(1, count($types))));
    $php_bin = function_exists('cfse_cli_php') ? cfse_cli_php() : PHP_BINARY;

    $tmp = sys_get_temp_dir();
    $pid = getmypid();
    foreach ($types as $t) {
        $f = $tmp . '/cfse_skin_' . $pid . '_' . $t . '.html';
        @unlink($f);
        $cmd = escapeshellarg($php_bin) . ' ' . escapeshellarg($script)
            . ' --site=' . escapeshellarg($base)
            . ' --type=' . escapeshellarg($t)
            . ' --probe=' . $probe_id
            . ' --out=' . escapeshellarg($f) . ' 2>&1';
        if ($timeout_bin !== '') {
            $cmd = escapeshellarg($timeout_bin) . ' ' . $per_type . ' ' . $cmd;
        }
        // 串行同步执行：每种页面一个进程（规避 WP load-once），但**不后台化** ——
        // fpm 里后台子进程可能被回收，主请求会白等超时（nginx 504）。
        $lines = array();
        $code = 0;
        @exec($cmd, $lines, $code);
        if ($code === 0 && is_file($f) && filesize($f) > 1000) {
            $out[$t] = (string) file_get_contents($f);
        } else {
            $tail = implode(' ', array_slice((array) $lines, -3));
            $GLOBALS['cfse_skin_render_fails'][$t] = 'exit=' . $code . ($tail !== '' ? ' | ' . substr($tail, 0, 220) : '');
        }
        @unlink($f);
    }
    return $out;
}
