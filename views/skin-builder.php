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

/** 生成日期候选文本（主题可能用不同格式输出日期） */
function cfse_skin_date_candidates($post_date) {
    $ts = strtotime($post_date);
    if (!$ts) return array();
    $out = array();
    foreach (array('Y-m-d', 'Y/m/d', 'F j, Y', 'j F Y', 'M j, Y', 'd M Y', 'm/d/Y', 'Y-n-j', 'Y年n月j日') as $f) {
        $s = date_i18n($f, $ts);
        if ($s !== '') $out[$s] = true;
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
    // 6) 站点级替换。作者归档等站点链接**保留** {{SITE_URL}} 占位符：
    //    渲染端 cfseRenderItem() 会填充它，而 /author/<slug>/ 这类路径由 Worker 路由文件支持。
    $item = cfse_skin_replace_site($item, $ctx);
    // 7) 容器自身的 post-N id/class 去掉（避免模板里写死某一篇）
    $item = preg_replace('~(<[a-zA-Z]+[^>]*)\s+id="post-' . (int) $ctx['probe_id'] . '"~', '$1', $item, 1);
    $item = preg_replace('~(<[a-zA-Z]+[^>]*class="[^"]*)\bpost-' . (int) $ctx['probe_id'] . '\b\s*~', '$1', $item, 1);
    if (strpos($item, '{{POST_TITLE}}') === false) {
        $warns[] = '条目模板缺少 {{POST_TITLE}}（标题 token 可能没匹配上）';
    }
    return array($item, $warns);
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
    return $html;
}

/** 去掉探针自身的动态端点 link（oEmbed / shortlink / 探针 feed） */
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

    // 3) 侧边栏三块 → 占位符（站点级内容，与探针无关；沿用旧的容器特征）
    $n = 0;
    $html = preg_replace('~(<ul\b[^>]*class=["\'][^"\']*wp-block-categories-list[^"\']*["\'][^>]*>).*?(</ul>)~s', '$1{{SIDEBAR_CATEGORIES}}$2', $html, -1, $n);
    if (!$n) $html = preg_replace('~(<ul\b[^>]*class=["\'][^"\']*cat-item[^"\']*["\'][^>]*>).*?(</ul>)~s', '$1{{SIDEBAR_CATEGORIES}}$2', $html, -1, $n);
    if (!$n && cfse_skin_sidebar_replace($html, array('widget_categories', 'wp-block-categories'), '{{SIDEBAR_CATEGORIES}}')) $n = 1;
    if (!$n) $warns[] = '未找到侧边栏分类列表容器，{{SIDEBAR_CATEGORIES}} 缺失';
    $n = 0;
    $html = preg_replace('~(<ul\b[^>]*class=["\'][^"\']*(wp-block-archives|widget_archive)[^"\']*["\'][^>]*>).*?(</ul>)~s', '$1{{SIDEBAR_ARCHIVES}}$2', $html, -1, $n);
    if (!$n) $html = preg_replace('~(<li id="archives-2"[^>]*>.*?<ul>).*?(</ul>)~s', '$1{{SIDEBAR_ARCHIVES}}$2', $html, -1, $n);
    if (!$n && cfse_skin_sidebar_replace($html, array('widget_archive', 'wp-block-archives'), '{{SIDEBAR_ARCHIVES}}')) $n = 1;
    if (!$n) $warns[] = '未找到侧边栏归档列表容器，{{SIDEBAR_ARCHIVES}} 缺失';
    $n = 0;
    $html = preg_replace('~(<ul\b[^>]*class=["\'][^"\']*(wp-tag-cloud|tagcloud)[^"\']*["\'][^>]*>).*?(</ul>)~s', '$1{{SIDEBAR_TAGS}}$2', $html, -1, $n);
    if (!$n && cfse_skin_sidebar_replace($html, array('widget_tag_cloud', 'wp-block-tag-cloud', 'wp-tag-cloud', 'tagcloud'), '{{SIDEBAR_TAGS}}')) $n = 1;
    if (!$n) $warns[] = '未找到侧边栏标签云容器，{{SIDEBAR_TAGS}} 缺失';

    // 4) 站点级替换
    $html = cfse_skin_replace_site($html, $ctx);

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
function cfse_skin_single_shell($html, $ctx) {
    $warns = array();
    $html = cfse_skin_strip_probe_links($html, $ctx);

    // 评论 / 回应 / 上下篇导航（按 id/class 容器删除，带注释锚的也覆盖）
    $html = preg_replace('~<div[^>]*id="comments"[^>]*>.*?</div><!--\s*#comments\s*-->~s', '', $html, 1);
    $html = preg_replace('~<div[^>]*id="respond"[^>]*>.*?</div><!--\s*#respond\s*-->~s', '', $html, 1);
    $html = preg_replace('~<(div|nav)[^>]*class="[^"]*nav-previous[^"]*"[^>]*>.*?</\1>~s', '', $html);
    $html = preg_replace('~<(div|nav)[^>]*class="[^"]*nav-next[^"]*"[^>]*>.*?</\1>~s', '', $html);

    // 正文/标题/日期/分类/标签/URL：整页当一个容器处理
    list($html, $warns2) = cfse_skin_replace_item($html, $ctx, true);
    // 条目注释锚同样清掉
    $html = preg_replace('~<!--\s*#post-\d+\s*-->~', '', $html);
    // 分类 / 标签 feed link（静态站无该路由）
    $html = preg_replace('~<link\b[^>]*rel=["\']alternate["\'][^>]*href=["\'][^"\']*/(category|tag)/[^"\']*/feed/["\'][^>]*/?>~i', '', $html);
    $html = preg_replace('~<link\b[^>]*href=["\'][^"\']*/(category|tag)/[^"\']*/feed/["\'][^>]*rel=["\']alternate["\'][^>]*/?>~i', '', $html);
    $warns = array_merge($warns, $warns2);
    $html = str_replace($ctx['probe_title'], '{{POST_TITLE}}', $html);
    return array($html, $warns);
}

/**
 * 主入口：用 5 份内部渲染结果生成 6 类模板。
 * $ctx 需含：token, probe_id, probe_title, permalink, post_date, cat_names, tag_names,
 *            site_url, site_name, site_desc, html => array(home|category|tag|archive|single)
 */
function cfse_skin_map_templates($ctx) {
    $warns = array();
    $ctx['date_candidates'] = cfse_skin_date_candidates($ctx['post_date']);

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

    $out['warns'] = $warns;
    return $out;
}

/**
 * 调 tools/skin-render.php 渲染 5 种页面（每种一个进程，规避 WP load-once）。
 * 返回 array(type => html)；失败的类型不出现。
 */
function cfse_skin_render_all($probe_id, $site_url, $types = array('home', 'category', 'tag', 'archive', 'single'), $timeout = 90) {
    $script = CFSE_PLUGIN_DIR . 'tools/skin-render.php';
    if (!file_exists($script)) return array();
    $probe_id = (int) $probe_id;
    $base = rtrim((string) $site_url, '/');
    if ($base === '') return array();

    $out = array();
    if (!function_exists('exec')) {
        return $out;
    }

    $tmp = sys_get_temp_dir();
    $pid = getmypid();
    $files = array();
    $cmds = array();
    foreach ($types as $t) {
        $f = $tmp . '/cfse_skin_' . $pid . '_' . $t . '.html';
        @unlink($f);
        $files[$t] = $f;
        $cmds[$t] = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' --site=' . escapeshellarg($base)
            . ' --type=' . escapeshellarg($t)
            . ' --probe=' . $probe_id
            . ' --out=' . escapeshellarg($f)
            . ' > /dev/null 2>&1';
    }
    // 并行启动（每种页面一个进程）
    foreach ($cmds as $t => $cmd) {
        @exec($cmd . ' &');
    }
    // 轮询等待
    $deadline = time() + $timeout;
    while (time() < $deadline) {
        $pending = 0;
        foreach ($files as $t => $f) {
            if (!is_file($f) || filesize($f) < 1000) $pending++;
        }
        if ($pending === 0) break;
        usleep(200000);
    }
    foreach ($files as $t => $f) {
        if (is_file($f) && filesize($f) > 1000) {
            $out[$t] = (string) file_get_contents($f);
        }
        @unlink($f);
    }
    return $out;
}
