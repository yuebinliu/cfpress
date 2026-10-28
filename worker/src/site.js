/**
 * site.js —— 站点上下文 + 缓存策略，供 routes 渲染与 admin 单页生成共用
 *
 * 数据来源：D1 settings 表两行（k='site' 站点信息 / k='cache' 缓存策略），
 * 一次查询读回后打包成同一个 KV 值（site:ctx），动态页每请求仅 1 次 KV 读。
 * 任一侧变更（WP 设置同步 / Worker 后台保存）都会清 site:ctx，下个请求即生效。
 *
 * 缓存策略为"纯 TTL"语义（由 cacheconfig/管理后台消费）：
 *   ttl_list=0    → 列表页直查 D1 不缓存（>0 才走 KV）
 *   ttl_sidebar=0 → 侧边栏直查 D1 不缓存
 *   ttl_site=0    → 站点上下文本身也不缓存（每请求直查 D1）
 *   ttl_feed=0    → RSS feed 不缓存（>0 走 KV，默认 4 小时；发布不主动清）
 *   feed_count    → RSS 输出条数（默认 20，1-100）
 *   purge_on_publish → 发布文章时是否清列表/侧边栏 KV（默认 false，省 KV 写）
 */

const DEFAULT_CACHE = {
    ttl_list: 0,        // 列表页：0=直查 D1（默认，最省 KV 写）
    ttl_sidebar: 0,     // 侧边栏：0=直查 D1
    ttl_site: 604800,   // 站点上下文 KV TTL：默认 7 天（读极频繁、写极少，值得缓存）
    ttl_feed: 14400,    // RSS feed KV TTL：默认 4 小时（低频发现通道；发布文章不主动清）
    feed_count: 20,     // RSS 输出条数（1-100）
    per_page: 10,       // 列表页每页文章数（1-100；首页/分类/标签/归档/搜索共用）
    purge_on_publish: false,
    task_batch: 20,       // 批量任务：每次请求处理条数（重建单页 / permalink 迁移共用）
    task_concurrency: 4,  // 批量任务：一批内同时处理几条
};

/** 归一化缓存配置（纯 TTL；兼容旧版 {mode:'d1'|'kv'} 数据） */
export function normalizeCache(raw) {
    const out = Object.assign({}, DEFAULT_CACHE);
    if (raw && typeof raw === 'object') {
        if (raw.mode === 'd1') { out.ttl_list = 0; out.ttl_sidebar = 0; }
        else if (raw.mode === 'kv') { out.ttl_list = 3600; out.ttl_sidebar = 86400; }
        ['ttl_list', 'ttl_sidebar', 'ttl_site', 'ttl_feed'].forEach(function (k) {
            if (raw[k] !== undefined) {
                const n = parseInt(raw[k], 10);
                if (!isNaN(n) && n >= 0) out[k] = n;
            }
        });
        if (raw.feed_count !== undefined) {
            const n = parseInt(raw.feed_count, 10);
            if (!isNaN(n) && n >= 1) out.feed_count = Math.min(n, 100);
        }
        if (raw.per_page !== undefined) {
            const n = parseInt(raw.per_page, 10);
            if (!isNaN(n) && n >= 1) out.per_page = Math.min(n, 100);
        }
        if (raw.purge_on_publish !== undefined) out.purge_on_publish = !!raw.purge_on_publish;
        if (raw.task_batch !== undefined) {
            const n = parseInt(raw.task_batch, 10);
            if (!isNaN(n) && n >= 1) out.task_batch = Math.min(n, 100);
        }
        if (raw.task_concurrency !== undefined) {
            const n = parseInt(raw.task_concurrency, 10);
            if (!isNaN(n) && n >= 1) out.task_concurrency = Math.min(n, 16);
        }
    }
    return out;
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// ── 请求级 / 短 TTL 内存缓存（D9：KV 读只在 miss 后，且一次渲染只读 1 次）──────────────────
// site:ctx 是**全局站点上下文**（站点信息 + 缓存策略 + 风格指针一次打包），
// 渲染流里 getSiteContext / getCacheConfig / tplstore 的 4 个 getter 都会调它。
// 之前每次调用都独立 KV.get('site:ctx') → 一个请求重复读 4~6 次同一键（纯浪费读配额）。
// 现在：同一个 isolate 内、TTL 内所有调用只发 1 次 KV.get，其余命中内存。
// 语义不变：site:ctx 本就有 KV 最终一致性 ~60s，加几秒内存延迟无感；
// 配置/风格变更路径会调 clearSiteCtxMemo()（见 cacheconfig.resetCacheConfig）保证即时生效。
const SITE_MEMO_TTL = 30000; // 30s：site:ctx 是「站点设置」快照（唯一仍用 KV 的项）。
                            // 拉长内存命中窗口 ⇒ KV 读量降一个数量级；配置变更路径都会调
                            // clearSiteCtxMemo()（本 isolate 立即生效），跨 isolate 最迟 30s（KV 本身传播也要 ~60s）。
let siteCtxMemo = null;     // { ctx, exp }
export function clearSiteCtxMemo() {
    siteCtxMemo = null;
}

export async function getSiteContext(env) {
    const now = Date.now();
    if (siteCtxMemo && siteCtxMemo.exp > now) return siteCtxMemo.ctx;

    const cacheKey = 'site:ctx';
    try {
        const cached = await env.KV_CACHE.get(cacheKey).then(function (v) {
            if (!v) return null;
            try { return JSON.parse(v); } catch (e) { return null; }
        });
        if (cached) {
            if (!cached.cache) cached.cache = normalizeCache(null); // 旧缓存值防御
            siteCtxMemo = { ctx: cached, exp: now + SITE_MEMO_TTL };
            return cached;
        }
    } catch (e) { /* 忽略缓存读取失败 */ }
    let ctx = { name: '', url: '', logo: '', footerHtml: '', storagePattern: '{slug}/index.html' };
    let cacheRaw = null;
    let styleRaw = null;
    try {
        const rows = await env.D1_DATABASE.prepare("SELECT k, v FROM settings WHERE k IN ('site','cache','style')").all();
        for (const r of (rows.results || [])) {
            if (r.k === 'site' && r.v) {
                try {
                    const parsed = JSON.parse(r.v);
                    ctx.name = String(parsed.name || '');
                    ctx.url = String(parsed.url || '').replace(/\/+$/, '');
                    if (parsed.description !== undefined) ctx.description = String(parsed.description);
                    if (parsed.storagePattern) ctx.storagePattern = String(parsed.storagePattern);
                    if (parsed.timezoneOffset !== undefined) ctx.timezoneOffset = parseInt(parsed.timezoneOffset, 10) || 0;
                    // 评论配置（route A：embed=注入第三方片段；native=B 阶段的骨架渲染）
                    if (parsed.comments && typeof parsed.comments === 'object') {
                        ctx.comments = {
                            mode:   String(parsed.comments.mode || 'off'),
                            embed:  String(parsed.comments.embed || ''),
                            accept: String(parsed.comments.accept || 'off'),
                        };
                    }
                    if (parsed.defaultAuthor && typeof parsed.defaultAuthor === 'object') {
                        ctx.defaultAuthor = { name: String(parsed.defaultAuthor.name || ''), slug: String(parsed.defaultAuthor.slug || '') };
                    }
                    // 站点 logo（{{SITE_LOGO}}）：WP 侧 sync_site_info() 写入；空 ⇒ 模板里 {{#IF_LOGO}} 整块隐藏
                    if (parsed.logo !== undefined) ctx.logo = String(parsed.logo || '');
                    // 站点横幅横条图（{{SITE_BANNER}}）：后台可配；空 ⇒ 模板里 {{#IF_BANNER}} 整块隐藏
                    if (parsed.banner !== undefined) ctx.banner = String(parsed.banner || '');
                    // 社交账号（{{SOCIAL_LINKS}}）：[{service,url}]；空 ⇒ {{#IF_SOCIAL}} 整块隐藏
                    if (Array.isArray(parsed.social)) {
                        ctx.social = parsed.social.map(function (s) {
                            return { service: String((s && s.service) || ''), url: String((s && s.url) || '') };
                        }).filter(function (s) { return s.url !== ''; });
                    }
                    // 主导航（{{MENU}}）：CMS 后台独立维护的菜单树（与 WP 后台菜单不联动）
                    // [{title, type, target, children:[…]}]，type = custom|category|tag|post
                    if (Array.isArray(parsed.menu)) { ctx.menu = parsed.menu; }
                    // 单页布局开关：singleSidebar = 'show'（默认，双列）| 'hide'（单列：隐藏侧边栏 + 主内容占满）
                    // 只在**渲染时**（按需渲染单页）用来决定是否注入一段 <style> ⇒ 产出的 HTML 就是最终形态。
                    if (parsed.layout && typeof parsed.layout === 'object') {
                        ctx.layout = { singleSidebar: String(parsed.layout.singleSidebar || 'show') };
                    }
                    // head 自定义 HTML（{{HEAD_EXTRA}} / 渲染出口自动注入 </head> 前）：
                    // 管理员在 Worker 后台填的任意 head 片段（AdSense 验证 meta / 统计 / 字体…），原样输出
                    if (parsed.headExtra !== undefined) { ctx.headExtra = String(parsed.headExtra || ''); }
                    // 文档库映射（{{DOC_*}} 渲染的数据源）：权威存 D1 site 行，site:ctx 是快照
                    // [{abc, xyz, styleId, docNav, docIndex}]（见 docs/document-library.md）
                    if (Array.isArray(parsed.docRoutes)) { ctx.docRoutes = parsed.docRoutes; }
                } catch (e) {}
            } else if (r.k === 'cache' && r.v) {
                try { cacheRaw = JSON.parse(r.v); } catch (e) {}
            } else if (r.k === 'style' && r.v) {
                try { styleRaw = JSON.parse(r.v); } catch (e) {}
            }
        }
    } catch (e) { console.error('getSiteContext error:', e); }
    ctx.cache = normalizeCache(cacheRaw);
    // 风格**生效指针**（属「站点设置」：D1 settings k='style'.active）
    // 见 docs/style-refactor.md（废除 tpl:index / tpl:sindex / tpl:active）
    ctx.activeStyleId = (styleRaw && typeof styleRaw.active === 'string') ? styleRaw.active : '';
    // 注：风格**清单**（内置 assets index.json + 用户 k='style'.user 合并）**不进 site:ctx** ——
    //     它由 assets / D1 派生，改用下面的 getStyleIndex()（独立 isolate 短缓存 60s）。
    //     好处：① site:ctx 是纯「站点设置」；② 部署新增/删除内置风格后**无需清 KV**（≤60s 自动跟上）。
    if (ctx.url) {
        ctx.footerHtml = '<div style="text-align:center;color:#666;font-size:12px;padding:20px 0;border-top:1px solid #eee;margin-top:30px;"><a href="' + esc(ctx.url) + '/">' + esc(ctx.name) + '</a> · Cloudflare-native CMS</div>';
    }
    // ttl_site=0 → 不缓存（每请求直查 D1）；>0 按配置 TTL 写 KV
    if (ctx.cache.ttl_site > 0) {
        try { await env.KV_CACHE.put(cacheKey, JSON.stringify(ctx), { expirationTtl: ctx.cache.ttl_site }); } catch (e) {}
    }
    // KV miss 重建出的 ctx 同样进内存缓存（同请求后续调用不再查 KV/D1）
    siteCtxMemo = { ctx: ctx, exp: now + SITE_MEMO_TTL };
    return ctx;
}

// ── 风格清单（内置 = assets index.json；用户 = D1 k='style'.user）────────────────
// 与 site:ctx 解耦：60s isolate 短缓存（部署换风格包后无需清 KV 即自动跟上）。
const STYLE_IDX_TTL = 60000;
let styleIdxMemo = null;   // { list, exp }
export function clearStyleIndexMemo() { styleIdxMemo = null; }

export async function getStyleIndex(env) {
    const now = Date.now();
    if (styleIdxMemo && styleIdxMemo.exp > now) return styleIdxMemo.list;
    let userStyles = [];
    try {
        const row = await env.D1_DATABASE.prepare("SELECT v FROM settings WHERE k = 'style'").first();
        if (row && row.v) {
            const o = JSON.parse(row.v);
            if (o && Array.isArray(o.user)) {
                userStyles = o.user.map(function (x) {
                    return { id: String((x && x.id) || ''), name: String((x && x.name) || ''), source: 'user' };
                }).filter(function (x) { return x.id; });
            }
        }
    } catch (e) { /* 无 style 行（新库） */ }
    let builtinStyles = [];
    try {
        if (env.ASSETS) {
            const bres = await env.ASSETS.fetch(new Request('https://assets.local/wp-content/themes/index.json'));
            if (bres && bres.ok) {
                const arr = await bres.json();
                if (Array.isArray(arr)) builtinStyles = arr.map(function (x) {
                    return { id: String((x && x.id) || ''), name: String((x && x.name) || ''), updated: String((x && x.updated) || ''), source: 'builtin' };
                }).filter(function (x) { return x.id; });
            }
        }
    } catch (e) { /* 无内置清单（新库 / 未部署） */ }
    const uids = new Set(userStyles.map(function (x) { return x.id; }));
    const list = builtinStyles.filter(function (x) { return !uids.has(x.id); }).concat(userStyles);
    styleIdxMemo = { list: list, exp: now + STYLE_IDX_TTL };
    return list;
}

/** 站点级 HTML 转义（供 site 上下文内拼 footer 等） */
export function siteEsc(s) {
    return esc(s);
}
