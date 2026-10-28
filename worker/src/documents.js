/**
 * documents.js —— 文档库（Document Library）
 *
 * 独立于博客 posts 的内容来源：存量 MD/HTML 文件上传为原稿（R2 abc/<路径>），
 * 元数据存 D1 documents 表；访问时套「目录级壳」（D1 styles）渲染成品（R2 xyz/<路径>.html）。
 *
 * 设计依据：docs/document-library.md（v1.1 定稿）
 *   · abc = 原稿目录（渲染路径来源）；xyz = 可访问前缀 = 成品 R2 前缀（1:1 映射）
 *   · docRoutes 权威存 D1 settings，site:ctx 是渲染读的快照
 *   · 内链改写 = 纯路径前缀判断，零 D1 查询（D22）
 *   · 侧边栏树烘焙进成品页（访问零成本）
 *   · KV 读只在 miss 之后（命中 0 KV）
 */

// 注意：site.js / md.js 采用函数内动态 import（renderDocument / renderDocIndex 内部），
// 使本模块的纯函数（validate / rewrite / sidebar / key 推导）可脱离 Worker 环境单独测试。

// ============================================================
// 校验：保留前缀（xyz 不能与之冲突）
// ============================================================
const RESERVED_PREFIXES = [
    // 已占用路由
    'api', 'admin', 'feed', 'category', 'tag', 'wp-json', 'wp-content', 'wp-includes',
    'preview', 'sync', 'comments', 'upload', 'delete', 'd1-batch', 'style-list',
    'purge-cache', 'page', 'robots.txt', 'favicon.ico', 'favicon.png', 'sitemap.xml',
    // 未来预留（资源前缀，避免被文档库占用）
    'assets', 'media', 'static',
    // 文档库自身相关
    'documents',
];

/**
 * 默认文档壳（DEFAULT_DOC_SHELL）—— 未指定 docShell 时使用。
 * 占位符：{{DOC_TITLE}} {{CONTENT}} {{DOC_SIDEBAR}} {{SITE_NAME}} {{SITE_URL}} {{SITE_DESC}}
 * 结构：顶栏（站名）+ 左栏粘性侧边栏目录树 + 右主内容；窄屏折为上下。
 * 后台可另存自定义文档壳（存 D1 styles 的 tpl_json.docShell）。
 */
export const DEFAULT_DOC_SHELL = '<!DOCTYPE html>\n<html lang="zh-CN">\n<head>\n<meta charset="UTF-8">\n'
    + '<meta name="viewport" content="width=device-width, initial-scale=1.0">\n'
    + '<title>{{DOC_TITLE}} · {{SITE_NAME}}</title>\n'
    + '<meta name="description" content="{{SITE_DESC}}">\n'
    + '<style>\n'
    + ':root{--fg:#1f2328;--muted:#6b7280;--line:#e5e7eb;--accent:#2563eb;--bg:#fff;--code-bg:#f6f8fa}\n'
    + '*{box-sizing:border-box}\n'
    + 'body{margin:0;font:16px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"PingFang SC","Microsoft YaHei",sans-serif;color:var(--fg);background:var(--bg)}\n'
    + '.doc-top{border-bottom:1px solid var(--line);padding:12px 20px;font-weight:600}\n'
    + '.doc-top a{color:var(--fg);text-decoration:none}\n'
    + '.doc-layout{display:flex;gap:32px;max-width:1180px;margin:0 auto;padding:24px 20px 60px}\n'
    + '.doc-side-col{flex:0 0 240px;position:sticky;top:16px;align-self:flex-start;max-height:calc(100vh - 32px);overflow:auto}\n'
    + '.doc-main{flex:1;min-width:0}\n'
    + '.doc-sidebar{list-style:none;margin:0;padding:0;font-size:14px}\n'
    + '.doc-sidebar ul{list-style:none;margin:0;padding-left:14px}\n'
    + '.doc-sidebar li{margin:2px 0}\n'
    + '.doc-sidebar a{color:var(--muted);text-decoration:none;display:block;padding:3px 8px;border-radius:6px}\n'
    + '.doc-sidebar a:hover{background:#f3f4f6;color:var(--fg)}\n'
    + '.doc-sidebar a.active{color:var(--accent);font-weight:600;background:#eff6ff}\n'
    + '.doc-sidebar .doc-dir-name{display:block;padding:6px 8px 2px;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.03em;opacity:.65}\n'
    + '.doc-title{margin:0 0 4px;font-size:30px;line-height:1.25}\n'
    + '.doc-body h1,.doc-body h2,.doc-body h3{line-height:1.3;margin:1.4em 0 .5em}\n'
    + '.doc-body h2{border-bottom:1px solid var(--line);padding-bottom:.3em}\n'
    + '.doc-body a{color:var(--accent)}\n'
    + '.doc-body code{background:var(--code-bg);padding:.15em .4em;border-radius:4px;font-size:.9em;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}\n'
    + '.doc-body pre{background:var(--code-bg);padding:14px 16px;border-radius:8px;overflow:auto}\n'
    + '.doc-body pre code{background:none;padding:0}\n'
    + '.doc-body blockquote{margin:1em 0;padding:.2em 1em;border-left:4px solid var(--line);color:var(--muted)}\n'
    + '.doc-body table{border-collapse:collapse;width:100%;margin:1em 0}\n'
    + '.doc-body th,.doc-body td{border:1px solid var(--line);padding:8px 10px;text-align:left}\n'
    + '.doc-body img{max-width:100%;height:auto;border-radius:6px}\n'
    + '.doc-body hr{border:0;border-top:1px solid var(--line);margin:2em 0}\n'
    + '@media(max-width:860px){.doc-layout{flex-direction:column;gap:16px}.doc-side-col{position:static;max-height:none;flex:none;order:2;border-top:1px solid var(--line);padding-top:12px}.doc-main{order:1}}\n'
    + '</style>\n</head>\n<body>\n'
    + '<header class="doc-top"><a href="{{SITE_URL}}/">{{SITE_NAME}}</a></header>\n'
    + '<div class="doc-layout">\n'
    + '<aside class="doc-side-col">{{DOC_SIDEBAR}}</aside>\n'
    + '<main class="doc-main">\n'
    + '<h1 class="doc-title">{{DOC_TITLE}}</h1>\n'
    + '<article class="doc-body">{{CONTENT}}</article>\n'
    + '</main>\n</div>\n</body>\n</html>';

/** 校验 xyz 前缀是否合法；返回 {ok, error?} */
export function validateRoutePrefix(xyz) {
    const s = String(xyz || '').trim().replace(/^\/+|\/+$/g, '');
    if (!s) return { ok: false, error: 'xyz 前缀不能为空' };
    if (/[\/\\?\s]/.test(s)) return { ok: false, error: 'xyz 前缀只能是一段路径（不含 / 空格等）' };
    if (RESERVED_PREFIXES.includes(s.toLowerCase())) return { ok: false, error: 'xyz 前缀 "' + s + '" 是保留前缀，不能使用' };
    return { ok: true, value: s };
}

/** 校验文档相对路径（abc 内）是否合法；返回 {ok, error?, value?} */
export function validateDocPath(path) {
    const s = String(path || '').replace(/^\/+/, '');
    if (!s) return { ok: false, error: '路径不能为空' };
    // 段级校验：不得有空段 / `.` / `..` 段（对齐 r2KeyFromPath 的防穿越）。
    // 注意：段内的连续点（如 `Compose..md`）不是穿越，**允许**；空格也允许（URL 侧会 percent-encode）。
    const segs = s.split('/');
    for (const seg of segs) {
        if (seg === '' || seg === '.' || seg === '..') return { ok: false, error: '路径含非法段（空段 / . / ..）' };
        if (seg.indexOf('\0') !== -1) return { ok: false, error: '路径含非法字符' };
    }
    if (!/\.(md|html?)$/i.test(s)) return { ok: false, error: '仅支持 .md / .html / .htm 文件' };
    // 末段不能是 index（成品 key 会与目录页 xyz/index.html 冲突）
    const base = s.replace(/\.(md|html?)$/i, '');
    const last = base.split('/').pop() || '';
    if (last.toLowerCase() === 'index') return { ok: false, error: '原稿路径最后一段不能是 index（与目录页冲突）' };
    return { ok: true, value: s, format: (/\.md$/i.test(s) ? 'md' : 'html') };
}

/** 文档路径 → 成品相对 key（去扩展名 + .html） */
export function docPathToOutKey(path) {
    const base = String(path || '').replace(/\.(md|html?)$/i, '');
    return base + '.html';
}

/** 文档路径 → 标题（缺省用文件名） */
export function docTitleFromPath(path, title) {
    if (title && String(title).trim()) return String(title).trim();
    const base = String(path || '').replace(/\.(md|html?)$/i, '');
    const file = base.split('/').pop() || 'doc';
    return file;
}

/** 原稿 R2 key = abc/<路径> */
export function sourceKey(abc, path) {
    return String(abc || '').replace(/^\/+|\/+$/g, '') + '/' + String(path || '');
}

/** 成品 R2 key = xyz/<路径去扩展名>.html */
export function outKey(xyz, path) {
    return String(xyz || '').replace(/^\/+|\/+$/g, '') + '/' + docPathToOutKey(path);
}

/** 成品 URL（用于 href）= /xyz/<路径去扩展名>.html，各段 percent-encoded（中文/空格安全）；
 *  注意与 outKey（R2 存储 key，原文）区分：R2 key 不解码，href 要编码。 */
export function outUrl(xyz, path) {
    const x = String(xyz || '').replace(/^\/+|\/+$/g, '');
    const rel = docPathToOutKey(path).split('/').map(function (s) {
        try { return encodeURIComponent(decodeURIComponent(s)); } catch (e) { return encodeURIComponent(s); }
    }).join('/');
    return '/' + x + '/' + rel;
}

/** 目录页 URL = /xyz/ */
export function indexUrl(xyz) {
    return '/' + String(xyz || '').replace(/^\/+|\/+$/g, '') + '/';
}

// ============================================================
// 内链改写：纯路径前缀判断，零 D1 查询（D22）
// ============================================================

/**
 * 把原稿 HTML 里指向「同库文件」的相对链接改写为成品 URL。
 * @param {string} html        原稿转换后的 HTML
 * @param {string} abc         该库原稿目录
 * @param {string} xyz         该库可访问前缀
 * @param {string} currentPath 当前文档路径（相对 abc），用于解析相对链接
 */
export function rewriteDocLinks(html, abc, xyz, currentPath) {
    const currentDir = (String(currentPath || '').split('/').slice(0, -1).join('/'));
    const abcNorm = String(abc || '').replace(/^\/+|\/+$/g, '');
    const xyzNorm = String(xyz || '').replace(/^\/+|\/+$/g, '');

    // 处理 <a href>、<img src> 的相对链接
    return String(html || '').replace(/(<(?:a|img|source|video|audio)\b[^>]*\b(?:href|src)\s*=\s*["'])([^"']*)(["'])/gi, function (m, pre, href, post) {
        const h = href.trim();
        // 外链 / 锚点 / 站内绝对（非本站文档）/ 协议相对 —— 跳过
        if (!h || h.startsWith('#') || h.startsWith('http://') || h.startsWith('https://') ||
            h.startsWith('//') || h.startsWith('mailto:') || h.startsWith('tel:') || h.startsWith('data:')) {
            return m;
        }
        // 去掉锚点
        const hashIdx = h.indexOf('#');
        const hash = hashIdx >= 0 ? h.slice(hashIdx) : '';
        let target = hashIdx >= 0 ? h.slice(0, hashIdx) : h;
        if (!target) return m; // 纯锚点
        // 去掉 query
        const qIdx = target.indexOf('?');
        if (qIdx >= 0) target = target.slice(0, qIdx);

        // 站内绝对路径（/abc/...）：若落在该库 abc 前缀 → 改写
        if (target.startsWith('/')) {
            const t = target.replace(/^\/+/, '');
            if (t.startsWith(abcNorm + '/')) {
                const rel = t.slice(abcNorm.length).replace(/^\/+/, '');
                return pre + outUrlForTarget(xyzNorm, rel) + hash + post;
            }
            return m;
        }
        // 相对路径：解析相对 currentDir（currentDir 与 currentPath 均为「相对 abc」的路径）→ 一律改写
        // 注：resolveRel 结果已是「相对 abc」的路径（原稿都在 abc 下），无需再匹配 abc 前缀
        const resolved = resolveRel(currentDir, target);
        if (!resolved) return m;
        return pre + outUrlForTarget(xyzNorm, resolved) + hash + post;
    });
}

/** 改写目标 URL：md/html → .html 成品；其它扩展名（图片等）保持原样（xyz 同路径） */
function outUrlForTarget(xyz, rel) {
    const x = String(xyz).replace(/^\/+|\/+$/g, '');
    let r = String(rel);
    if (/\.(md|markdown|html?)$/i.test(r)) r = r.replace(/\.(md|markdown|html?)$/i, '') + '.html';
    const enc = r.split('/').map(function (s) {
        try { return encodeURIComponent(decodeURIComponent(s)); } catch (e) { return encodeURIComponent(s); }
    }).join('/');
    return '/' + x + '/' + enc;
}

/** 解析相对路径（target 相对 baseDir），返回归一化路径（不含前导 /） */
function resolveRel(baseDir, target) {
    const parts = [];
    (baseDir ? String(baseDir).split('/') : []).forEach(function (p) { if (p) parts.push(p); });
    String(target).split('/').forEach(function (seg) {
        if (!seg || seg === '.') return;
        if (seg === '..') { parts.pop(); } else { parts.push(seg); }
    });
    return parts.join('/');
}

// ============================================================
// 侧边栏目录树（docNav=sidebar）
// ============================================================

/**
 * 由文档列表生成侧边栏目录树 HTML（<ul> 嵌套）。
 * @param {Array} docs  [{path, title}]（已按路径排序）
 * @param {string} xyz  可访问前缀
 * @param {string} currentPath 当前文档路径（相对 abc），用于高亮
 */
export function sidebarTreeHtml(docs, xyz, currentPath) {
    const xyzNorm = String(xyz || '').replace(/^\/+|\/+$/g, '');
    const tree = {};
    (docs || []).forEach(function (d) {
        const parts = String(d.path || '').split('/');
        const file = parts.pop() || '';
        let node = tree;
        parts.forEach(function (p) {
            if (!p) return;
            if (!node[p]) node[p] = { _dir: true, children: {} };
            node = node[p].children;
        });
        node[file] = { _file: true, path: d.path, title: d.title || file };
    });

    const esc = function (s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); };
    const cur = String(currentPath || '');

    const renderNode = function (obj) {
        let out = '';
        const keys = Object.keys(obj).sort(function (a, b) {
            // 目录在前，文件按名
            const da = obj[a]._dir ? 0 : 1, db = obj[b]._dir ? 0 : 1;
            if (da !== db) return da - db;
            return a.localeCompare(b);
        });
        for (const k of keys) {
            const v = obj[k];
            if (v._dir) {
                out += '<li class="doc-dir"><span class="doc-dir-name">' + esc(k) + '</span><ul>' + renderNode(v.children) + '</ul></li>';
            } else {
                const url = outUrl(xyzNorm, v.path);
                const active = (v.path === cur) ? ' class="active"' : '';
                out += '<li><a href="' + url + '"' + active + '>' + esc(v.title) + '</a></li>';
            }
        }
        return out;
    };
    return '<ul class="doc-sidebar">' + renderNode(tree) + '</ul>';
}

// ============================================================
// 渲染文档页（miss 时：原稿 → 内链改写 → 侧边栏树 → 套壳 → 成品）
// ============================================================

/** 从 md / html 原稿提取标题（frontmatter title / <title> / 文件名） */
export function extractDocTitle(content, format, path) {
    let title = '';
    if (format === 'md') {
        const m = String(content || '').match(/^---\s*\n([\s\S]*?)\n---/);
        if (m) {
            const fm = m[1].match(/^\s*title\s*:\s*(.+)$/m);
            if (fm) title = String(fm[1]).trim().replace(/^["']|["']$/g, '');
        }
        // 回退：正文第一个 `# 标题`（去掉 frontmatter 后再找）
        if (!title) {
            const body = String(content || '').replace(/^---\s*\n[\s\S]*?\n---\s*\n?/, '');
            const h1 = body.match(/^#\s+(.+)$/m);
            if (h1) title = String(h1[1]).trim();
        }
    } else {
        const m = String(content || '').match(/<title[^>]*>([\s\S]*?)<\/title>/i);
        if (m) title = String(m[1]).trim();
    }
    return docTitleFromPath(path, title);
}

/** 从 md / html 原稿提取摘要 */
export function extractDocExcerpt(content, format) {
    const s = String(content || '');
    if (format === 'md') {
        const fm = s.match(/^---\s*\n([\s\S]*?)\n---/);
        if (fm) {
            const m = fm[1].match(/^\s*description\s*:\s*(.+)$/m);
            if (m) return String(m[1]).trim().replace(/^["']|["']$/g, '');
        }
        const text = s.replace(/^---\s*\n[\s\S]*?\n---\s*\n?/, '').replace(/[#>*_`~\[\]()-]/g, ' ').replace(/\s+/g, ' ').trim();
        return text.slice(0, 200);
    }
    const m = s.match(/<meta\s+name=["']description["']\s+content=["']([^"']*)["']/i);
    if (m) return String(m[1]).trim();
    const text = s.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    return text.slice(0, 200);
}

/**
 * 渲染一篇文档为成品 HTML（不落 R2，由调用方决定写回）。
 * @param {object} env
 * @param {object} route  {abc, xyz, styleId, docNav, docIndex}
 * @param {object} doc    documents 行
 * @param {object} shared {docs: 全库列表(可空), shell: 壳模板(可空)}  批量渲染时预取
 * @returns {Promise<string|null>} HTML 或 null（取原稿/壳失败）
 */
export async function renderDocument(env, route, doc, shared) {
    const abc = String(route.abc || '').replace(/^\/+|\/+$/g, '');
    const xyz = String(route.xyz || '').replace(/^\/+|\/+$/g, '');
    const path = String(doc.path || '');
    const format = String(doc.format || (path.toLowerCase().endsWith('.md') ? 'md' : 'html'));

    // 1. 取原稿
    let raw;
    try {
        const obj = await env.R2_BUCKET.get(sourceKey(abc, path));
        if (!obj) return null;
        raw = await obj.text();
    } catch (e) { console.error('doc render: 取原稿失败', e); return null; }

    // 2. 正文转换
    let bodyHtml;
    if (format === 'md') {
        // 去掉 frontmatter 再转 md
        const noFm = String(raw).replace(/^---\s*\n[\s\S]*?\n---\s*\n?/, '');
        const { mdToHtml } = await import('./md');
        bodyHtml = mdToHtml(noFm);
    } else {
        bodyHtml = String(raw);
    }

    // 3. 内链改写
    bodyHtml = rewriteDocLinks(bodyHtml, abc, xyz, path);

    // 4. 侧边栏树
    let sidebarHtml = '';
    const docNav = String(route.docNav || 'sidebar');
    if (docNav === 'sidebar') {
        const docs = (shared && Array.isArray(shared.docs)) ? shared.docs : null;
        let list = docs;
        if (!list) {
            try {
                const res = await env.D1_DATABASE.prepare(
                    "SELECT path, title FROM documents WHERE source_prefix = ? AND status = 'published' ORDER BY path"
                ).bind(abc).all();
                list = (res && res.results) || [];
            } catch (e) { list = []; }
        }
        sidebarHtml = sidebarTreeHtml(list, xyz, path);
    }

    // 5. 取壳（D1 styles 最新版）
    let shell = (shared && shared.shell) || null;
    if (!shell && route.styleId) {
        try {
            const row = await env.D1_DATABASE.prepare(
                "SELECT tpl_json FROM styles WHERE id = ? ORDER BY version DESC LIMIT 1"
            ).bind(String(route.styleId)).first();
            if (row) { try { shell = JSON.parse(row.tpl_json || '{}'); } catch (e) { shell = null; } }
        } catch (e) { shell = null; }
    }
    // 壳模板：只认 docShell（文档壳）——**不**回退到文章 single 壳
    // （文章壳占位符是 {{POST_CONTENT}} 等，与文档的 {{CONTENT}} 不同 ⇒ 会用成空正文）
    let shellTpl = (shell && typeof shell === 'object' && shell.docShell) ? String(shell.docShell) : '';
    if (!shellTpl) shellTpl = DEFAULT_DOC_SHELL;

    // 6. 填占位符
    const { getSiteContext } = await import('./site');
    const site = await getSiteContext(env);
    function repVal(v) {
        if (v === 'DOC_TITLE') return escHtml(docTitleFromPath(path, doc.title));
        if (v === 'CONTENT') return bodyHtml;
        if (v === 'DOC_SIDEBAR') return sidebarHtml;
        if (v === 'SITE_NAME') return escHtml(site.name || '');
        if (v === 'SITE_URL') return escUrl(site.url || '');
        if (v === 'SITE_DESC') return escHtml(site.description || '');
        return '';
    }
    // 顺序很重要：**先把壳里未实现的占位符清掉，再注入正文**。
    // 旧实现是「注入正文 → 全局兜底清理」，会把正文（md 原稿）里形如 {{POST_TITLE}}
    // 的字面量一起删掉 —— 一旦文档讲解占位符本身，正文就会被吃空。
    const DOC_KEYS = ['DOC_TITLE', 'CONTENT', 'DOC_SIDEBAR', 'SITE_NAME', 'SITE_URL', 'SITE_DESC'];
    let h = shellTpl;
    // 壳里的已知占位符先换成哨兵（NUL 包裹；正文里不可能出现该串）
    DOC_KEYS.forEach(function (k, i) { h = h.split('{{' + k + '}}').join('\u0000cfseDoc' + i + '\u0000'); });
    // 壳里其余未实现的占位符兜底清空（此时正文尚未注入 ⇒ 不会波及内容）
    h = h.replace(/\{\{[#/]?[A-Z][A-Z0-9_]*\}\}/g, '');
    // 哨兵换成真实值（CONTENT = 正文 HTML）
    DOC_KEYS.forEach(function (k, i) { h = h.split('\u0000cfseDoc' + i + '\u0000').join(repVal(k)); });
    // head 自定义 HTML（AdSense meta 等）：与文章页一致，注入 </head> 前
    const { cfseInjectHead } = await import('./template-engine');
    return cfseInjectHead(h, site.headExtra);
}

function escHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function escUrl(s) {
    return escHtml(s);
}

// ============================================================
// 目录页（docIndex=true）：/xyz/ → 文档列表
// ============================================================

export async function renderDocIndex(env, route) {
    const abc = String(route.abc || '').replace(/^\/+|\/+$/g, '');
    const xyz = String(route.xyz || '').replace(/^\/+|\/+$/g, '');
    let docs = [];
    try {
        const res = await env.D1_DATABASE.prepare(
            "SELECT path, title FROM documents WHERE source_prefix = ? AND status = 'published' ORDER BY path"
        ).bind(abc).all();
        docs = (res && res.results) || [];
    } catch (e) { docs = []; }
    const items = docs.map(function (d) {
        return '<li><a href="' + outUrl(xyz, d.path) + '">' + escHtml(d.title || d.path) + '</a> <span class="muted">' + escHtml(d.path) + '</span></li>';
    }).join('\n');
    const { getSiteContext } = await import('./site');
    const site = await getSiteContext(env);
    const { cfseInjectHead } = await import('./template-engine');
    return cfseInjectHead('<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
        + '<title>' + escHtml(site.name || '文档') + ' · 文档目录</title>'
        + '<style>body{font-family:-apple-system,"Segoe UI",Roboto,sans-serif;max-width:760px;margin:0 auto;padding:32px 20px;line-height:1.7;color:#333}'
        + '.doc-index a{color:#0066cc;text-decoration:none}.doc-index li{margin:6px 0}.muted{color:#999;font-size:13px}</style></head><body>'
        + '<h1>' + escHtml(site.name || '文档') + '</h1><p class="muted">文档目录</p>'
        + '<ul class="doc-index">' + (items || '<li>（空）</li>') + '</ul></body></html>', site.headExtra);
}

/** 判断请求路径是否为某库的文档路径（<xyz>/<多级>.html 或 <xyz>/）；
 *  doc 分支返回 { base }：已解码、去扩展名的相对路径（用于按 .md/.html/.htm 查 documents） */
export function matchDocRoute(path, routes) {
    const p = String(path || '');
    if (!Array.isArray(routes) || !routes.length) return null;
    for (const r of routes) {
        const xyz = String((r && r.xyz) || '').replace(/^\/+|\/+$/g, '');
        if (!xyz) continue;
        const prefix = '/' + xyz;
        // 目录页 /xyz/
        if (p === prefix + '/') return { route: r, kind: 'index', base: '' };
        // 文档 /xyz/xxx.html（URL 侧一律 .html；原稿可能是 .md/.html/.htm）
        if (p.startsWith(prefix + '/') && /\.html?$/i.test(p)) {
            let rel = p.slice(prefix.length + 1);
            try { rel = decodeURIComponent(rel); } catch (e) { /* 保持原样 */ }
            const base = rel.replace(/\.html?$/i, '');
            return { route: r, kind: 'doc', base: base };
        }
    }
    return null;
}
