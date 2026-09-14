/**
 * admin.js —— Worker 管理后台 API（/api/*）
 * 鉴权：除 /api/login /api/refresh 外均需 Authorization: Bearer <access token>
 * 依赖：auth.js（JWT/登录）、cache.js（发布后清列表缓存）
 */
import { authenticate, signJwt, verifyJwt, bearerToken, loginRateLimit, hashPassword, verifyPassword } from './auth';
import { deleteCache, purgeCache } from './cache';
import { CFSE_SINGLE_TPL, renderHomepage, renderCategoryPage, renderTagPage, renderArchivePage } from './templates';
import { mdToHtml, mdToText } from './md';
import { getSiteContext } from './site';
import { getActiveTpl } from './tplstore';
import { getActiveTplContent } from './tplstore';
import { getCacheConfig } from './cacheconfig';

const ACCESS_TTL = 15 * 60;   // 15 min
const REFRESH_TTL = 7 * 86400; // 7 d

/** 粗略净化正文：移除脚本/样式/表单/危险属性（前台不可信编辑来源防护） */
function scrubHtml(html) {
    let h = String(html || '');
    h = h.replace(/<script[\s\S]*?<\/script\s*>/gi, '');
    h = h.replace(/<style[\s\S]*?<\/style\s*>/gi, '');
    h = h.replace(/<(iframe|object|embed|form)\b[\s\S]*?<\/\1\s*>/gi, '');
    h = h.replace(/<(iframe|object|embed)\b[^>]*\/?>/gi, '');
    h = h.replace(/\s+on\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '');
    h = h.replace(/\b(href|src|xlink:href)\s*=\s*("javascript:[^"]*"|'javascript:[^']*'|javascript:[^\s>]+)/gi, '$1="#"');
    return h;
}

// 写操作限速（内存滑窗：每用户 90 次/分钟；isolate 级，0 KV 成本）
const WRATE_LIMIT = 90;
const wrateMap = new Map();
function wrateAllowed(uid) {
    const now = Date.now();
    const e = wrateMap.get(uid);
    if (!e || now >= e.resetAt) {
        if (wrateMap.size > 32) { wrateMap.forEach(function (v, k) { if (now >= v.resetAt) wrateMap.delete(k); }); }
        wrateMap.set(uid, { n: 1, resetAt: now + 60000 });
        return true;
    }
    e.n++;
    return e.n <= WRATE_LIMIT;
}

function json(data, status = 200) {
    return new Response(JSON.stringify(data), {
        status: status,
        headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' },
    });
}

function slugify(text) {
    let s = String(text || '').trim().toLowerCase();
    if (!s) return '';
    // 非 ASCII（中文等）→ 小写 %xx（与 WP sanitize_title 及库内既有 slug 形态一致，渲染端直接拼接）
    s = s.replace(/[^\x00-\x7F]+/g, function (m) { return encodeURIComponent(m).toLowerCase(); });
    // ASCII 部分：仅保留 a-z0-9%（%xx 编码用），其余连续字符 → '-'
    s = s.replace(/[^a-z0-9%]+/g, '-').replace(/^-+|-+$/g, '');
    return s;
}

/** WordPress 默认分类：无分类文章自动归入（对齐 WP get_option('default_category') 行为） */
const DEFAULT_CATEGORY = { name: 'Uncategorized', slug: 'uncategorized' };

/** 分类列表为空（或全部无效）时补默认分类 */
function withDefaultCategory(list) {
    const arr = (Array.isArray(list) ? list : []).filter(function (x) {
        if (!x) return false;
        return typeof x === 'string' ? x.trim() !== '' : String(x.name || '').trim() !== '';
    });
    return arr.length ? arr : [DEFAULT_CATEGORY];
}

function pad(n) { return n < 10 ? '0' + n : String(n); }

function nowDateTime() {
    return localDateTime(0);
}

/** 按站点时区偏移生成墙钟时间字符串 YYYY-MM-DD HH:MM:SS（与 WP 端 gmt_offset 对齐） */
function localDateTime(offsetSec) {
    const off = parseInt(offsetSec || 0, 10) || 0;
    const d = new Date(Date.now() + off * 1000);
    return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate())
        + ' ' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds());
}

function excerptOf(content, len = 200) {
    const text = String(content || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    return text.length > len ? text.slice(0, len) + '...' : text;
}

/** 解析 DB 中 categories/tags 列的 JSON（失败返回空数组） */
function parseTaxoList(s) {
    try {
        const a = JSON.parse(String(s || '[]'));
        return Array.isArray(a) ? a : [];
    } catch (e) {
        return [];
    }
}

function b64ToBytes(b64) {
    const bin = atob(b64);
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return bytes;
}

/** 模板类型（6 类）：单页壳 / 列表条目 / 四种页面外壳 */
const TPL_KINDS = ['single', 'item', 'pageHome', 'pageCategory', 'pageTag', 'pageArchive'];
function tplKind(v) {
    const s = String(v || '');
    return TPL_KINDS.indexOf(s) >= 0 ? s : 'single';
}

/** 读模板索引（tpl:index）；缺失时返回 6 类空结构 */
async function tplGetIndex(env) {
    const out = {};
    for (const k of TPL_KINDS) out[k] = [];
    try {
        const v = await env.KV_CACHE.get('tpl:index');
        if (v) {
            const o = JSON.parse(v);
            for (const k of TPL_KINDS) if (Array.isArray(o[k])) out[k] = o[k];
        }
    } catch (e) { /* fallthrough */ }
    return out;
}

/**
 * 读【内置包索引】。
 * 唯一来源 = KV tpl:index（部署时由 scripts/sync-themes-index.mjs 扫描
 * static/wp-content/themes/<slug>/tpl.json 注入）。
 */
async function builtinIndex(env) {
    // 内置风格清单的唯一来源 = KV tpl:index。
    // 它由**部署时**的 scripts/sync-themes-index.mjs 扫描 static/wp-content/themes/*/tpl.json 注入
    // （所以新增/删除风格只要 npm run deploy 就立即反映；也不再需要任何 list/index 静态文件）。
    // 唯一来源 = tpl:index（旧键 tpl:sindex-builtin 已废弃并清理，不再读写）。
    let out = [];
    try {
        const v = await env.KV_CACHE.get('tpl:index');
        if (v) {
            const o = JSON.parse(v);
            if (Array.isArray(o)) out = o;
        }
    } catch (e) { /* fallthrough */ }
    return out.map(function (x) {
        return { id: String(x.id || ''), name: String(x.name || x.id || ''), updated: String(x.updated || ''), source: 'builtin' };
    }).filter(function (x) { return x.id; });
}

/** 读风格清单 = 私人包（tpl:sindex）+ 内置包（builtinIndex，内置在前） */
async function styleIndex(env) {
    let user = [];
    try {
        const v = await env.KV_CACHE.get('tpl:sindex');
        if (v) {
            const o = JSON.parse(v);
            if (Array.isArray(o)) user = o.filter(function (x) { return x && x.source !== 'builtin'; });
        }
    } catch (e) { /* fallthrough */ }
    const builtin = await builtinIndex(env);
    const userIds = new Set(user.map(function (x) { return x.id; }));
    return builtin.filter(function (x) { return !userIds.has(x.id); }).concat(user);
}

/**
 * 读【用户风格】清单：只读 tpl:sindex 本身。
 * 注意不能拿 styleIndex() 的合并结果回写 tpl:sindex —— 那会把 builtin 项写进用户索引，
 * 下一次合并时 builtin 又被 userIds 过滤掉，内置风格就会"变成"私人包。
 * 这里同时剔除历史上被误写进来的 builtin 项（source==='builtin'）。
 */
async function userStyleIndex(env) {
    let out = [];
    try {
        const v = await env.KV_CACHE.get('tpl:sindex');
        if (v) {
            const o = JSON.parse(v);
            if (Array.isArray(o)) out = o;
        }
    } catch (e) { /* fallthrough */ }
    return out.filter(function (x) { return x && x.source !== 'builtin'; });
}

/**
 * 只保留某私人风格的最近 N 个版本（默认 5）。
 * 不做“永久保留 v1”这类特殊处理：想回到原生，直接应用对应的内置包即可。
 */
async function pruneStyleVersions(env, id, keep = 5) {
    try {
        const row = await env.D1_DATABASE.prepare('SELECT MAX(version) AS v FROM styles WHERE id = ?').bind(id).first();
        const max = (row && row.v) ? parseInt(row.v, 10) : 0;
        const floor = max - keep + 1;
        if (max > keep && floor > 1) {
            await env.D1_DATABASE.prepare('DELETE FROM styles WHERE id = ? AND version < ?').bind(id, floor).run();
        }
    } catch (e) { /* 清理失败不影响主流程 */ }
}

/** 确保 styles 表存在（幂等；WP 端 schema 迁移也会建，这里兜底） */
async function ensureStylesTable(env) {
    try {
        await env.D1_DATABASE.prepare('CREATE TABLE IF NOT EXISTS styles (id TEXT NOT NULL, version INTEGER NOT NULL, name TEXT, tpl_json TEXT, updated_at TEXT, PRIMARY KEY (id, version))').run();
    } catch (e) { /* fallthrough */ }
}

/**
 * 渲染单类模板的预览 HTML（/api/templates/preview 与 /api/templates/style/preview 共用）。
 * tplAll 可选：{item,pageHome,...}，用于风格级预览时按同一套组合渲染。
 */
async function tplPreviewHtml(env, type, content, tplAll) {
    const site = await getSiteContext(env);
    const actItem = (tplAll && typeof tplAll.item === 'string') ? tplAll.item : await getActiveTplContent(env, 'item');
    const sample = [];
    for (let i = 1; i <= 5; i++) {
        sample.push({
            wp_id: 90000 + i, slug: 'preview-' + i, title: '预览样例文章 ' + i,
            date: '2026-09-10 12:0' + i + ':00',
            excerpt: '这是一段用于预览的摘要文字，检查条目模板的标题、日期、分类与标签渲染效果。',
            categories: JSON.stringify([{ name: '示例分类', slug: 'uncategorized' }]),
            tags: JSON.stringify([{ name: '示例标签', slug: 'demo' }]),
        });
    }
    const data = { posts: sample, totalPages: 1, currentPage: 1 };
    const baseOpts = { baseUrl: '/', sidebar: null, site: site };
    if (type === 'item') {
        const pageHome = (tplAll && typeof tplAll.pageHome === 'string') ? tplAll.pageHome : '';
        return renderHomepage(data, 1, sample.length, Object.assign({ itemTpl: content, pageTpl: pageHome }, baseOpts));
    }
    if (type === 'single') {
        const row = {
            wp_id: 99999, title: '预览样例文章：中文标题 Test', slug: 'preview-sample',
            date: '2026-09-10 12:00:00', modified: '2026-09-10 12:00:00', excerpt: '预览用摘要',
            content: '<p>这是正文预览样例。包含一段文字与 <code>code</code> 标记。</p><p>再一段：看看段间距、行高和配色。</p><blockquote><p>引用样式预览。</p></blockquote><pre><code>console.log(1);</code></pre>',
            content_format: 'html',
            categories: JSON.stringify([{ name: '示例分类', slug: 'uncategorized' }]),
            tags: JSON.stringify([{ name: '示例标签', slug: 'demo' }]),
        };
        return renderSingleHtml(row, site, content);
    }
    const withItem = Object.assign({ itemTpl: actItem, pageTpl: content }, baseOpts);
    if (type === 'pageHome') return renderHomepage(data, 1, sample.length, withItem);
    if (type === 'pageCategory') return renderCategoryPage(data, 1, sample.length, Object.assign({ slug: 'demo', name: '示例分类' }, withItem));
    if (type === 'pageTag') return renderTagPage(data, 1, sample.length, Object.assign({ slug: 'demo', name: '示例标签' }, withItem));
    if (type === 'pageArchive') return renderArchivePage(data, 1, sample.length, Object.assign({ year: '2026', month: '09' }, withItem));
    return '';
}

/** 发布后清缓存：按纯 TTL 配置决定清理范围（省 KV 写） */
async function purgeLists(env, extraKeys) {
    const cfg = await getCacheConfig(env);
    // 注：原先这里有 seo:sitemap.xml / seo:robots.txt 两次 delete。
    // sitemap 已废弃（路由层 404，不再生成）、robots 已改为静态常量（不缓存）→ 无需再清。
    // 列表/侧边栏：仅在该类缓存启用（TTL>0）且 purge_on_publish=true 时清；否则靠 TTL 自然过期
    if (!cfg.purge_on_publish) return;
    if (cfg.ttl_sidebar > 0) {
        try { await deleteCache(env.KV_CACHE, 'sidebar:data'); } catch (e) {}
    }
    if (cfg.ttl_list > 0) {
        const keys = ['home:page:1'];
        for (const k of (extraKeys || [])) keys.push(k);
        for (const k of keys) { try { await deleteCache(env.KV_CACHE, k); } catch (e) {} }
    }
}

/* ---------- 通用批量任务执行器（重建单页 / permalink 迁移共用） ----------
 * 设计：job 只负责"单条做什么"，"分批取数 + 批内并发 + 进度持久化"由这里统一处理。
 * 进度存 KV `task:<job>:progress` → 刷新 / 中断后可从断点续做；跑完自动清除。
 */
function batchProgressKey(job) { return 'task:' + job + ':progress'; }

async function getBatchProgress(env, job) {
    try {
        const v = await env.KV_CACHE.get(batchProgressKey(job));
        if (!v) return null;
        const o = JSON.parse(v);
        return (o && typeof o === 'object') ? o : null;
    } catch (e) { return null; }
}

/** 批内并发跑一遍：handler(item) 返回 truthy 记为成功（并发池，最多 concurrency 条同时在跑） */
async function runBatchChunk(items, concurrency, handler) {
    let idx = 0;
    let ok = 0;
    let failed = 0;
    const workers =Math.max(1, Math.min(concurrency || 1, items.length || 1));
    const pool = [];
    for (let i = 0; i < workers; i++) {
        pool.push((async function () {
            while (idx < items.length) {
                const my = items[idx++];
                try {
                    if (await handler(my)) ok++; else failed++;
                } catch (e) { failed++; }
            }
        })());
    }
    await Promise.all(pool);
    return { ok: ok, failed: failed };
}

/* ---------- permalink（URL 形态） → storagePattern ---------- */
const PERMALINK_MAP = {
    postname: 'slug', year: 'year', monthnum: 'month', day: 'day',
    hour: 'hour', minute: 'minute', second: 'second', post_id: 'id', category: 'category',
};
const PATTERN_BACK = { slug: 'postname', year: 'year', month: 'monthnum', day: 'day', id: 'post_id', category: 'category' };

/** '/%year%/%postname%/' → '{year}/{slug}/index.html'；同时返回不支持的记号 */
function permalinkToPattern(structure) {
    let out = String(structure == null ? '' : structure).trim();
    out = out.replace(/^https?:\/\/[^/]+/i, '');   // 容错：整条 URL 粘进来
    Object.keys(PERMALINK_MAP).forEach(function (k) {
        out = out.split('%' + k + '%').join('{' + PERMALINK_MAP[k] + '}');
    });
    const unsupported = out.match(/%[A-Za-z_]+%/g) || [];
    out = out.split('/').filter(Boolean).join('/');
    if (out && !/index\.html$/.test(out)) out += '/index.html';
    return { pattern: out, unsupported: unsupported };
}

/** storagePattern → WP 风格结构（仅用于后台回显） */
function patternToPermalink(pattern) {
    let p = String(pattern || '').replace(/\/?index\.html$/, '');
    Object.keys(PATTERN_BACK).forEach(function (k) {
        p = p.split('{' + k + '}').join('%' + PATTERN_BACK[k] + '%');
    });
    return '/' + p.split('/').filter(Boolean).join('/') + '/';
}

/** 从绝对 URL 取路径部分（预览用） */
function urlPathOf(u) {
    try { return new URL(String(u)).pathname; } catch (e) { return String(u || ''); }
}

/** 写站点 storagePattern（保留 site 行其它字段）+ 清 site:ctx */
async function setStoragePattern(env, db, newPattern, siteCtx) {
    const srow = await db.prepare("SELECT v FROM settings WHERE k = 'site'").first();
    let sObj = {};
    try { sObj = JSON.parse((srow && srow.v) || '{}'); } catch (e) {}
    sObj.storagePattern = newPattern;
    if (!sObj.name) sObj.name = (siteCtx && siteCtx.name) || '';
    if (!sObj.url) sObj.url = (siteCtx && siteCtx.url) || '';
    await db.prepare("INSERT INTO settings (k, v) VALUES ('site', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v").bind(JSON.stringify(sObj)).run();
    try { await deleteCache(env.KV_CACHE, 'site:ctx'); } catch (e) {}
}

/* ---------- 单页 A3：用单页壳模板把文章渲染为静态 HTML 存 R2 ---------- */
function escHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/** 按站点 storagePattern（settings）解析文章 R2 存储路径 */
function postStoragePath(row, pattern) {
    const d = String(row.date || '');
    // {category}：取首个分类的 slug（posts.categories 是 JSON [{name,slug}]）
    let catSlug = '';
    try {
        const cats = JSON.parse(row.categories || '[]');
        if (Array.isArray(cats) && cats.length) {
            const c0 = cats[0];
            catSlug = String((c0 && (c0.slug || c0.name)) || '');
        }
    } catch (e) { /* 忽略解析失败 */ }
    const vals = {
        slug: String(row.slug || ''), id: String(row.wp_id || ''),
        year: d.slice(0, 4), month: d.slice(5, 7), day: d.slice(8, 10),
        hour: d.slice(11, 13), minute: d.slice(14, 16), second: d.slice(17, 19),
        category: catSlug,
    };
    let p = pattern || '{slug}/index.html';
    Object.keys(vals).forEach(function (k) { p = p.split('{' + k + '}').join(vals[k]); });
    return p;
}

function postPublicUrl(row, site) {
    const p = postStoragePath(row, site.storagePattern).replace(/\/?index\.html$/, '');
    return (site.url || '') + '/' + p.split('/').filter(Boolean).join('/') + '/';
}

/** 正文含代码块时给单页注入 highlight.js 资源（/hl/ 由 Worker assets 直出） */
function injectCodeAssets(html, content) {
    if (!html || !/<(?:pre|code)\b/i.test(String(content || ''))) return html;
    const css = '<link rel="stylesheet" href="/hl/github.min.css">';
    const js = '<script src="/hl/highlight.min.js"><\/script><script>document.addEventListener("DOMContentLoaded",function(){try{hljs.highlightAll()}catch(e){}});<\/script>';
    let h = html;
    if (/<\/head>/i.test(h)) { h = h.replace(/<\/head>/i, css + '</head>'); }
    else { h = css + h; }
    if (/<\/body>/i.test(h)) { h = h.replace(/<\/body>/i, js + '</body>'); }
    else { h += js; }
    return h;
}

/** 条件块处理：{{#IF_FLAG}}...{{/IF_FLAG}}，cond=false 时整块删除（与 templates.js cfseIfBlock 同语义） */
function cfseIfBlock(tpl, flag, cond) {
    const open = '{{#IF_' + flag + '}}';
    const close = '{{/IF_' + flag + '}}';
    while (tpl.includes(open)) {
        const s = tpl.indexOf(open);
        const e = tpl.indexOf(close, s);
        if (e < 0) break;
        tpl = tpl.slice(0, s) + (cond ? tpl.slice(s + open.length, e) : '') + tpl.slice(e + close.length);
    }
    return tpl;
}

function renderSingleHtml(row, site, tplOverride) {
    let h = tplOverride || CFSE_SINGLE_TPL || '';
    if (!h) return '';
    const rep = function (t, v) { h = h.split(t).join(String(v == null ? '' : v)); };
    rep('{{SITE_URL}}', site.url || '');
    rep('{{SITE_NAME}}', escHtml(site.name));
    rep('{{SITE_DESC}}', escHtml(site.description || ''));
    rep('{{SITE_FOOTER}}', site.footerHtml || '');
    rep('{{POST_TITLE}}', escHtml(row.title));
    rep('{{POST_DATE}}', escHtml(String(row.date || '').slice(0, 10)));
    rep('{{POST_EXCERPT}}', escHtml(row.excerpt || ''));
    const bodyHtml = (row.content_format || 'html') === 'md' ? mdToHtml(row.content) : String(row.content || '');
    rep('{{POST_CONTENT}}', bodyHtml);
    rep('{{POST_URL}}', postPublicUrl(row, site));
    // 分类/标签区（与列表条目模板同形态；无则整块隐藏）
    const cats = parseTaxoList(row.categories);
    const tags = parseTaxoList(row.tags);
    h = cfseIfBlock(h, 'CATEGORIES', cats.length > 0);
    h = cfseIfBlock(h, 'TAGS', tags.length > 0);
    const baseUrl = site.url || '';
    rep('{{POST_CATEGORIES}}', cats.map(function (c) { return '<a href="' + baseUrl + '/category/' + (c.slug || '') + '/">' + escHtml(c.name) + '</a>'; }).join(', '));
    rep('{{POST_TAGS}}', tags.map(function (t) { return '<a href="' + baseUrl + '/tag/' + (t.slug || '') + '/">' + escHtml(t.name) + '</a>'; }).join(', '));
    return injectCodeAssets(h, bodyHtml);
}

/** 发布（published）文章 → 渲染单页写 R2（单页直读 R2，无 KV 缓存层） */
async function writeSinglePage(env, row) {
    if (!row || row.status !== 'published') return false;
    const site = await getSiteContext(env);
    const path = postStoragePath(row, site.storagePattern);
    const html = renderSingleHtml(row, site, await getActiveTplContent(env, 'single'));
    if (!html) return false;
    try {
        await env.R2_BUCKET.put(path, html, { httpMetadata: { contentType: 'text/html; charset=utf-8' } });
    } catch (e) { console.error('writeSinglePage put failed:', e); return false; }
    return true;
}


/**
 * 按需渲染单页（R2 未命中时由路由调用）：
 *   D1 取该篇（status='published'）→ 用当前风格模板渲染（md 走 marked）→ 写回 R2 → 返回 HTML。
 * 用途：让「WP 只同步 D1、不上传 R2」的站点也能自愈（不需要每次改模板就全量重建单页才能访问）。
 * 返回 { html, path } 或 null（该 slug 不存在 / 非已发布 / 渲染失败）。
 */
export async function renderSinglePageOnDemand(env, slug) {
    const key = String(slug || '').toLowerCase();
    if (!key) return null;
    const db = env.D1_DATABASE;
    let row = await db.prepare("SELECT * FROM posts WHERE slug = ? AND status = 'published' LIMIT 1").bind(key).first();
    if (!row) {
        // 兜底：大小写不敏感匹配（历史数据里可能有带大写的 slug）
        row = await db.prepare("SELECT * FROM posts WHERE lower(slug) = ? AND status = 'published' LIMIT 1").bind(key).first();
    }
    const diag = { slug: key, step: 'lookup', found: 0 };
    if (!row) {
        // 再查一次不带 status 条件，看看是不是状态不符
        const any = await db.prepare("SELECT slug, status FROM posts WHERE lower(slug) = ? LIMIT 3").bind(key).all();
        diag.step = 'not-found';
        diag.candidates = JSON.stringify((any && any.results) || []);
        console.error('[ondemand] miss: ' + JSON.stringify(diag));
        return { html: null, path: null, diag: diag };
    }
    diag.found = 1;
    diag.status = row.status;
    diag.fmt = row.content_format || 'html';
    const site = await getSiteContext(env);
    let html = '';
    try {
        html = renderSingleHtml(row, site, await getActiveTplContent(env, 'single'));
    } catch (e) {
        diag.step = 'render-error';
        diag.err = String((e && e.message) || e);
        console.error('[ondemand] render failed: ' + JSON.stringify(diag));
        return { html: null, path: null, diag: diag };
    }
    diag.htmlLen = html ? html.length : 0;
    if (!html) {
        diag.step = 'empty-html';
        console.error('[ondemand] empty: ' + JSON.stringify(diag));
        return { html: null, path: null, diag: diag };
    }
    const path = postStoragePath(row, site.storagePattern);
    diag.path = path;
    try {
        await env.R2_BUCKET.put(path, html, { httpMetadata: { contentType: 'text/html; charset=utf-8' } });
    } catch (e) {
        console.error('renderSinglePageOnDemand put failed:', e);   // 写回失败也照常返回给用户
    }
    diag.step = 'ok';
    console.log('[ondemand] rendered ' + path + ' (slug=' + row.slug + ', fmt=' + (row.content_format || 'html') + ')');
    return { html: html, path: path, diag: diag };
}

/** 删除文章静态单页（R2） */
async function deleteSinglePage(env, row) {
    if (!row) return;
    const site = await getSiteContext(env);
    const path = postStoragePath(row, site.storagePattern);
    try { await env.R2_BUCKET.delete(path); } catch (e) {}
    try { await env.R2_BUCKET.delete(path + '.gz'); } catch (e) {}
}

/** 规范化并 upsert 分类/标签，返回 [{name,slug}]（同时写 post_taxonomy） */
async function syncTaxonomy(db, postId, type, terms) {
    const out = [];
    for (const raw of (terms || [])) {
        if (!raw) continue;
        const name = typeof raw === 'string' ? raw : String(raw.name || '');
        if (!name.trim()) continue;
        let slug = (raw && raw.slug) ? String(raw.slug) : slugify(name);
        if (!slug) slug = 'c' + Date.now().toString(36) + Math.floor(Math.random() * 1000);
        await db.prepare('INSERT INTO taxonomies (type, slug, name) VALUES (?, ?, ?) ON CONFLICT(type, slug) DO UPDATE SET name = excluded.name')
            .bind(type, slug, name.trim()).run();
        await db.prepare('INSERT OR IGNORE INTO post_taxonomy (post_id, taxonomy_type, taxonomy_slug) VALUES (?, ?, ?)')
            .bind(postId, type, slug).run();
        out.push({ name: name.trim(), slug: slug });
    }
    return out;
}

function buildPostPayload(body, offsetSec) {
    const title = String(body.title || '').trim();
    if (!title) return { error: '标题不能为空' };
    const fmt = body.content_format === 'md' ? 'md' : 'html';
    // md 原稿原样保存（渲染层再转）；html 入库前净化
    const content = fmt === 'md' ? String(body.content || '') : scrubHtml(String(body.content || ''));
    const slug = (body.slug ? String(body.slug) : slugify(title) || ('p' + Date.now().toString(36))).toLowerCase();
    const status = body.status === 'draft' ? 'draft' : 'published';
    return {
        slug: slug,
        title: title,
        content: content,
        content_format: fmt,
        excerpt: (body.excerpt !== undefined && String(body.excerpt).trim() !== '') ? String(body.excerpt).trim()
            : (fmt === 'md' ? mdToText(content, 200) : excerptOf(content)),
        status: status,
        featured_image: body.featured_image ? String(body.featured_image) : '',
        categories: body.categories,
        tags: body.tags,
        date: body.date || localDateTime(offsetSec),
        modified: localDateTime(offsetSec),
    };
}

/** 主入口：request.path 以 /api/ 开头时由 routes 调用 */
export async function handleAdminRequest(request, env) {
    const url = new URL(request.url);
    const path = url.pathname;
    const method = request.method;
    const secret = env.AUTH_KEY_SECRET || 'cfse-dev-secret';

    // ---- 登录 ----
    if (path === '/api/login' && method === 'POST') {
        const ip = request.headers.get('CF-Connecting-IP') || 'unknown';
        if (await loginRateLimit(env, ip)) return json({ error: '尝试过于频繁，请稍后再试' }, 429);
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        // 账号级失败锁定：连续 10 次错误锁 15 分钟（与 IP 限速叠加）
        const uName = String(body.username || '').trim();
        const uKey = 'login:u:' + uName;
        let uLock = '0';
        try { uLock = (await env.KV_CACHE.get(uKey)) || '0'; } catch (e) {}
        if (parseInt(uLock, 10) >= 10) return json({ error: '该账号已临时锁定，请 15 分钟后再试' }, 429);
        const user = await authenticate(env.D1_DATABASE, body.username, body.password);
        if (!user) {
            const n = parseInt(uLock, 10) + 1;
            try { await env.KV_CACHE.put(uKey, String(n), { expirationTtl: 900 }); } catch (e) {}
            return json({ error: n >= 10 ? '该账号已临时锁定，请 15 分钟后再试' : '用户名或密码错误' }, 401);
        }
        try { await env.KV_CACHE.delete(uKey); } catch (e) {}
        const access = await signJwt({ sub: user.id, username: user.username, role: user.role, typ: 'access' }, secret, ACCESS_TTL);
        const refresh = await signJwt({ sub: user.id, typ: 'refresh' }, secret, REFRESH_TTL);
        return json({ access, refresh, user: { id: user.id, username: user.username, role: user.role, displayName: user.displayName, email: user.email } });
    }

    // ---- 刷新 ----
    if (path === '/api/refresh' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const payload = await verifyJwt(body.refresh, secret, 'refresh');
        if (!payload) return json({ error: 'refresh 无效或已过期' }, 401);
        const row = await env.D1_DATABASE.prepare('SELECT * FROM users WHERE id = ?').bind(payload.sub).first();
        if (!row || parseInt(row.user_status, 10) !== 1) return json({ error: '账号不可用' }, 401);
        const access = await signJwt({ sub: row.id, username: row.user_login, role: row.role, typ: 'access' }, secret, ACCESS_TTL);
        return json({ access, user: { id: row.id, username: row.user_login, role: row.role, displayName: row.display_name, email: row.user_email } });
    }

    // ---- 其余需 access 鉴权 ----
    const token = bearerToken(request);
    const authPayload = token ? await verifyJwt(token, secret, 'access') : null;
    if (!authPayload) return json({ error: '未授权或登录已过期' }, 401);

    // ---- 角色权限门禁 ----
    // admin：全部；editor/author：内容管理；subscriber：仅个人资料
    const role = authPayload.role || 'subscriber';
    const isWrite = request.method === 'POST' || request.method === 'PUT' || request.method === 'DELETE';
    // 应用密码允许「本人」管理自己的（下面端点内部再做归属校验）
    const isAppPwRoute = /^\/api\/users\/(\d+)\/app-passwords$/.test(path) || /^\/api\/app-passwords\/(\d+)$/.test(path);
    const adminOnly = (path.startsWith('/api/users') && !isAppPwRoute)
        || (isWrite && (path === '/api/settings' || path.startsWith('/api/import')
            || path === '/api/cache/purge' || path.startsWith('/api/posts/rebuild-single')
            || path.startsWith('/api/posts/batch')));
    if (adminOnly && role !== 'admin') return json({ error: '无权限' }, 403);
    if (role === 'subscriber' && path !== '/api/me' && path !== '/api/me/password') {
        return json({ error: '无权限' }, 403);
    }

    // ---- 写操作限速（每用户 90 次/分钟，防脚本风暴；内存滑窗，0 KV 成本） ----
    if (role !== 'subscriber' && (request.method === 'POST' || request.method === 'PUT' || request.method === 'DELETE')) {
        if (!wrateAllowed(authPayload.sub)) return json({ error: '操作过于频繁，请稍后再试' }, 429);
    }

    const db = env.D1_DATABASE;

    // ---- 应用密码（Application Passwords；第三方 md 编辑器认证用）----
    const apm = path.match(/^\/api\/users\/(\d+)\/app-passwords$/);
    if (apm) {
        const uidApp = parseInt(apm[1], 10);
        if (authPayload.role !== 'admin' && authPayload.sub !== uidApp) return json({ error: '无权限' }, 403);
        await ensureAppPasswordsTable(env);
        if (method === 'GET') {
            const rows = await db.prepare('SELECT id, user_id, name, created_at, last_used_at, revoked FROM app_passwords WHERE user_id = ? ORDER BY id DESC').bind(uidApp).all();
            return json({ items: rows.results || [] });
        }
        if (method === 'POST') {
            let body = {};
            try { body = await request.json(); } catch (e) { body = {}; }
            const apName = String(body.name || '').trim() || '未命名';
            const plain = genAppPasswordPlain();
            const hash = await hashAppPassword(plain);
            await db.prepare('INSERT INTO app_passwords (user_id, name, pass_hash, created_at, last_used_at, revoked) VALUES (?, ?, ?, ?, ?, 0)')
                .bind(uidApp, apName, hash, nowDateTime(), '').run();
            const last = await db.prepare('SELECT id FROM app_passwords WHERE user_id = ? ORDER BY id DESC LIMIT 1').bind(uidApp).first();
            return json({ ok: true, id: last ? last.id : 0, name: apName, password: plain, warning: '明文只显示这一次，请立即保存' });
        }
    }
    const apd = path.match(/^\/api\/app-passwords\/(\d+)$/);
    if (apd && method === 'DELETE') {
        const aid = parseInt(apd[1], 10);
        const apRow = await db.prepare('SELECT * FROM app_passwords WHERE id = ?').bind(aid).first();
        if (!apRow) return json({ error: 'not found' }, 404);
        if (authPayload.role !== 'admin' && authPayload.sub !== apRow.user_id) return json({ error: '无权限' }, 403);
        await db.prepare('UPDATE app_passwords SET revoked = 1 WHERE id = ?').bind(aid).run();
        return json({ ok: true });
    }

    // GET /api/me
    if (path === '/api/me' && method === 'GET') {
        const row = await db.prepare('SELECT id, user_login, user_email, display_name, role FROM users WHERE id = ?').bind(authPayload.sub).first();
        return json(row ? { id: row.id, username: row.user_login, email: row.user_email, displayName: row.display_name, role: row.role } : { error: 'not found' }, row ? 200 : 404);
    }

    // PUT /api/me（更新昵称 / 邮箱）
    if (path === '/api/me' && method === 'PUT') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const displayName = String(body.display_name || '').trim();
        const email = String(body.email || '').trim();
        if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return json({ error: '邮箱格式不正确' }, 400);
        if (!displayName) return json({ error: '昵称不能为空' }, 400);
        await db.prepare('UPDATE users SET display_name = ?, user_email = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            .bind(displayName, email, authPayload.sub).run();
        return json({ ok: true });
    }

    // POST /api/me/password（修改自己的密码）
    if (path === '/api/me/password' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const row = await db.prepare('SELECT * FROM users WHERE id = ?').bind(authPayload.sub).first();
        if (!row) return json({ error: '用户不存在' }, 404);
        const oldOk = await verifyPassword(body.old_password, row.user_pass);
        if (!oldOk) return json({ error: '原密码错误' }, 401);
        const np = String(body.new_password || '');
        if (np.length < 8) return json({ error: '新密码至少 8 位' }, 400);
        const store = await hashPassword(np);
        await db.prepare('UPDATE users SET user_pass = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?').bind(store, authPayload.sub).run();
        return json({ ok: true, message: '密码已修改，请重新登录' });
    }

    // GET /api/settings（站点信息 + 缓存策略）
    if (path === '/api/settings' && method === 'GET') {
        const site = await getSiteContext(env);
        return json({
            site: { name: site.name, url: site.url, description: site.description || '' },
            cache: site.cache,
        });
    }

    // PUT /api/settings（仅 admin；支持站点名/描述 与/或 缓存策略 → settings 落库 + 清缓存即时生效）
    if (path === '/api/settings' && method === 'PUT') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const hasSite = body.site && typeof body.site === 'object';
        const hasCache = body.cache && typeof body.cache === 'object';
        if (!hasSite && !hasCache) return json({ error: '没有要更新的设置' }, 400);
        const site = await getSiteContext(env);
        const result = {};
        if (hasSite) {
            const newName = String(body.site.name || '').trim();
            if (!newName) return json({ error: '站点名不能为空' }, 400);
            const row = await db.prepare("SELECT v FROM settings WHERE k = 'site'").first();
            let prev = {};
            try { prev = JSON.parse((row && row.v) || '{}'); } catch (e) {}
            const newDesc = String(body.site.description || '').trim();
            const payload = JSON.stringify({
                name: newName,
                description: newDesc,
                url: prev.url || site.url || '',
                storagePattern: prev.storagePattern || site.storagePattern || '{slug}/index.html',
                timezoneOffset: prev.timezoneOffset || 0,
            });
            await db.prepare("INSERT INTO settings (k, v) VALUES ('site', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v").bind(payload).run();
            result.site = { name: newName, description: newDesc };
        }
        if (hasCache) {
            const c = body.cache;
            const ttlNum = function (v, dflt) {
                const n = parseInt(v, 10);
                if (isNaN(n) || n < 0) return dflt;
                return Math.min(n, 31536000); // 上限 1 年
            };
            const cacheCfg = {
                ttl_list: ttlNum(c.ttl_list, 0),
                ttl_sidebar: ttlNum(c.ttl_sidebar, 0),
                ttl_site: ttlNum(c.ttl_site, 604800),
                ttl_feed: ttlNum(c.ttl_feed, 14400),
                feed_count: (function () { const n = parseInt(c.feed_count, 10); return (!isNaN(n) && n >= 1) ? Math.min(n, 100) : 20; })(),
                purge_on_publish: !!c.purge_on_publish,
                // 未传则保留原值（避免"只改缓存策略"的旧请求把批量任务配置重置）
                task_batch: (function () {
                    const n = parseInt(c.task_batch, 10);
                    if (!isNaN(n) && n >= 1) return Math.min(n, 100);
                    const pv = parseInt((site.cache || {}).task_batch, 10);
                    return (!isNaN(pv) && pv >= 1) ? Math.min(pv, 100) : 20;
                })(),
                task_concurrency: (function () {
                    const n = parseInt(c.task_concurrency, 10);
                    if (!isNaN(n) && n >= 1) return Math.min(n, 16);
                    const pv = parseInt((site.cache || {}).task_concurrency, 10);
                    return (!isNaN(pv) && pv >= 1) ? Math.min(pv, 16) : 4;
                })(),
            };
            await db.prepare("INSERT INTO settings (k, v) VALUES ('cache', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v").bind(JSON.stringify(cacheCfg)).run();
            result.cache = cacheCfg;
        }
        // 配置与站点信息打包在同一个 KV 值 site:ctx → 清一次即全面生效；旧的独立配置键顺手清理
        try { await deleteCache(env.KV_CACHE, 'site:ctx'); } catch (e) {}
        try { await deleteCache(env.KV_CACHE, 'cache:config'); } catch (e) {}
        // 站名/描述/缓存策略变化影响 RSS
        try { await purgeCache(env.KV_CACHE, 'rss:'); } catch (e) {}
        // 旧的列表/侧边栏缓存精确失效（首屏；其余分页靠 TTL 过期），并按新配置清 seo
        try { await deleteCache(env.KV_CACHE, 'home:page:1'); } catch (e) {}
        try { await deleteCache(env.KV_CACHE, 'sidebar:data'); } catch (e) {}
        await purgeLists(env);
        return json(Object.assign({ ok: true }, result));
    }

    // ---- 文章 ----
    if (path === '/api/posts' && method === 'GET') {
        const page = Math.max(1, parseInt(url.searchParams.get('page') || '1', 10) || 1);
        const per = Math.min(100, Math.max(1, parseInt(url.searchParams.get('per') || '20', 10) || 20));
        const q = url.searchParams.get('q');
        const status = url.searchParams.get('status');
        const taxType = url.searchParams.get('type');
        const taxSlug = url.searchParams.get('tax');
        const sortCol = url.searchParams.get('sort') === 'title' ? 'title COLLATE NOCASE' : 'date';
        const sortDir = url.searchParams.get('dir') === 'asc' ? 'ASC' : 'DESC';
        const offset = (page - 1) * per;
        const conds = [];
        const like = [];
        if (q) { conds.push('(title LIKE ? OR content LIKE ?)'); like.push('%' + q + '%', '%' + q + '%'); }
        if (status === 'published' || status === 'draft') { conds.push('status = ?'); like.push(status); }
        if ((taxType === 'category' || taxType === 'tag') && taxSlug) {
            conds.push("wp_id IN (SELECT post_id FROM post_taxonomy WHERE taxonomy_type = ? AND taxonomy_slug = ?)");
            like.push(taxType, decodeURIComponent(taxSlug));
        }
        const where = conds.length ? (' WHERE ' + conds.join(' AND ')) : '';
        const rows = await db.prepare('SELECT wp_id, slug, title, excerpt, date, modified, status, categories, tags, featured_image FROM posts' + where + ' ORDER BY ' + sortCol + ' ' + sortDir + ' LIMIT ? OFFSET ?').bind(...like, per, offset).all();
        const cnt = await db.prepare('SELECT COUNT(*) AS c FROM posts' + where).bind(...like).first();
        return json({ posts: rows.results || [], total: parseInt((cnt && cnt.c) || 0, 10), page, pages: Math.ceil(parseInt((cnt && cnt.c) || 0, 10) / per) });
    }

    // POST /api/posts/{id}/duplicate（复制为草稿）
    let sd = path.match(/^\/api\/posts\/(\d+)\/duplicate$/);
    if (sd && method === 'POST') {
        const id = parseInt(sd[1], 10);
        const row = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(id).first();
        if (!row) return json({ error: 'not found' }, 404);
        const siteCtx = await getSiteContext(env);
        const off = siteCtx.timezoneOffset || 0;
        let slug = String(row.slug) + '-2';
        for (let tries = 1; tries <= 20; tries++) {
            const hit = await db.prepare('SELECT wp_id FROM posts WHERE slug = ?').bind(slug).first();
            if (!hit) break;
            slug = String(row.slug) + '-' + (tries + 1);
        }
        const wpId = Date.now() * 100 + Math.floor(Math.random() * 100);
        const cats = withDefaultCategory(parseTaxoList(row.categories));
        const tags = parseTaxoList(row.tags);
        await db.prepare('INSERT INTO posts (wp_id, slug, title, content, content_format, excerpt, date, modified, status, categories, tags, featured_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            .bind(wpId, slug, String(row.title) + '（副本）', String(row.content || ''), (row.content_format || 'html'), String(row.excerpt || ''), localDateTime(off), localDateTime(off), 'draft', JSON.stringify(cats), JSON.stringify(tags), String(row.featured_image || '')).run();
        await syncTaxonomy(db, wpId, 'category', cats);
        await syncTaxonomy(db, wpId, 'tag', tags);
        return json({ ok: true, id: wpId, slug: slug, status: 'draft' });
    }

    // POST /api/posts/{id}/rebuild（单篇重建静态单页）
    let sr = path.match(/^\/api\/posts\/(\d+)\/rebuild$/);
    if (sr && method === 'POST') {
        const id = parseInt(sr[1], 10);
        const row = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(id).first();
        if (!row) return json({ error: 'not found' }, 404);
        if (row.status === 'published') {
            await writeSinglePage(env, row);
            await purgeLists(env);
        }
        return json({ ok: true, rebuilt: row.status === 'published' ? 1 : 0 });
    }

    // GET /api/posts/{id}
    let m = path.match(/^\/api\/posts\/(\d+)$/);
    if (m && method === 'GET') {
        const row = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(parseInt(m[1], 10)).first();
        if (!row) return json({ error: 'not found' }, 404);
        // 邻篇（按 date desc 管理列表顺序）
        const all = await db.prepare('SELECT wp_id, slug, title FROM posts ORDER BY date DESC, wp_id DESC').all();
        const list = all.results || [];
        const ix = list.findIndex(function (x) { return x.wp_id === row.wp_id; });
        const prev = ix > 0 ? list[ix - 1] : null;
        const next = (ix >= 0 && ix < list.length - 1) ? list[ix + 1] : null;
        return json(Object.assign({}, row, { prev: prev, next: next }));
    }

    // POST /api/posts（新建）
    if (path === '/api/posts' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const siteCtx = await getSiteContext(env);
        const p = buildPostPayload(body, siteCtx.timezoneOffset || 0);
        if (p.error) return json({ error: p.error }, 400);
        const dt = localDateTime(siteCtx.timezoneOffset || 0);
        // slug 冲突时自动追加 -2/-3…（WP 风格），最多尝试 20 次
        let finalSlug = p.slug;
        for (let tries = 1; tries <= 20; tries++) {
            const hit = await db.prepare('SELECT wp_id FROM posts WHERE slug = ?').bind(finalSlug).first();
            if (!hit) break;
            finalSlug = p.slug + '-' + (tries + 1);
        }
        const wpId = Date.now() * 100 + Math.floor(Math.random() * 100); // 无自增可用唯一 id（时间戳毫秒）
        const catJson = JSON.stringify(withDefaultCategory(p.categories));
        const tagJson = JSON.stringify(p.tags || []);
        await db.prepare("INSERT INTO posts (wp_id, slug, title, content, content_format, excerpt, date, modified, status, categories, tags, featured_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            .bind(wpId, finalSlug, p.title, p.content, p.content_format, p.excerpt, p.date, dt, p.status, catJson, tagJson, p.featured_image).run();
        await db.prepare('DELETE FROM post_taxonomy WHERE post_id = ?').bind(wpId).run();
        const cats = await syncTaxonomy(db, wpId, 'category', withDefaultCategory(p.categories));
        const tags = await syncTaxonomy(db, wpId, 'tag', p.tags);
        if (cats.length || tags.length) {
            await db.prepare('UPDATE posts SET categories = ?, tags = ? WHERE wp_id = ?')
                .bind(JSON.stringify(cats), JSON.stringify(tags), wpId).run();
        }
        if (p.status === 'published') {
            const row2 = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(wpId).first();
            if (row2) await writeSinglePage(env, row2);
            await purgeLists(env);
        }
        return json({ ok: true, id: wpId, slug: finalSlug, date: p.date, single: p.status === 'published' ? 1 : 0 });
    }

    // PUT /api/posts/{id}（更新；正文为纯净 HTML）
    if (m && method === 'PUT') {
        const id = parseInt(m[1], 10);
        const row = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(id).first();
        if (!row) return json({ error: 'not found' }, 404);
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const siteCtx = await getSiteContext(env);
        const p = buildPostPayload(Object.assign({}, row, body), siteCtx.timezoneOffset || 0);
        if (p.error) return json({ error: p.error }, 400);
        // 改 slug 时的唯一性检查（其它文章已占用 → 409）
        if (p.slug !== row.slug) {
            const clash = await db.prepare('SELECT wp_id FROM posts WHERE slug = ? AND wp_id != ?').bind(p.slug, id).first();
            if (clash) return json({ error: 'slug 已被其它文章占用：' + p.slug }, 409);
        }
        await db.prepare('DELETE FROM post_taxonomy WHERE post_id = ?').bind(id).run();
        const catsIn = withDefaultCategory(body.categories !== undefined ? body.categories : parseTaxoList(row.categories));
        const tagsIn = body.tags !== undefined ? body.tags : parseTaxoList(row.tags);
        const cats = await syncTaxonomy(db, id, 'category', catsIn);
        const tags = await syncTaxonomy(db, id, 'tag', tagsIn);
        await db.prepare('UPDATE posts SET slug = ?, title = ?, content = ?, content_format = ?, excerpt = ?, modified = ?, status = ?, categories = ?, tags = ?, featured_image = ? WHERE wp_id = ?')
            .bind(p.slug, p.title, p.content, p.content_format, p.excerpt, localDateTime(siteCtx.timezoneOffset || 0), p.status, JSON.stringify(cats), JSON.stringify(tags), p.featured_image, id).run();
        // 单页同步：slug 变化先删旧；published 重建/生成单页；转草稿删除单页
        const newRow = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(id).first();
        if (row && row.status === 'published' && row.slug !== p.slug) await deleteSinglePage(env, row);
        if (p.status === 'published' && newRow) await writeSinglePage(env, newRow);
        if (p.status === 'draft') await deleteSinglePage(env, newRow || row);
        await purgeLists(env);
        return json({ ok: true, id: id, slug: p.slug, single: p.status === 'published' ? 1 : 0 });
    }

    // DELETE /api/posts/{id}
    if (m && method === 'DELETE') {
        const id = parseInt(m[1], 10);
        const row = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(id).first();
        await db.prepare('DELETE FROM post_taxonomy WHERE post_id = ?').bind(id).run();
        await db.prepare('DELETE FROM posts WHERE wp_id = ?').bind(id).run();
        await db.prepare('DELETE FROM taxonomies WHERE slug NOT IN (SELECT DISTINCT taxonomy_slug FROM post_taxonomy)').run();
        if (row) await deleteSinglePage(env, row);
        await purgeLists(env);
        return json({ ok: true });
    }

    // POST /api/posts/batch（批量：delete / draft / publish）
    if (path === '/api/posts/batch' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const ids = Array.isArray(body.ids) ? body.ids.map(Number).filter(function (n) { return n > 0; }) : [];
        if (!ids.length) return json({ error: '没有选中文章' }, 400);
        const action = body.action;
        if (action !== 'delete' && action !== 'draft' && action !== 'publish' && action !== 'setcats') return json({ error: 'action 无效' }, 400);
        const siteCtx = await getSiteContext(env);
        const off = siteCtx.timezoneOffset || 0;
        let done = 0;
        if (action === 'delete') {
            for (const id of ids) {
                const row = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(id).first();
                if (!row) continue;
                await db.prepare('DELETE FROM post_taxonomy WHERE post_id = ?').bind(id).run();
                await db.prepare('DELETE FROM posts WHERE wp_id = ?').bind(id).run();
                await deleteSinglePage(env, row);
                done++;
            }
        } else if (action === 'setcats') {
            // body.categories 可为 [{name}] 数组或字符串/JSON；数组直通（parseTaxoList 只吃 JSON 字符串）
            const catNames = Array.isArray(body.categories) ? body.categories : parseTaxoList(body.categories || []);
            if (!catNames.length) return json({ error: '没有提供分类' }, 400);
            const ph = ids.map(function () { return '?'; }).join(',');
            const rows = await db.prepare('SELECT * FROM posts WHERE wp_id IN (' + ph + ')').bind(...ids).all();
            for (const row of (rows.results || [])) {
                // 只替换分类：清旧 category 关联后重建
                await db.prepare('DELETE FROM post_taxonomy WHERE post_id = ? AND taxonomy_type = \'category\'').bind(row.wp_id).run();
                const cats = await syncTaxonomy(db, row.wp_id, 'category', catNames);
                await db.prepare('UPDATE posts SET categories = ?, modified = ? WHERE wp_id = ?').bind(JSON.stringify(cats), localDateTime(off), row.wp_id).run();
                done++;
            }
        } else {
            const ph = ids.map(function () { return '?'; }).join(',');
            const rows = await db.prepare('SELECT * FROM posts WHERE wp_id IN (' + ph + ')').bind(...ids).all();
            for (const row of (rows.results || [])) {
                const want = action === 'publish' ? 'published' : action; // 库内状态值为 published/draft
                if (row.status === want) { done++; continue; }
                await db.prepare('UPDATE posts SET status = ?, modified = ? WHERE wp_id = ?').bind(want, localDateTime(off), row.wp_id).run();
                if (action === 'publish') {
                    const nr = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(row.wp_id).first();
                    if (nr) await writeSinglePage(env, nr);
                } else {
                    await deleteSinglePage(env, row);
                }
                done++;
            }
        }
        if (done) await purgeLists(env);
        return json({ ok: true, done: done });
    }

    // 批量重建静态单页（admin）
    //   GET  → 查进度（用于"是否从断点继续"）；POST → 跑一批（批内并发；进度持久化，可中断续做）
    //   POST 带 ?reset=1 → 丢弃旧进度、从头开始
    //   批量大小 = settings.cache.task_batch；并发数 = settings.cache.task_concurrency
    if (path === '/api/posts/rebuild-single') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        const siteCtx = await getSiteContext(env);
        const tcfg = (siteCtx && siteCtx.cache) || {};
        const batch = Math.min(100, Math.max(1, parseInt(tcfg.task_batch, 10) || 20));
        const concurrency = Math.min(16, Math.max(1, parseInt(tcfg.task_concurrency, 10) || 4));
        const JOB = 'rebuild';

        if (method === 'GET') {
            const progress = await getBatchProgress(env, JOB);
            return json({ ok: true, progress: progress, batch: batch, concurrency: concurrency });
        }

        if (method === 'POST') {
            if (url.searchParams.get('reset') === '1') {
                try { await env.KV_CACHE.delete(batchProgressKey(JOB)); } catch (e) {}
            }
            const totalRows = await db.prepare("SELECT COUNT(*) AS c FROM posts WHERE status = 'published'").first();
            const total = parseInt((totalRows && totalRows.c) || 0, 10);
            let prog = await getBatchProgress(env, JOB);
            // 进度缺失或总篇数变了 → 重新开始
            if (!prog || parseInt(prog.total, 10) !== total) {
                prog = { cursor: 0, ok: 0, failed: 0, total: total, startedAt: new Date().toISOString() };
            }
            const cursor = Math.max(0, parseInt(prog.cursor, 10) || 0);
            if (cursor >= total) {
                try { await env.KV_CACHE.delete(batchProgressKey(JOB)); } catch (e) {}
                return json({ ok: true, rebuilt: 0, failed: 0, total: total, cursor: total, done: true, batch: batch, concurrency: concurrency });
            }
            const rows = await db.prepare("SELECT * FROM posts WHERE status = 'published' ORDER BY wp_id ASC LIMIT ? OFFSET ?").bind(batch, cursor).all();
            const list = rows.results || [];
            if (!list.length) {
                try { await env.KV_CACHE.delete(batchProgressKey(JOB)); } catch (e) {}
                return json({ ok: true, rebuilt: 0, failed: 0, total: total, cursor: total, done: true, batch: batch, concurrency: concurrency });
            }
            const res = await runBatchChunk(list, concurrency, async function (r) {
                return await writeSinglePage(env, r);
            });
            prog.cursor = cursor + list.length;
            prog.ok = (parseInt(prog.ok, 10) || 0) + res.ok;
            prog.failed = (parseInt(prog.failed, 10) || 0) + res.failed;
            prog.updatedAt = new Date().toISOString();
            const done = prog.cursor >= total;
            try {
                if (done) {
                    await env.KV_CACHE.delete(batchProgressKey(JOB));
                    await purgeLists(env);
                } else {
                    await env.KV_CACHE.put(batchProgressKey(JOB), JSON.stringify(prog));
                }
            } catch (e) { /* 进度写入失败不影响本批结果 */ }
            return json({
                ok: true, rebuilt: res.ok, failed: res.failed, total: total,
                cursor: prog.cursor, done: done, batch: batch, concurrency: concurrency,
            });
        }
    }

    // ---- permalink（URL 形态）：查询 / 预览 / 迁移 / 清理旧文件 ----
    if (path === '/api/permalink' && method === 'GET') {
        const siteP = await getSiteContext(env);
        const pat = siteP.storagePattern || '{slug}/index.html';
        return json({ ok: true, storagePattern: pat, structure: patternToPermalink(pat) });
    }

    if (path === '/api/permalink/preview' && method === 'POST') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const conv = permalinkToPattern(body.structure);
        if (!conv.pattern) return json({ error: 'URL 结构不能为空' }, 400);
        const siteP = await getSiteContext(env);
        const oldPat = siteP.storagePattern || '{slug}/index.html';
        const rows = await db.prepare("SELECT * FROM posts WHERE status = 'published' ORDER BY wp_id ASC LIMIT 3").all();
        const samples = (rows.results || []).map(function (r) {
            const siteNew = Object.assign({}, siteP, { storagePattern: conv.pattern });
            return {
                title: String(r.title || '').slice(0, 40),
                oldUrl: urlPathOf(postPublicUrl(r, siteP)),
                newUrl: urlPathOf(postPublicUrl(r, siteNew)),
                oldKey: postStoragePath(r, oldPat),
                newKey: postStoragePath(r, conv.pattern),
            };
        });
        return json({ ok: true, pattern: conv.pattern, oldPattern: oldPat, unsupported: conv.unsupported, samples: samples });
    }

    // 迁移：把已发布单页的 R2 对象按新结构逐篇 copy（旧对象保留！）；全部完成后自动切 storagePattern。
    //   设计成"先 copy 全量 → 再切 pattern"，所以中途任何一步失败都不会影响旧 URL。
    if (path === '/api/permalink/migrate') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        const siteP = await getSiteContext(env);
        const tcfg = (siteP && siteP.cache) || {};
        const batch = Math.min(100, Math.max(1, parseInt(tcfg.task_batch, 10) || 20));
        const concurrency = Math.min(16, Math.max(1, parseInt(tcfg.task_concurrency, 10) || 4));
        const JOB = 'permalink';

        if (method === 'GET') {
            const progress = await getBatchProgress(env, JOB);
            return json({ ok: true, progress: progress, batch: batch, concurrency: concurrency, currentPattern: siteP.storagePattern || '' });
        }

        if (method === 'POST') {
            let body = {};
            try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
            const conv = permalinkToPattern(body.structure);
            if (!conv.pattern) return json({ error: 'URL 结构不能为空' }, 400);
            if (url.searchParams.get('reset') === '1') {
                try { await env.KV_CACHE.delete(batchProgressKey(JOB)); } catch (e) {}
            }
            const totalRows = await db.prepare("SELECT COUNT(*) AS c FROM posts WHERE status = 'published'").first();
            const total = parseInt((totalRows && totalRows.c) || 0, 10);
            let prog = await getBatchProgress(env, JOB);
            if (!prog || parseInt(prog.total, 10) !== total || prog.pattern !== conv.pattern) {
                prog = {
                    cursor: 0, ok: 0, failed: 0, skipped: 0, total: total,
                    pattern: conv.pattern, oldPattern: siteP.storagePattern || '',
                    startedAt: new Date().toISOString(),
                };
            }
            const cursor = Math.max(0, parseInt(prog.cursor, 10) || 0);
            if (cursor >= total) {
                await setStoragePattern(env, db, conv.pattern, siteP);
                try { await env.KV_CACHE.delete(batchProgressKey(JOB)); } catch (e) {}
                await purgeLists(env);
                return json({ ok: true, copied: 0, failed: 0, skipped: 0, total: total, cursor: total, done: true, batch: batch, concurrency: concurrency });
            }
            const rows = await db.prepare("SELECT * FROM posts WHERE status = 'published' ORDER BY wp_id ASC LIMIT ? OFFSET ?").bind(batch, cursor).all();
            const list = rows.results || [];
            const oldKeys = [];
            const oldPat = prog.oldPattern || siteP.storagePattern || '{slug}/index.html';
            const res = await runBatchChunk(list, concurrency, async function (r) {
                const oldKey = postStoragePath(r, oldPat);
                const newKey = postStoragePath(r, conv.pattern);
                if (oldKey === newKey) return true;
                const obj = await env.R2_BUCKET.get(oldKey);
                if (!obj) return false;                      // 旧对象不在（未导出过）→ 记为失败，稍后人工看
                await env.R2_BUCKET.put(newKey, obj.body, { httpMetadata: obj.httpMetadata });
                oldKeys.push(oldKey);
                return true;
            });
            if (oldKeys.length) {
                try { await env.KV_CACHE.put('task:permalink:oldkeys:' + cursor, JSON.stringify(oldKeys)); } catch (e) {}
            }
            prog.skipped = list.length - res.ok - res.failed;
            prog.cursor = cursor + list.length;
            prog.ok = (parseInt(prog.ok, 10) || 0) + res.ok;
            prog.failed = (parseInt(prog.failed, 10) || 0) + res.failed;
            prog.updatedAt = new Date().toISOString();
            const done = prog.cursor >= total;
            if (done) {
                await setStoragePattern(env, db, conv.pattern, siteP);
                try { await env.KV_CACHE.delete(batchProgressKey(JOB)); } catch (e) {}
                await purgeLists(env);
            } else {
                try { await env.KV_CACHE.put(batchProgressKey(JOB), JSON.stringify(prog)); } catch (e) {}
            }
            return json({
                ok: true, copied: res.ok, failed: res.failed, skipped: prog.skipped,
                total: total, cursor: prog.cursor, done: done, batch: batch, concurrency: concurrency,
                switchedTo: done ? conv.pattern : null,
            });
        }
    }

    // 清理旧文件：删掉迁移时记录下来的旧 key（分批键 task:permalink:oldkeys:<cursor>）
    if (path === '/api/permalink/cleanup' && method === 'POST') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        let deleted = 0, batches = 0, remaining = 0;
        let cursor = undefined;
        do {
            const page = await env.KV_CACHE.list({ prefix: 'task:permalink:oldkeys:', cursor: cursor });
            for (const k of (page.keys || [])) {
                const v = await env.KV_CACHE.get(k.name);
                let arr = [];
                try { arr = JSON.parse(v || '[]'); } catch (e) {}
                for (const key of (Array.isArray(arr) ? arr : [])) {
                    try { await env.R2_BUCKET.delete(key); deleted++; } catch (e) {}
                }
                try { await env.KV_CACHE.delete(k.name); } catch (e) {}
                batches++;
            }
            cursor = page.truncated ? page.cursor : undefined;
            if (cursor) remaining++;
        } while (cursor);
        return json({ ok: true, deleted: deleted, batches: batches });
    }

    // 仅切换 URL 结构（不搬文件）：适合"改结构 → 重建全部单页"的省事路线。
    // ⚠️ 切换后到重建完成之前，所有单页地址都会 404（列表页不受影响）。
    if (path === '/api/permalink/switch' && method === 'POST') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const conv = permalinkToPattern(body.structure);
        if (!conv.pattern) return json({ error: 'URL 结构不能为空' }, 400);
        if (conv.unsupported.length) return json({ error: '含不支持的记号: ' + conv.unsupported.join(' ') }, 400);
        const siteP = await getSiteContext(env);
        const oldPat = siteP.storagePattern || '{slug}/index.html';
        if (oldPat === conv.pattern) return json({ ok: true, pattern: conv.pattern, oldPattern: oldPat, changed: false });
        await setStoragePattern(env, db, conv.pattern, siteP);
        await purgeLists(env);
        return json({ ok: true, pattern: conv.pattern, oldPattern: oldPat, changed: true });
    }

    // 孤儿单页文件：R2 里形如 <path>/index.html、但不属于「当前结构下任何文章」的对象。
    //   body {delete:false}（默认）→ 只扫描并返回列表；{delete:true} → 删除全部孤儿。
    if (path === '/api/permalink/orphans' && method === 'POST') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        let body = {};
        try { body = await request.json(); } catch (e) { body = {}; }
        const siteP = await getSiteContext(env);
        const pat = siteP.storagePattern || '{slug}/index.html';
        // 1) 当前结构下的「应有」key 集合
        const rows = await db.prepare("SELECT * FROM posts WHERE status = 'published'").all();
        const want = {};
        (rows.results || []).forEach(function (r) { want[postStoragePath(r, pat)] = true; });
        // 2) 扫描 R2 全部对象（分页），挑出「单页形状」且不在应有集合里的
        const found = [];
        let cursor = undefined;
        do {
            const page = await env.R2_BUCKET.list({ limit: 1000, cursor: cursor });
            (page.objects || []).forEach(function (o) {
                const k = String(o.key || '');
                if (!/\/index\.html$/.test(k) && k !== 'index.html') return;   // 非单页形状
                if (/^wp-content\//.test(k) || /^style\//.test(k) || /^hl\//.test(k)) return; // 资源线排除
                if (!want[k]) found.push({ key: k, size: o.size || 0 });
            });
            cursor = page.truncated ? page.cursor : undefined;
        } while (cursor);
        // 3) 可选删除
        const doDelete = !!body.delete;
        let deleted = 0;
        if (doDelete) {
            for (const o of found) {
                try { await env.R2_BUCKET.delete(o.key); deleted++; } catch (e) { /* 单个失败继续 */ }
            }
        }
        return json({
            ok: true, pattern: pat,
            expected: Object.keys(want).length,
            orphanCount: found.length,
            orphans: doDelete ? [] : found.slice(0, 50),
            truncated: (!doDelete && found.length > 50),
            deleted: deleted,
        });
    }

    // ---- 模板管理（单页壳 / 列表条目；KV 存储，多套切换） ----
    if (path === '/api/templates' && method === 'GET') {
        const active = (await getActiveTpl(env)) || {};
        const idx = await tplGetIndex(env);
        const act = {};
        for (const k of TPL_KINDS) {
            act[k] = { id: (active[k] && active[k].id) || 'default', name: (active[k] && active[k].name) || '内置默认' };
        }
        return json({ active: act, index: idx, styles: await styleIndex(env) });
    }
    if (path === '/api/templates/content' && method === 'GET') {
        const type = tplKind(url.searchParams.get('type'));
        const id = String(url.searchParams.get('id') || '');
        if (id === 'default' || id === '') return json({ content: '' });
        const raw = await env.KV_CACHE.get('tpl:store:' + type + ':' + id);
        if (!raw) return json({ error: 'not found' }, 404);
        const o = JSON.parse(raw);
        return json({ id: o.id, name: o.name, content: o.content, updated: o.updated });
    }
    if (path === '/api/templates/save' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const type = tplKind(body.type);
        const content = String(body.content || '');
        if (!content.trim()) return json({ error: '模板内容不能为空' }, 400);
        if (content.length > 300 * 1024) return json({ error: '模板过大（上限 300KB）' }, 400);
        const name = String(body.name || '').trim().slice(0, 40) || ('模板 ' + new Date().toISOString().slice(0, 16).replace('T', ' '));
        let id = String(body.id || '');
        if (!id) id = 'c' + Date.now().toString(36) + Math.floor(Math.random() * 1000);
        const rec = { id: id, name: name, content: content, updated: new Date().toISOString().slice(0, 19) };
        await env.KV_CACHE.put('tpl:store:' + type + ':' + id, JSON.stringify(rec));
        const idx = await tplGetIndex(env);
        idx[type] = (idx[type] || []).filter(function (x) { return x.id !== id; });
        idx[type].unshift({ id: id, name: name, updated: rec.updated });
        await env.KV_CACHE.put('tpl:index', JSON.stringify(idx));
        return json({ ok: true, id: id, name: name });
    }
    if (path === '/api/templates/activate' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const type = tplKind(body.type);
        const id = String(body.id || 'default');
        const active = (await getActiveTpl(env)) || {};
        if (id === 'default') {
            active[type] = { id: 'default', name: '内置默认', content: '' };
        } else {
            const raw = await env.KV_CACHE.get('tpl:store:' + type + ':' + id);
            if (!raw) return json({ error: '模板不存在' }, 404);
            const o = JSON.parse(raw);
            active[type] = { id: o.id, name: o.name, content: o.content };
        }
        await env.KV_CACHE.put('tpl:active', JSON.stringify(active));
        return json({ ok: true });
    }
    if (path === '/api/templates/delete' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const type = tplKind(body.type);
        const id = String(body.id || '');
        if (!id || id === 'default') return json({ error: '无效 id' }, 400);
        await env.KV_CACHE.delete('tpl:store:' + type + ':' + id);
        const idx = await tplGetIndex(env);
        idx[type] = (idx[type] || []).filter(function (x) { return x.id !== id; });
        await env.KV_CACHE.put('tpl:index', JSON.stringify(idx));
        const active = (await getActiveTpl(env)) || {};
        if (active[type] && active[type].id === id) {
            active[type] = { id: 'default', name: '内置默认', content: '' };
            await env.KV_CACHE.put('tpl:active', JSON.stringify(active));
        }
        return json({ ok: true });
    }
    if (path === '/api/templates/preview' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const type = tplKind(body.type);
        const content = String(body.content || '');
        if (!content.trim()) return json({ error: '模板内容为空' }, 400);
        const html = await tplPreviewHtml(env, type, content, body.tplAll || null);
        if (!html) return json({ error: '未知类型' }, 400);
        return json({ html: html });
    }

    // ---- 风格包（整体应用/导入导出/多套）与风格静态资源（R2） ----
    if (path === '/api/templates/styles' && method === 'GET') {
        return json({ styles: await styleIndex(env) });
    }
    if (path === '/api/templates/style/preview' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        let tpl = (body.tpl && typeof body.tpl === 'object') ? body.tpl : null;
        const id = String(body.id || '');
        if (!tpl && id === '__default__') tpl = {};
        if (!tpl && id) {
            // 用户风格 → D1 当前版；内置风格前端先 fetch static tpl.json 后传 tpl
            const prow = await env.D1_DATABASE.prepare('SELECT id, name, tpl_json FROM styles WHERE id = ? ORDER BY version DESC LIMIT 1').bind(id).first();
            if (prow) { try { tpl = JSON.parse(prow.tpl_json || '{}'); } catch (e) { tpl = {}; } }
            else {
                const legacy = await env.KV_CACHE.get('tpl:style:' + id);
                if (legacy) { try { tpl = (JSON.parse(legacy).tpl) || {}; } catch (e) { tpl = {}; } }
                else return json({ error: 'style not found' }, 404);
            }
        }
        if (!tpl) {
            const active = (await getActiveTpl(env)) || {};
            tpl = {};
            for (const k of TPL_KINDS) tpl[k] = (active[k] && active[k].content) || '';
        }
        const pages = [];
        const order = ['single', 'pageHome', 'pageCategory', 'pageTag', 'pageArchive', 'item'];
        for (const t of order) {
            const c = String(tpl[t] || '');
            const html = await tplPreviewHtml(env, t, c, tpl);
            pages.push({ kind: t, html: html });
        }
        return json({ pages: pages });
    }
    if (path === '/api/templates/style/get' && method === 'GET') {
        await ensureStylesTable(env);
        const id = String(url.searchParams.get('id') || '');
        if (id === '__default__') {
            // 默认风格（内置模板）：不依赖任何 tpl.json 资源，返回空组合即可
            // （渲染端遇到空内容会回退部署内置模板）。此前缺这个分支，前端加载/预览
            // 默认风格会拿到 404，被误报成「内置风格资源未部署」。
            const tplEmpty = {};
            for (const k of TPL_KINDS) tplEmpty[k] = '';
            return json({ id: '__default__', name: '默认风格（内置模板）', tpl: tplEmpty });
        }
        if (id) {
            // 内置风格 → 返回 builtin 标记，内容由前端 fetch static /style/<id>/tpl.json
            const bi = await builtinIndex(env);
            const biHit = bi.find(function (x) { return x.id === id; });
            if (biHit) {
                return json({ id: id, name: biHit.name || id, builtin: true, tpl: {} });
            }
            // 用户风格 → D1 当前版本
            const row = await env.D1_DATABASE.prepare('SELECT id, version, name, tpl_json FROM styles WHERE id = ? ORDER BY version DESC LIMIT 1').bind(id).first();
            if (row) {
                let tpl = {};
                try { tpl = JSON.parse(row.tpl_json || '{}'); } catch (e) { tpl = {}; }
                return json({ id: row.id, version: row.version, name: row.name, source: 'user', tpl: tpl });
            }
            // 迁移前兼容：回退旧 KV 风格
            const legacy = await env.KV_CACHE.get('tpl:style:' + id);
            if (legacy) {
                const rec = JSON.parse(legacy);
                return json({ id: rec.id || id, name: rec.name || id, source: 'legacy', tpl: (rec.tpl || {}) });
            }
            return json({ error: 'style not found' }, 404);
        }
        // 无 id → 返回当前生效组合
        const active = (await getActiveTpl(env)) || {};
        const tpl = {};
        for (const k of TPL_KINDS) tpl[k] = (active[k] && active[k].content) || '';
        return json({ id: '', name: '当前生效模板', tpl: tpl });
    }

    if (path === '/api/templates/style/save' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: 'bad body' }, 400); }
        const src = (body.tpl && typeof body.tpl === 'object') ? body.tpl : body;
        const tpl = {};
        let total = 0;
        for (const k of TPL_KINDS) {
            const v = (src[k] === undefined || src[k] === null) ? '' : String(src[k]);
            if (v.length > 300 * 1024) return json({ error: k + ' too large' }, 400);
            tpl[k] = v; total += v.length;
        }
        if (total === 0) return json({ error: 'empty style' }, 400);
        if (total > 900 * 1024) return json({ error: 'style too large' }, 400);
        const name = String(body.name || '').trim().slice(0, 40) || ('style ' + new Date().toISOString().slice(0, 16).replace('T', ' '));
        let id = String(body.id || '');
        if (!id) id = 's' + Date.now().toString(36) + Math.floor(Math.random() * 1000);
        // 与内置风格同名 → 提示改名（slug 唯一）
        const bi = await builtinIndex(env);
        if (bi.some(function (x) { return x.id === id; })) {
            return json({ error: 'name conflicts with builtin style, please rename' }, 400);
        }
        const now = new Date().toISOString().slice(0, 19);
        const prev = await env.D1_DATABASE.prepare('SELECT MAX(version) AS v FROM styles WHERE id = ?').bind(id).first();
        const version = ((prev && prev.v) ? parseInt(prev.v, 10) : 0) + 1;
        await env.D1_DATABASE.prepare('INSERT INTO styles (id, version, name, tpl_json, updated_at) VALUES (?, ?, ?, ?, ?)').bind(id, version, name, JSON.stringify(tpl), now).run();
        let idx = await userStyleIndex(env);
        idx = idx.filter(function (x) { return x.id !== id; });
        idx.unshift({ id: id, name: name, updated: now, source: 'user' });
        await env.KV_CACHE.put('tpl:sindex', JSON.stringify(idx));
        await pruneStyleVersions(env, id, 5);
        return json({ ok: true, id: id, name: name, version: version });
    }

    if (path === '/api/templates/style/apply' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: 'bad body' }, 400); }
        const id = String(body.id || '');
        if (id === '__default__') {
            const active = (await getActiveTpl(env)) || {};
            for (const k of TPL_KINDS) active[k] = { id: 'default', name: 'builtin', content: '' };
            await env.KV_CACHE.put('tpl:active', JSON.stringify(active));
            return json({ ok: true, name: 'Default (builtin)' });
        }
        let tpl = (body.tpl && typeof body.tpl === 'object') ? body.tpl : null;
        let name = String(body.name || id);
        if (!tpl) {
            const row = await env.D1_DATABASE.prepare('SELECT id, name, tpl_json FROM styles WHERE id = ? ORDER BY version DESC LIMIT 1').bind(id).first();
            if (!row) return json({ error: 'style not found' }, 404);
            try { tpl = JSON.parse(row.tpl_json || '{}'); } catch (e) { tpl = {}; }
            name = row.name || id;
        }
        const active = (await getActiveTpl(env)) || {};
        for (const k of TPL_KINDS) {
            const c = String((tpl && tpl[k]) || '');
            active[k] = c ? { id: id, name: name, content: c } : { id: 'default', name: 'builtin', content: '' };
        }
        await env.KV_CACHE.put('tpl:active', JSON.stringify(active));
        return json({ ok: true, name: name });
    }

    if (path === '/api/templates/style/delete' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: 'bad body' }, 400); }
        const id = String(body.id || '');
        if (!id) return json({ error: 'invalid id' }, 400);
        const bi = await builtinIndex(env);
        if (bi.some(function (x) { return x.id === id; })) {
            return json({ error: 'builtin style cannot be deleted' }, 400);
        }
        await env.D1_DATABASE.prepare('DELETE FROM styles WHERE id = ?').bind(id).run();
        let idx = await userStyleIndex(env);
        idx = idx.filter(function (x) { return x.id !== id; });
        await env.KV_CACHE.put('tpl:sindex', JSON.stringify(idx));
        return json({ ok: true });
    }

    // 风格版本历史（回退用）
    if (path === '/api/templates/style/versions' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: 'bad body' }, 400); }
        const id = String(body.id || '');
        if (!id) return json({ error: 'invalid id' }, 400);
        const rows = await env.D1_DATABASE.prepare('SELECT id, version, name, updated_at FROM styles WHERE id = ? ORDER BY version DESC').bind(id).all();
        return json({ versions: rows.results || [] });
    }
    // 回退到历史版本：另存为新版本（不删历史）+ 写 tpl:active
    if (path === '/api/templates/style/revert' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: 'bad body' }, 400); }
        const id = String(body.id || '');
        const version = parseInt(body.version, 10);
        if (!id || !version) return json({ error: 'invalid param' }, 400);
        const row = await env.D1_DATABASE.prepare('SELECT id, name, tpl_json FROM styles WHERE id = ? AND version = ?').bind(id, version).first();
        if (!row) return json({ error: 'version not found' }, 404);
        const now = new Date().toISOString().slice(0, 19);
        const prev = await env.D1_DATABASE.prepare('SELECT MAX(version) AS v FROM styles WHERE id = ?').bind(id).first();
        const newVer = ((prev && prev.v) ? parseInt(prev.v, 10) : 0) + 1;
        await env.D1_DATABASE.prepare('INSERT INTO styles (id, version, name, tpl_json, updated_at) VALUES (?, ?, ?, ?, ?)').bind(id, newVer, row.name + ' (r' + version + ')', row.tpl_json, now).run();
        await pruneStyleVersions(env, id, 5);
        let tpl = {};
        try { tpl = JSON.parse(row.tpl_json || '{}'); } catch (e) { tpl = {}; }
        const active = (await getActiveTpl(env)) || {};
        for (const k of TPL_KINDS) {
            const c = String(tpl[k] || '');
            active[k] = c ? { id: id, name: row.name, content: c } : { id: 'default', name: 'builtin', content: '' };
        }
        await env.KV_CACHE.put('tpl:active', JSON.stringify(active));
        return json({ ok: true, name: row.name, version: newVer });
    }
    if (path === '/api/style-assets' && method === 'POST') {
        let form;
        try { form = await request.formData(); } catch (e) { return json({ error: '无效表单' }, 400); }
        const file = form.get('file');
        if (!file || typeof file === 'string') return json({ error: '缺少文件' }, 400);
        if (file.size > 5 * 1024 * 1024) return json({ error: '单个资源不能超过 5MB' }, 413);
        const styleSlug = String(form.get('style') || '').toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
        if (!styleSlug) return json({ error: '缺少风格名（小写字母/数字/-）' }, 400);
        const rawName = String(file.name || 'file');
        const ext = rawName.includes('.') ? rawName.split('.').pop().toLowerCase() : '';
        const allowed = ['css', 'js', 'mjs', 'json', 'map', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'txt'];
        if (!allowed.includes(ext)) return json({ error: '不支持的类型：.' + ext }, 400);
        const safeBase = rawName.split('.').slice(0, -1).join('.').replace(/[^a-zA-Z0-9-]+/g, '-').slice(0, 80) || 'asset';
        const relPath = String(form.get('path') || '');
        let relSafe = '';
        if (relPath) {
            relSafe = relPath.split('/').map(function (seg) { return seg.replace(/[^a-zA-Z0-9._-]+/g, '-'); }).filter(function (seg) { return seg && seg !== '.' && seg !== '..'; }).join('/').slice(0, 160);
        }
        if (!relSafe) relSafe = safeBase + '.' + ext;
        // 私人风格资源与内置风格同形：都走 /wp-content/themes/<slug>/（请求链 assets 命中 → 未命中落 R2 同路径）
        const key = 'wp-content/themes/' + styleSlug + '/' + relSafe;
        const buf = await file.arrayBuffer();
        const ct = file.type || (ext === 'css' ? 'text/css' : ext === 'js' ? 'application/javascript' : ext === 'svg' ? 'image/svg+xml' : ext === 'json' ? 'application/json' : 'application/octet-stream');
        await env.R2_BUCKET.put(key, buf, { httpMetadata: { contentType: ct, cacheControl: 'public, max-age=3600' } });
        return json({ ok: true, key: key, url: '/' + key });
    }
    if (path === '/api/style-assets' && method === 'GET') {
        const styleSlug = String(url.searchParams.get('style') || '').toLowerCase().replace(/[^a-z0-9-]+/g, '-').slice(0, 40);
        if (!styleSlug) return json({ items: [] });
        const list = await env.R2_BUCKET.list({ prefix: 'wp-content/themes/' + styleSlug + '/' });
        const items = (list.objects || []).map(function (o) { return { key: o.key, size: o.size, url: '/' + o.key }; });
        return json({ items: items });
    }
    if (path === '/api/style-assets/delete' && method === 'POST') {
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const key = String(body.key || '');
        if (!key.startsWith('wp-content/themes/') && !key.startsWith('style/')) return json({ error: '无效 key' }, 400);
        await env.R2_BUCKET.delete(key);
        return json({ ok: true });
    }

    // ---- 分类 / 标签 ----
    const taxRoute = path.match(/^\/api\/(categories|tags)$/);
    const taxType = taxRoute ? (taxRoute[1] === 'tags' ? 'tag' : 'category') : null;
    if (taxType) {
        if (method === 'GET') {
            const rows = await db.prepare('SELECT t.slug, t.name, COUNT(ps.wp_id) AS cnt FROM taxonomies t LEFT JOIN post_taxonomy pt ON pt.taxonomy_type = t.type AND pt.taxonomy_slug = t.slug LEFT JOIN posts ps ON ps.wp_id = pt.post_id AND ps.status = \'published\' WHERE t.type = ? GROUP BY t.slug ORDER BY t.name COLLATE NOCASE ASC').bind(taxType).all();
            return json({ items: rows.results || [] });
        }
        if (method === 'POST') {
            let body = {};
            try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
            const name = String(body.name || '').trim();
            if (!name) return json({ error: '名称不能为空' }, 400);
            const slug = body.slug ? String(body.slug) : slugify(name) || ('c' + Date.now().toString(36));
            await db.prepare('INSERT INTO taxonomies (type, slug, name) VALUES (?, ?, ?) ON CONFLICT(type, slug) DO UPDATE SET name = excluded.name').bind(taxType, slug, name).run();
            return json({ ok: true, slug, name });
        }
    }
    const taxTerm = path.match(/^\/api\/(categories|tags)\/([^/]+)$/);
    const termType = taxTerm ? (taxTerm[1] === 'tags' ? 'tag' : 'category') : null;
    if (termType) {
        const termSlug = decodeURIComponent(taxTerm[2]);
        if (method === 'PUT') {
            let body = {};
            try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
            const name = String(body.name || '').trim();
            const newSlug = body.slug ? String(body.slug) : termSlug;
            await db.prepare('UPDATE taxonomies SET name = ?, slug = ? WHERE type = ? AND slug = ?').bind(name || termSlug, newSlug, termType, termSlug).run();
            await db.prepare('UPDATE post_taxonomy SET taxonomy_slug = ? WHERE taxonomy_type = ? AND taxonomy_slug = ?').bind(newSlug, termType, termSlug).run();
            // 同步存量文章 categories/tags JSON 里的 {name,slug}，保证前台列表/单页不残留旧名
            const rel = await db.prepare('SELECT wp_id, categories, tags FROM posts WHERE wp_id IN (SELECT post_id FROM post_taxonomy WHERE taxonomy_type = ? AND taxonomy_slug = ?)').bind(termType, newSlug).all();
            for (const r of (rel.results || [])) {
                for (const col of ['categories', 'tags']) {
                    let arr = parseTaxoList(r[col]);
                    let changed = false;
                    arr = arr.map(function (el) {
                        if (el && String(el.slug) === termSlug) {
                            changed = true;
                            el.name = name || el.name;
                            el.slug = newSlug;
                        }
                        return el;
                    });
                    if (changed) await db.prepare('UPDATE posts SET ' + col + ' = ? WHERE wp_id = ?').bind(JSON.stringify(arr), r.wp_id).run();
                }
            }
            await purgeLists(env);
            return json({ ok: true });
        }
        if (method === 'DELETE') {
            const cnt = await db.prepare('SELECT COUNT(*) AS c FROM post_taxonomy WHERE taxonomy_type = ? AND taxonomy_slug = ?').bind(termType, termSlug).first();
            const n = parseInt((cnt && cnt.c) || 0, 10);
            if (n > 0) {
                return json({ error: '该' + (termType === 'tag' ? '标签' : '分类') + '下仍有 ' + n + ' 篇文章，无法删除' }, 409);
            }
            await db.prepare('DELETE FROM taxonomies WHERE type = ? AND slug = ?').bind(termType, termSlug).run();
            await db.prepare('DELETE FROM post_taxonomy WHERE taxonomy_type = ? AND taxonomy_slug = ?').bind(termType, termSlug).run();
            await purgeLists(env);
            return json({ ok: true });
        }
    }

    // ---- 媒体（R2 wp-content/uploads）----
    if (path === '/api/media' && method === 'POST') {
        let form;
        try { form = await request.formData(); } catch (e) { return json({ error: '无效表单' }, 400); }
        const file = form.get('file');
        if (!file || typeof file === 'string') return json({ error: '缺少文件' }, 400);
        if (file.size > 10 * 1024 * 1024) return json({ error: '文件不能超过 10MB' }, 413);
        const name = String(file.name || '').toLowerCase();
        const ext = name.includes('.') ? name.split('.').pop() : '';
        const allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!allowed.includes(ext)) return json({ error: '仅支持 jpg/png/gif/webp' }, 400);
        const contentType = file.type || ('image/' + (ext === 'jpg' ? 'jpeg' : ext));
        const now = new Date();
        const y = now.getUTCFullYear();
        const mm = String(now.getUTCMonth() + 1).padStart(2, '0');
        const rand = Math.random().toString(36).slice(2, 10);
        const safeBase = name.split('.').slice(0, -1).join('.').replace(/[^a-z0-9\u4e00-\u9fa5-]+/g, '-').slice(0, 60) || 'img';
        const path = 'wp-content/uploads/' + y + '/' + mm + '/' + rand + '-' + safeBase + '.' + ext;
        const buf = await file.arrayBuffer();
        // 魔数嗅探：防止伪装扩展名上传非图片（sniff 前若干字节）
        const head = new Uint8Array(buf, 0, 12);
        const sig = function () { let s = ''; for (let i = 0; i < head.length; i++) s += String.fromCharCode(head[i]); return s; }();
        const isPng = head[0] === 0x89 && head[1] === 0x50 && head[2] === 0x4E && head[3] === 0x47;
        const isJpeg = head[0] === 0xFF && head[1] === 0xD8 && head[2] === 0xFF;
        const isGif = sig.slice(0, 4) === 'GIF8';
        const isWebp = sig.slice(0, 4) === 'RIFF' && sig.slice(8, 12) === 'WEBP';
        if (!isPng && !isJpeg && !isGif && !isWebp) return json({ error: '文件内容不是有效图片' }, 400);
        try {
            await env.R2_BUCKET.put(path, buf, { httpMetadata: { contentType: contentType } });
        } catch (e) { console.error('media put failed:', e); return json({ error: '上传失败' }, 500); }
        const site = await getSiteContext(env);
        return json({ ok: true, path: path, url: (site.url || '') + '/' + path });
    }

    // PUT /api/media?key=...（同 key 覆盖替换；正文引用 URL 不变即自动生效）
    if (path === '/api/media' && method === 'PUT') {
        const key = decodeURIComponent(url.searchParams.get('key') || '');
        if (!key || !key.startsWith('wp-content/uploads/')) return json({ error: '参数错误' }, 400);
        const ext = key.includes('.') ? key.split('.').pop().toLowerCase() : '';
        if (!['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) return json({ error: '仅支持 jpg/png/gif/webp' }, 400);
        let form;
        try { form = await request.formData(); } catch (e) { return json({ error: '无效表单' }, 400); }
        const file = form.get('file');
        if (!file || typeof file === 'string') return json({ error: '缺少文件' }, 400);
        if (file.size > 10 * 1024 * 1024) return json({ error: '文件不能超过 10MB' }, 413);
        const buf = await file.arrayBuffer();
        const head = new Uint8Array(buf, 0, 12);
        const sig = function () { let s = ''; for (let i = 0; i < head.length; i++) s += String.fromCharCode(head[i]); return s; }();
        const isPng = head[0] === 0x89 && head[1] === 0x50 && head[2] === 0x4E && head[3] === 0x47;
        const isJpeg = head[0] === 0xFF && head[1] === 0xD8 && head[2] === 0xFF;
        const isGif = sig.slice(0, 4) === 'GIF8';
        const isWebp = sig.slice(0, 4) === 'RIFF' && sig.slice(8, 12) === 'WEBP';
        if (!isPng && !isJpeg && !isGif && !isWebp) return json({ error: '文件内容不是有效图片' }, 400);
        const contentType = file.type || ('image/' + (ext === 'jpg' ? 'jpeg' : ext));
        try {
            await env.R2_BUCKET.put(key, buf, { httpMetadata: { contentType: contentType } });
        } catch (e) { console.error('media replace failed:', e); return json({ error: '替换失败' }, 500); }
        const site = await getSiteContext(env);
        return json({ ok: true, path: key, url: (site.url || '') + '/' + key });
    }
    if (path === '/api/media' && method === 'GET') {
        const cursor = url.searchParams.get('cursor') || undefined;
        const limit = Math.min(100, Math.max(1, parseInt(url.searchParams.get('limit') || '50', 10) || 50));
        const list = await env.R2_BUCKET.list({ prefix: 'wp-content/uploads/', limit: limit, cursor: cursor });
        const site = await getSiteContext(env);
        const items = (list.objects || []).map(function (o) {
            return { key: o.key, size: o.size, uploaded: o.uploaded, url: (site.url || '') + '/' + o.key };
        });
        return json({ items: items, truncated: !!list.truncated, cursor: list.truncated ? list.cursor : null });
    }

    // DELETE /api/media?key=wp-content/uploads/...（删除媒体对象）
    if (path === '/api/media' && method === 'DELETE') {
        const key = url.searchParams.get('key') || '';
        if (!key || !key.startsWith('wp-content/uploads/')) return json({ error: '参数错误' }, 400);
        try {
            await env.R2_BUCKET.delete(key);
        } catch (e) { console.error('media delete failed:', e); return json({ error: '删除失败' }, 500); }
        return json({ ok: true, key: key });
    }

    // ---- 用户管理（仅 admin）----
    if (path === '/api/users' && method === 'GET') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        const rows = await db.prepare("SELECT id, user_login, user_email, display_name, role, user_status, user_registered FROM users ORDER BY id ASC").all();
        return json({ users: rows.results || [] });
    }
    if (path === '/api/users' && method === 'POST') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const login = String(body.username || '').trim();
        const pass = String(body.password || '');
        const email = String(body.email || '').trim();
        const role = ['admin', 'editor', 'author', 'subscriber'].includes(body.role) ? body.role : 'editor';
        if (!/^[a-zA-Z0-9_.-]{3,}$/.test(login)) return json({ error: '用户名需 3 位以上字母数字' }, 400);
        if (pass.length < 8) return json({ error: '密码至少 8 位' }, 400);
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return json({ error: '邮箱格式不正确' }, 400);
        const dup = await db.prepare('SELECT id FROM users WHERE user_login = ?').bind(login).first();
        if (dup) return json({ error: '用户名已存在' }, 409);
        const store = await hashPassword(pass);
        const res = await db.prepare("INSERT INTO users (user_login, user_pass, user_nicename, user_email, display_name, role, user_status) VALUES (?, ?, ?, ?, ?, ?, 1)")
            .bind(login, store, login, email, String(body.display_name || '').trim() || login, role).run();
        return json({ ok: true, id: res && res.meta && res.meta.last_row_id ? res.meta.last_row_id : 0 });
    }
    const um = path.match(/^\/api\/users\/(\d+)$/);
    if (um && method === 'PUT') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        const id = parseInt(um[1], 10);
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const sets = [];
        const params = [];
        if (body.password !== undefined && String(body.password) !== '') {
            const pw = String(body.password);
            if (pw.length < 8) return json({ error: '密码至少 8 位' }, 400);
            sets.push('user_pass = ?'); params.push(await hashPassword(pw));
        }
        if (body.display_name !== undefined) { sets.push('display_name = ?'); params.push(String(body.display_name).trim()); }
        if (body.email !== undefined) {
            const em = String(body.email).trim();
            if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(em)) return json({ error: '邮箱格式不正确' }, 400);
            sets.push('user_email = ?'); params.push(em);
        }
        if (body.role !== undefined) {
            if (!['admin', 'editor', 'author', 'subscriber'].includes(body.role)) return json({ error: '角色无效' }, 400);
            sets.push('role = ?'); params.push(body.role);
        }
        if (body.user_status !== undefined) {
            const st = body.user_status === 1 ? 1 : 0;
            if (id === authPayload.sub && st === 0) return json({ error: '不能禁用当前登录账号' }, 400);
            sets.push('user_status = ?'); params.push(st);
        }
        if (!sets.length) return json({ error: '没有要更新的字段' }, 400);
        sets.push('updated_at = CURRENT_TIMESTAMP');
        params.push(id);
        await db.prepare('UPDATE users SET ' + sets.join(', ') + ' WHERE id = ?').bind(...params).run();
        return json({ ok: true });
    }
    if (um && method === 'DELETE') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        const id = parseInt(um[1], 10);
        if (id === authPayload.sub) return json({ error: '不能删除当前登录账号' }, 400);
        await db.prepare('DELETE FROM users WHERE id = ?').bind(id).run();
        return json({ ok: true });
    }

    // ---- 导出 / 导入（备份恢复）----
    if (path === '/api/export' && method === 'GET') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        const rows = await db.prepare('SELECT wp_id, slug, title, content, content_format, excerpt, date, modified, status, categories, tags, featured_image FROM posts ORDER BY date ASC').all();
        return json({ exportedAt: nowDateTime(), total: (rows.results || []).length, posts: rows.results || [] });
    }
    if (path === '/api/import' && method === 'POST') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const siteCtx = await getSiteContext(env);
        const off = siteCtx.timezoneOffset || 0;
        const items = Array.isArray(body.posts) ? body.posts
            : (body.posts && Array.isArray(body.posts.posts) ? body.posts.posts : []);
        if (!items.length) return json({ error: '没有可导入的文章' }, 400);
        const baseId = Math.floor(Date.now()) * 1000000;
        let imported = 0;
        const skipped = [];
        for (let i = 0; i < items.length; i++) {
            const it = items[i] || {};
            const slug = String((it.slug || it.title || '').trim()).toLowerCase();
            if (!slug || !String(it.title || '').trim()) { skipped.push({ slug: slug || '?', reason: '缺少标题或 slug' }); continue; }
            const exists = await db.prepare('SELECT wp_id FROM posts WHERE slug = ?').bind(slug).first();
            if (exists) { skipped.push({ slug: slug, reason: '已存在' }); continue; }
            const itFmt = it.content_format === 'md' ? 'md' : 'html';
            const content = itFmt === 'md' ? String(it.content || '') : scrubHtml(String(it.content || ''));
            const excerpt = (it.excerpt !== undefined && String(it.excerpt).trim() !== '') ? String(it.excerpt).trim()
                : (itFmt === 'md' ? mdToText(content, 200) : excerptOf(content));
            const status = it.status === 'draft' ? 'draft' : 'published';
            const wpId = baseId + i;
            const cats = withDefaultCategory(parseTaxoList(it.categories));
            const tags = parseTaxoList(it.tags);
            await db.prepare('INSERT INTO posts (wp_id, slug, title, content, content_format, excerpt, date, modified, status, categories, tags, featured_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                .bind(wpId, slug, String(it.title), content, itFmt, excerpt, String(it.date || '').trim() || localDateTime(off), localDateTime(off), status, JSON.stringify(cats), JSON.stringify(tags), String(it.featured_image || '')).run();
            await syncTaxonomy(db, wpId, 'category', cats);
            await syncTaxonomy(db, wpId, 'tag', tags);
            const nrow = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(wpId).first();
            if (status === 'published' && nrow) await writeSinglePage(env, nrow);
            imported++;
        }
        if (imported) await purgeLists(env);
        return json({ ok: true, imported: imported, skipped: skipped });
    }

    // POST /api/cache/purge（admin：按前缀批量清 KV 缓存）
    if (path === '/api/cache/purge' && method === 'POST') {
        if (authPayload.role !== 'admin') return json({ error: '无权限' }, 403);
        let body = {};
        try { body = await request.json(); } catch (e) { return json({ error: '无效请求体' }, 400); }
        const all = ['home:page:', 'sidebar:data', 'seo:', 'post:', 'rss:', 'site:ctx'];
        const prefixes = Array.isArray(body.prefixes) && body.prefixes.length ? body.prefixes : all;
        let cleared = 0;
        for (const p of prefixes) {
            try {
                if (p === 'sidebar:data') { await deleteCache(env.KV_CACHE, 'sidebar:data'); cleared++; }
                else { await purgeCache(env.KV_CACHE, String(p)); cleared++; }
            } catch (e) { console.error('purge failed:', p, e); }
        }
        return json({ ok: true, cleared: cleared });
    }

    // GET /api/audit（内容质检：无分类 / 缺封面 / 超长标题 的已发布文章）
    if (path === '/api/audit' && method === 'GET') {
        const rows = await db.prepare("SELECT wp_id, title, slug, featured_image, categories FROM posts WHERE status = 'published' ORDER BY date DESC").all();
        const noCat = [], noFeature = [], longTitle = [];
        for (const r of (rows.results || [])) {
            const arr = parseTaxoList(r.categories);
            const item = { wp_id: r.wp_id, slug: r.slug, title: String(r.title || '') };
            if (!arr.length) noCat.push(item);
            if (!String(r.featured_image || '').trim()) noFeature.push(item);
            if (item.title.length > 80) longTitle.push(item);
        }
        return json({
            total: (rows.results || []).length,
            noCat: noCat.length, noCatItems: noCat.slice(0, 100),
            noFeature: noFeature.length, noFeatureItems: noFeature.slice(0, 100),
            longTitle: longTitle.length, longTitleItems: longTitle.slice(0, 100),
        });
    }

    // GET /api/export-media（媒体对象清单 JSON，供备份归档）
    if (path === '/api/export-media' && method === 'GET') {
        const out = [];
        let cur = '';
        try {
            for (let i = 0; i < 50; i++) {
                const page = await env.R2_BUCKET.list({ prefix: 'wp-content/uploads/', limit: 1000, cursor: cur || undefined });
                for (const o of (page.objects || [])) out.push({ key: o.key, size: o.size, uploaded: o.uploaded });
                if (!page.truncated) break;
                cur = page.cursor || '';
            }
        } catch (e) { console.error('export-media failed:', e); return json({ error: '读取媒体失败' }, 500); }
        return json({ exportedAt: nowDateTime(), total: out.length, items: out });
    }

    // ---- 统计（管理首页概览）----
    if (path === '/api/stats' && method === 'GET') {
        const q1 = await db.prepare('SELECT COUNT(*) AS c FROM posts').first();
        const q2 = await db.prepare("SELECT COUNT(*) AS c FROM posts WHERE status = 'published'").first();
        const q3 = await db.prepare("SELECT COUNT(*) AS c FROM posts WHERE status = 'draft'").first();
        const q4 = await db.prepare("SELECT COUNT(*) AS c FROM taxonomies WHERE type = 'category'").first();
        const q5 = await db.prepare("SELECT COUNT(*) AS c FROM taxonomies WHERE type = 'tag'").first();
        let mediaCount = 0;
        let mediaBytes = 0;
        let mcursor = '';
        try {
            for (let i = 0; i < 10; i++) {
                const page = await env.R2_BUCKET.list({ prefix: 'wp-content/uploads/', limit: 1000, cursor: mcursor || undefined });
                for (const o of (page.objects || [])) { mediaCount++; mediaBytes += o.size || 0; }
                if (!page.truncated) break;
                mcursor = page.cursor || '';
            }
        } catch (e) { mediaCount = -1; }
        return json({
            posts: parseInt((q1 && q1.c) || 0, 10),
            published: parseInt((q2 && q2.c) || 0, 10),
            draft: parseInt((q3 && q3.c) || 0, 10),
            categories: parseInt((q4 && q4.c) || 0, 10),
            tags: parseInt((q5 && q5.c) || 0, 10),
            media: mediaCount,
            mediaBytes: mediaBytes,
        });
    }

    return json({ error: '接口不存在' }, 404);
}


// ============================================================
// WP REST 兼容写入口（第三方 md 编辑器：Obsidian / Ulysses / 脚本）
//   路径：/wp-json/wp/v2/* 与 /wp/v2/*
//   认证：Basic Auth（用户名 + 应用密码；也接受账号密码，与 WP Application Passwords 一致）
//   归属：新建文章 wp_id = Date.now()*100+rand（时间戳量级，即 CFSE_WORKER_ID_MIN 之上 ⇒ Worker 端诞生）
//         格式：新建默认 content_format='md'（第三方为 md 编辑器）；若请求体看起来是 HTML 则按 html 存
// ============================================================

const AP_CHARS = 'abcdefghijkmnpqrstuvwxyz23456789';
const wpv2Buckets = new Map();

function apBytesHex(bytes) { let out = ''; for (let i = 0; i < bytes.length; i++) out += bytes[i].toString(16).padStart(2, '0'); return out; }
function apHexBytes(hex) {
    const clean = String(hex).replace(/[^0-9a-fA-F]/g, '');
    const out = new Uint8Array(clean.length / 2);
    for (let i = 0; i < out.length; i++) out[i] = parseInt(clean.substr(i * 2, 2), 16);
    return out;
}
function apConstEq(a, b) {
    const x = String(a).toLowerCase(), y = String(b).toLowerCase();
    if (x.length !== y.length) return false;
    let d = 0;
    for (let i = 0; i < x.length; i++) d |= x.charCodeAt(i) ^ y.charCodeAt(i);
    return d === 0;
}

/** 生成应用密码明文：20 位 base32（去掉易混的 l/o/0/1），按 4 位分组便于抄写 */
function genAppPasswordPlain() {
    const bytes = crypto.getRandomValues(new Uint8Array(20));
    let str = '';
    for (let i = 0; i < bytes.length; i++) str += AP_CHARS[bytes[i] % AP_CHARS.length];
    return str.match(/.{1,4}/g).join('-');
}

/** 应用密码哈希：高熵随机串 ⇒ SHA-256+盐足够（PBKDF2 100k 每请求 20-40ms 会撞免费档 CPU 上限） */
async function hashAppPassword(plain) {
    const salt = crypto.getRandomValues(new Uint8Array(16));
    const input = new Uint8Array(salt.length + plain.length);
    input.set(salt, 0);
    input.set(new TextEncoder().encode(plain), salt.length);
    const bits = await crypto.subtle.digest('SHA-256', input);
    return 'sha256$' + apBytesHex(salt) + '$' + apBytesHex(new Uint8Array(bits));
}

async function verifyAppPassword(plain, stored) {
    stored = String(stored || '');
    if (!stored) return false;
    if (stored.indexOf('pbkdf2_sha256$') === 0) return verifyPassword(plain, stored);
    const parts = stored.split('$');
    if (parts.length !== 3 || parts[0] !== 'sha256') return false;
    const salt = apHexBytes(parts[1]);
    const input = new Uint8Array(salt.length + plain.length);
    input.set(salt, 0);
    input.set(new TextEncoder().encode(plain), salt.length);
    const bits = await crypto.subtle.digest('SHA-256', input);
    return apConstEq(apBytesHex(new Uint8Array(bits)), parts[2]);
}

async function ensureAppPasswordsTable(env) {
    try {
        await env.D1_DATABASE.prepare("CREATE TABLE IF NOT EXISTS app_passwords (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '', pass_hash TEXT NOT NULL, last_used_at TEXT DEFAULT '', created_at TEXT NOT NULL, revoked INTEGER NOT NULL DEFAULT 0)").run();
        await env.D1_DATABASE.prepare('CREATE INDEX IF NOT EXISTS idx_apppw_user ON app_passwords(user_id)').run();
    } catch (e) {
        console.error('ensureAppPasswordsTable:', e);
    }
}

/** Basic Auth → {id, username, role, displayName, email, apId}；失败返回 null */
async function authenticateAppPassword(db, request) {
    const auth = request.headers.get('Authorization') || '';
    const m = auth.match(/^Basic\s+(.+)$/i);
    if (!m) return null;
    let decoded = '';
    try { decoded = atob(m[1].trim()); } catch (e) { return null; }
    const ix = decoded.indexOf(':');
    if (ix <= 0) return null;
    const username = decoded.slice(0, ix).trim();
    const password = decoded.slice(ix + 1).replace(/\s+/g, '');
    if (!username || !password) return null;
    const u = await db.prepare('SELECT * FROM users WHERE user_login = ?').bind(username).first();
    if (!u || parseInt(u.user_status, 10) !== 1) return null;
    let rows = { results: [] };
    try {
        rows = await db.prepare('SELECT id, pass_hash FROM app_passwords WHERE user_id = ? AND revoked = 0').bind(u.id).all();
    } catch (e) { /* 表未建：忽略，走账号密码兜底 */ }
    for (const r of (rows.results || [])) {
        if (await verifyAppPassword(password, r.pass_hash)) {
            try { await db.prepare('UPDATE app_passwords SET last_used_at = ? WHERE id = ?').bind(nowDateTime(), r.id).run(); } catch (e) {}
            return { id: u.id, username: u.user_login, role: u.role, displayName: u.display_name, email: u.user_email, apId: r.id };
        }
    }
    if (await verifyPassword(password, u.user_pass)) {
        return { id: u.id, username: u.user_login, role: u.role, displayName: u.display_name, email: u.user_email, apId: 0 };
    }
    return null;
}

/** 写入口限速：内存滑窗（isolate 级，0 KV）；读 600/分、写 90/分 */
function wpv2RateAllowed(key, isWrite) {
    const limit = isWrite ? 90 : 600;
    const now = Date.now();
    const k = String(key);
    let arr = wpv2Buckets.get(k);
    if (!arr) { arr = []; wpv2Buckets.set(k, arr); }
    while (arr.length && now - arr[0] > 60000) arr.shift();
    if (arr.length >= limit) return false;
    arr.push(now);
    return true;
}

function wpErr(code, message, status) {
    return new Response(JSON.stringify({ code: code, message: message, data: { status: status } }), {
        status: status,
        headers: { 'Content-Type': 'application/json; charset=UTF-8' },
    });
}

function wpJson(data, status) {
    return new Response(JSON.stringify(data), {
        status: status || 200,
        headers: { 'Content-Type': 'application/json; charset=UTF-8' },
    });
}

function wpIso(dt) { return String(dt || '').replace(' ', 'T'); }
function wpInternalStatus(st) {
    const v = String(st || '').toLowerCase();
    return (v === 'publish' || v === 'published') ? 'published' : 'draft';
}
function wpRoleNames(role) {
    const r = String(role || '');
    if (r === 'admin') return ['administrator'];
    if (r === 'editor') return ['editor'];
    if (r === 'author') return ['author'];
    return ['subscriber'];
}

/** 取 WP REST 字段文本：支持字符串 / {raw} / {rendered} */
function wpFieldText(v) {
    if (v === undefined || v === null) return undefined;
    if (typeof v === 'object') {
        if (typeof v.raw === 'string') return v.raw;
        if (typeof v.rendered === 'string') return v.rendered;
        return undefined;
    }
    return String(v);
}

/** 把分类/标签 slug 列表映射成 term id（WP 客户端认 id） */
async function wpTermIds(db, type, listJson) {
    const items = parseTaxoList(listJson);
    if (!items.length) return [];
    const rows = await db.prepare('SELECT rowid AS id, slug FROM taxonomies WHERE type = ?').bind(type).all();
    const map = {};
    for (const r of (rows.results || [])) map[r.slug] = r.id;
    const out = [];
    for (const t of items) {
        if (t && map[t.slug] !== undefined) out.push(map[t.slug]);
    }
    return out;
}

/** 入参 terms（id 或 slug 或对象）→ [{name, slug}]；返回 null 表示"未提供" */
async function wpResolveTerms(db, type, input) {
    if (input === undefined || input === null) return null;
    const list = Array.isArray(input) ? input : (typeof input === 'string' ? String(input).split(',') : [input]);
    const rows = await db.prepare('SELECT rowid AS id, slug, name FROM taxonomies WHERE type = ?').bind(type).all();
    const byId = {}, bySlug = {};
    for (const t of (rows.results || [])) { byId[String(t.id)] = t; bySlug[t.slug] = t; }
    const out = [];
    for (const el of list) {
        if (el === undefined || el === null || el === '') continue;
        let hit = null;
        if (typeof el === 'object') {
            if (el.id !== undefined) hit = byId[String(el.id)];
            else if (el.slug) hit = bySlug[String(el.slug)];
        } else {
            const key = String(el).trim();
            hit = byId[key] || bySlug[key];
        }
        if (hit) out.push({ name: hit.name, slug: hit.slug });
    }
    return out;
}

/** WP REST 请求体 → 内部 payload（categories/tags 由调用方解析后填入） */
function wpPayloadFromRest(body, row) {
    const prevFmt = row ? ((row.content_format === 'md') ? 'md' : 'html') : 'md';
    let fmt = prevFmt;
    const fmtIn = (body.content_format !== undefined) ? body.content_format
        : (body.meta && typeof body.meta === 'object' ? body.meta.content_format : undefined);
    const titleIn = wpFieldText(body.title);
    const title = (titleIn !== undefined ? String(titleIn) : (row ? String(row.title) : '')).trim();
    if (!title) return { error: '标题不能为空' };
    const contentIn = wpFieldText(body.content);
    const rawContent = contentIn !== undefined ? String(contentIn) : (row ? String(row.content || '') : '');
    const looksHtml = /<(p|div|h[1-6]|ul|ol|li|blockquote|pre|img|figure|br)\b/i.test(rawContent);
    if (fmtIn === 'md' || fmtIn === 'html') fmt = fmtIn;
    else if (fmtIn === undefined && looksHtml) fmt = 'html';
    const content = fmt === 'md' ? rawContent : scrubHtml(rawContent);
    const slugIn = body.slug !== undefined ? String(body.slug) : (row ? String(row.slug || '') : '');
    const slug = (slugIn ? slugIn : (slugify(title) || ('p' + Date.now().toString(36)))).toLowerCase();
    const status = body.status !== undefined ? wpInternalStatus(body.status) : (row ? (row.status === 'published' ? 'published' : 'draft') : 'draft');
    const excerptIn = wpFieldText(body.excerpt);
    const excerpt = (excerptIn !== undefined && String(excerptIn).trim() !== '')
        ? String(excerptIn).trim()
        : (row ? String(row.excerpt || '') : (fmt === 'md' ? mdToText(content, 200) : excerptOf(content)));
    const dateIn = body.date !== undefined ? String(body.date).replace('T', ' ').replace(/\..*$/, '').slice(0, 19) : '';
    return {
        title: title,
        content: content,
        content_format: fmt,
        slug: slug,
        status: status,
        excerpt: excerpt,
        featured_image: body.featured_image !== undefined ? String(body.featured_image) : (row ? String(row.featured_image || '') : ''),
        date: dateIn || (row ? String(row.date || '') : localDateTime(0)),
    };
}

/** 组装 WP REST 的 post 形状（含 content.rendered：md 由 marked 渲染） */
async function wpPostShape(db, row, site) {
    const fmt = (row.content_format === 'md') ? 'md' : 'html';
    const raw = String(row.content || '');
    return {
        id: row.wp_id,
        date: wpIso(row.date),
        date_gmt: wpIso(row.date),
        modified: wpIso(row.modified),
        modified_gmt: wpIso(row.modified),
        slug: row.slug,
        status: row.status === 'published' ? 'publish' : 'draft',
        type: 'post',
        link: postPublicUrl(row, site),
        title: { rendered: row.title, raw: row.title },
        content: { rendered: fmt === 'md' ? mdToHtml(raw) : raw, raw: raw, protected: false },
        excerpt: { rendered: String(row.excerpt || ''), raw: String(row.excerpt || ''), protected: false },
        author: 0,
        featured_media: 0,
        comment_status: 'closed',
        ping_status: 'closed',
        sticky: false,
        format: 'standard',
        categories: await wpTermIds(db, 'category', row.categories),
        tags: await wpTermIds(db, 'tag', row.tags),
        meta: { content_format: fmt, cfse_wp_id: row.wp_id },
        cfse: { content_format: fmt, categories: parseTaxoList(row.categories), tags: parseTaxoList(row.tags) },
    };
}

/**
 * 入口：命中 /wp-json/wp/v2/* 或 /wp/v2/* 时由 routes 调用
 */
export async function handleWpV2(request, env) {
    const url = new URL(request.url);
    const method = request.method;
    const raw = url.pathname.replace(/^\/wp-json/, '');
    if (!(raw === '/wp/v2' || raw.indexOf('/wp/v2/') === 0)) return wpErr('rest_no_route', '未找到路由', 404);
    const route = raw.slice('/wp/v2'.length) || '/';
    const db = env.D1_DATABASE;

    const user = await authenticateAppPassword(db, request);
    if (!user) {
        return new Response(JSON.stringify({ code: 'rest_forbidden', message: '认证失败：请用「用户名 + 应用密码」的 Basic Auth（或账号密码）', data: { status: 401 } }), {
            status: 401,
            headers: { 'Content-Type': 'application/json; charset=UTF-8', 'WWW-Authenticate': 'Basic realm="cf-static-export-pro"' },
        });
    }
    if (user.role === 'subscriber') return wpErr('rest_forbidden', '该用户无权访问写入口', 403);
    const isWrite = method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE';
    if (!wpv2RateAllowed(user.apId || user.id, isWrite)) return wpErr('too_many_requests', '请求过于频繁，请稍后再试', 429);

    const site = await getSiteContext(env);
    console.log('[wpv2] ' + method + ' ' + raw + ' user=' + user.username + ' ip=' + (request.headers.get('CF-Connecting-IP') || '-'));

    // 当前用户
    if (route === '/users/me' && method === 'GET') {
        return wpJson({
            id: user.id,
            name: user.displayName || user.username,
            slug: user.username,
            url: '', description: '',
            link: (site.url || '') + '/author/' + user.username + '/',
            roles: wpRoleNames(user.role),
            capabilities: {}, extra_capabilities: {}, avatar_urls: {},
            cfse: { role: user.role, content_format: 'md' },
        });
    }
    // 类型 / 分类法探测（部分客户端启动时会请求）
    if (route === '/types' && method === 'GET') {
        return wpJson({ post: { slug: 'post', name: '文章', rest_base: 'posts', hierarchical: false, supports: { title: true, editor: true, excerpt: true, thumbnail: false, custom_fields: true } } });
    }
    if (route === '/taxonomies' && method === 'GET') {
        return wpJson({
            category: { slug: 'category', types: ['post'], rest_base: 'categories', name: '分类' },
            post_tag: { slug: 'post_tag', types: ['post'], rest_base: 'tags', name: '标签' },
        });
    }

    // 分类 / 标签
    const termM = route.match(/^\/(categories|tags)(?:\/([^/]+))?$/);
    if (termM) {
        const type = termM[1] === 'tags' ? 'tag' : 'category';
        if (!termM[2] && method === 'GET') {
            const per = Math.min(100, Math.max(1, parseInt(url.searchParams.get('per_page') || '100', 10) || 100));
            const page = Math.max(1, parseInt(url.searchParams.get('page') || '1', 10) || 1);
            const rows = await db.prepare('SELECT rowid AS id, slug, name FROM taxonomies WHERE type = ? ORDER BY name COLLATE NOCASE ASC LIMIT ? OFFSET ?').bind(type, per, (page - 1) * per).all();
            const out = [];
            for (const t of (rows.results || [])) {
                const c = await db.prepare("SELECT COUNT(*) AS n FROM post_taxonomy pt JOIN posts p ON p.wp_id = pt.post_id AND p.status = 'published' WHERE pt.taxonomy_type = ? AND pt.taxonomy_slug = ?").bind(type, t.slug).first();
                out.push({ id: t.id, count: parseInt((c && c.n) || 0, 10), description: '', link: '', name: t.name, slug: t.slug, taxonomy: type === 'tag' ? 'post_tag' : 'category', parent: 0 });
            }
            return wpJson(out);
        }
        if (!termM[2] && method === 'POST') {
            let body = {};
            try { body = await request.json(); } catch (e) { return wpErr('rest_invalid_json', '无效 JSON', 400); }
            const tName = String(body.name || '').trim();
            if (!tName) return wpErr('rest_invalid_param', '名称不能为空', 400);
            const tSlug = body.slug ? String(body.slug) : (slugify(tName) || ('c' + Date.now().toString(36)));
            await db.prepare('INSERT INTO taxonomies (type, slug, name) VALUES (?, ?, ?) ON CONFLICT(type, slug) DO UPDATE SET name = excluded.name').bind(type, tSlug, tName).run();
            const tRow = await db.prepare('SELECT rowid AS id, slug, name FROM taxonomies WHERE type = ? AND slug = ?').bind(type, tSlug).first();
            return wpJson({ id: tRow ? tRow.id : 0, count: 0, description: '', name: tName, slug: tSlug, taxonomy: type === 'tag' ? 'post_tag' : 'category', parent: 0 }, 201);
        }
        return wpErr('rest_no_route', '方法不支持', 405);
    }

    // 媒体上传：POST /wp/v2/media
    //   ① 标准 WP 形状：multipart/form-data（字段 file；title 可选）
    //   ② 简化形状：原始二进制 body（Content-Type: image/*；文件名走 X-Filename 头或 ?name=）
    // 存储沿用 /api/media 的规则（wp-content/uploads/YYYY/MM/…），只接受 jpg/png/gif/webp。
    if (route === '/media' && method === 'POST') {
        const ctype = (request.headers.get('Content-Type') || '').toLowerCase();
        let buf = null;
        let origName = '';
        let declared = '';
        if (ctype.indexOf('multipart/form-data') === 0) {
            let form;
            try { form = await request.formData(); } catch (e) { return wpErr('rest_invalid_param', '无效的 multipart 表单', 400); }
            const file = form.get('file');
            if (!file || typeof file === 'string') return wpErr('rest_invalid_param', '缺少 file 字段', 400);
            declared = String(file.type || '');
            origName = String(form.get('title') || file.name || 'upload');
            buf = await file.arrayBuffer();
        } else {
            declared = ctype;
            let nm = request.headers.get('X-Filename') || url.searchParams.get('name') || '';
            try { nm = decodeURIComponent(nm); } catch (e) { /* 原样 */ }
            origName = nm || 'upload';
            try { buf = await request.arrayBuffer(); } catch (e) { return wpErr('rest_invalid_param', '读取请求体失败', 400); }
        }
        if (!buf || buf.byteLength === 0) return wpErr('rest_invalid_param', '文件为空', 400);
        if (buf.byteLength > 10 * 1024 * 1024) return wpErr('rest_upload_file_too_big', '文件不能超过 10MB', 413);
        const head = new Uint8Array(buf.slice(0, 12));
        const sig = (function () { let t = ''; for (let i = 0; i < head.length; i++) t += String.fromCharCode(head[i]); return t; })();
        const isPng = head[0] === 0x89 && head[1] === 0x50 && head[2] === 0x4E && head[3] === 0x47;
        const isJpeg = head[0] === 0xFF && head[1] === 0xD8 && head[2] === 0xFF;
        const isGif = sig.slice(0, 4) === 'GIF8';
        const isWebp = sig.slice(0, 4) === 'RIFF' && sig.slice(8, 12) === 'WEBP';
        if (!isPng && !isJpeg && !isGif && !isWebp) return wpErr('rest_invalid_media', '只接受 jpg / png / gif / webp 图片', 400);
        const ext = isPng ? 'png' : (isGif ? 'gif' : (isWebp ? 'webp' : 'jpg'));
        const mediaType = isPng ? 'image/png' : (isGif ? 'image/gif' : (isWebp ? 'image/webp' : 'image/jpeg'));
        const base = String(origName).replace(/\.[^.]*$/, '').replace(/[^\w\u4e00-\u9fa5.-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'image';
        const now = new Date();
        const y = now.getUTCFullYear(), mm = String(now.getUTCMonth() + 1).padStart(2, '0');
        const key = 'wp-content/uploads/' + y + '/' + mm + '/' + Date.now().toString(36) + '-' + base + '.' + ext;
        try {
            await env.R2_BUCKET.put(key, buf, { httpMetadata: { contentType: mediaType } });
        } catch (e) {
            console.error('wpv2 media put failed:', e);
            return wpErr('rest_upload_error', '上传失败', 500);
        }
        const mediaUrl = (site.url || '') + '/' + key;
        console.log('[wpv2] media uploaded ' + key + ' (' + buf.byteLength + 'B) by ' + user.username);
        return wpJson({
            id: Date.now(),
            date: new Date().toISOString().replace('Z', ''),
            slug: base,
            type: 'attachment',
            link: mediaUrl,
            title: { rendered: origName },
            media_type: 'image',
            mime_type: mediaType,
            source_url: mediaUrl,
            media_details: { file: key, width: 0, height: 0 },
        }, 201);
    }

    // 文章集合
    if (route === '/posts') {
        if (method === 'GET') {
            const per = Math.min(100, Math.max(1, parseInt(url.searchParams.get('per_page') || '10', 10) || 10));
            const page = Math.max(1, parseInt(url.searchParams.get('page') || '1', 10) || 1);
            const search = url.searchParams.get('search') || '';
            const statusQ = url.searchParams.get('status') || 'publish';
            const conds = [], vals = [];
            if (statusQ && statusQ !== 'any') { conds.push('status = ?'); vals.push(wpInternalStatus(statusQ)); }
            if (search) { conds.push('(title LIKE ? OR content LIKE ?)'); vals.push('%' + search + '%', '%' + search + '%'); }
            const where = conds.length ? (' WHERE ' + conds.join(' AND ')) : '';
            const rows = await db.prepare('SELECT * FROM posts' + where + ' ORDER BY date DESC, wp_id DESC LIMIT ? OFFSET ?').bind(...vals, per, (page - 1) * per).all();
            const cnt = await db.prepare('SELECT COUNT(*) AS c FROM posts' + where).bind(...vals).first();
            const total = parseInt((cnt && cnt.c) || 0, 10);
            const out = [];
            for (const r of (rows.results || [])) out.push(await wpPostShape(db, r, site));
            return new Response(JSON.stringify(out), {
                status: 200,
                headers: {
                    'Content-Type': 'application/json; charset=UTF-8',
                    'X-WP-Total': String(total),
                    'X-WP-TotalPages': String(Math.ceil(total / per) || 1),
                },
            });
        }
        if (method === 'POST') {
            let body = {};
            try { body = await request.json(); } catch (e) { return wpErr('rest_invalid_json', '无效 JSON', 400); }
            const p = wpPayloadFromRest(body);
            if (p.error) return wpErr('rest_invalid_param', p.error, 400);
            const catsIn = await wpResolveTerms(db, 'category', body.categories);
            const tagsIn = await wpResolveTerms(db, 'tag', body.tags);
            p.categories = catsIn === null ? [] : catsIn;
            p.tags = tagsIn === null ? [] : tagsIn;
            let finalSlug = p.slug;
            for (let tries = 1; tries <= 20; tries++) {
                const hit = await db.prepare('SELECT wp_id FROM posts WHERE slug = ?').bind(finalSlug).first();
                if (!hit) break;
                finalSlug = p.slug + '-' + (tries + 1);
            }
            const wpId = Date.now() * 100 + Math.floor(Math.random() * 100);
            const dt = localDateTime(site.timezoneOffset || 0);
            const catJson = JSON.stringify(withDefaultCategory(p.categories));
            const tagJson = JSON.stringify(p.tags || []);
            await db.prepare('INSERT INTO posts (wp_id, slug, title, content, content_format, excerpt, date, modified, status, categories, tags, featured_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                .bind(wpId, finalSlug, p.title, p.content, p.content_format, p.excerpt, p.date || dt, dt, p.status, catJson, tagJson, p.featured_image).run();
            await db.prepare('DELETE FROM post_taxonomy WHERE post_id = ?').bind(wpId).run();
            const cats = await syncTaxonomy(db, wpId, 'category', withDefaultCategory(p.categories));
            const tags = await syncTaxonomy(db, wpId, 'tag', p.tags);
            if (cats.length || tags.length) {
                await db.prepare('UPDATE posts SET categories = ?, tags = ? WHERE wp_id = ?').bind(JSON.stringify(cats), JSON.stringify(tags), wpId).run();
            }
            if (p.status === 'published') {
                const rowPub = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(wpId).first();
                if (rowPub) await writeSinglePage(env, rowPub);
                await purgeLists(env);
            }
            const created = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(wpId).first();
            return wpJson(await wpPostShape(db, created, site), 201);
        }
        return wpErr('rest_no_route', '方法不支持', 405);
    }

    // 文章单项
    const pm = route.match(/^\/posts\/(\d+)$/);
    if (pm) {
        const id = parseInt(pm[1], 10);
        const row = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(id).first();
        if (!row) return wpErr('rest_post_invalid_id', '文章不存在：' + id, 404);
        if (method === 'GET') return wpJson(await wpPostShape(db, row, site));
        // 宽容：POST /wp/v2/posts/<id> 也视为更新。
        // WP 官方 REST 用 PUT/PATCH，但不少客户端（含自建 Obsidian 插件）习惯对带 id 的 URL 直接 POST。
        if (method === 'POST' || method === 'PUT' || method === 'PATCH') {
            let body = {};
            try { body = await request.json(); } catch (e) { return wpErr('rest_invalid_json', '无效 JSON', 400); }
            const p = wpPayloadFromRest(body, row);
            if (p.error) return wpErr('rest_invalid_param', p.error, 400);
            const catsIn = await wpResolveTerms(db, 'category', body.categories);
            const tagsIn = await wpResolveTerms(db, 'tag', body.tags);
            if (p.slug !== row.slug) {
                const clash = await db.prepare('SELECT wp_id FROM posts WHERE slug = ? AND wp_id != ?').bind(p.slug, id).first();
                if (clash) return wpErr('rest_slug_conflict', 'slug 已被其它文章占用：' + p.slug, 409);
            }
            await db.prepare('DELETE FROM post_taxonomy WHERE post_id = ?').bind(id).run();
            const cats = await syncTaxonomy(db, id, 'category', withDefaultCategory(catsIn === null ? parseTaxoList(row.categories) : catsIn));
            const tags = await syncTaxonomy(db, id, 'tag', tagsIn === null ? parseTaxoList(row.tags) : tagsIn);
            await db.prepare('UPDATE posts SET slug = ?, title = ?, content = ?, content_format = ?, excerpt = ?, modified = ?, status = ?, categories = ?, tags = ?, featured_image = ? WHERE wp_id = ?')
                .bind(p.slug, p.title, p.content, p.content_format, p.excerpt, localDateTime(site.timezoneOffset || 0), p.status, JSON.stringify(cats), JSON.stringify(tags), p.featured_image, id).run();
            const newRow = await db.prepare('SELECT * FROM posts WHERE wp_id = ?').bind(id).first();
            if (row.status === 'published' && row.slug !== p.slug) await deleteSinglePage(env, row);
            if (p.status === 'published' && newRow) await writeSinglePage(env, newRow);
            if (p.status === 'draft') await deleteSinglePage(env, newRow || row);
            await purgeLists(env);
            return wpJson(await wpPostShape(db, newRow, site));
        }
        if (method === 'DELETE') {
            await db.prepare('DELETE FROM post_taxonomy WHERE post_id = ?').bind(id).run();
            await db.prepare('DELETE FROM posts WHERE wp_id = ?').bind(id).run();
            await db.prepare('DELETE FROM taxonomies WHERE slug NOT IN (SELECT DISTINCT taxonomy_slug FROM post_taxonomy)').run();
            await deleteSinglePage(env, row);
            await purgeLists(env);
            return wpJson({ deleted: true, previous: { id: row.wp_id, slug: row.slug, title: { rendered: row.title } } });
        }
        return wpErr('rest_no_route', '方法不支持', 405);
    }

    return wpErr('rest_no_route', '未找到路由：' + raw, 404);
}

