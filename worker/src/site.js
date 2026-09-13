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

export async function getSiteContext(env) {
    const cacheKey = 'site:ctx';
    try {
        const cached = await env.KV_CACHE.get(cacheKey).then(function (v) {
            if (!v) return null;
            try { return JSON.parse(v); } catch (e) { return null; }
        });
        if (cached) {
            if (!cached.cache) cached.cache = normalizeCache(null); // 旧缓存值防御
            return cached;
        }
    } catch (e) { /* 忽略缓存读取失败 */ }
    let ctx = { name: '', url: '', footerHtml: '', storagePattern: '{slug}/index.html' };
    let cacheRaw = null;
    try {
        const rows = await env.D1_DATABASE.prepare("SELECT k, v FROM settings WHERE k IN ('site','cache')").all();
        for (const r of (rows.results || [])) {
            if (r.k === 'site' && r.v) {
                try {
                    const parsed = JSON.parse(r.v);
                    ctx.name = String(parsed.name || '');
                    ctx.url = String(parsed.url || '').replace(/\/+$/, '');
                    if (parsed.description !== undefined) ctx.description = String(parsed.description);
                    if (parsed.storagePattern) ctx.storagePattern = String(parsed.storagePattern);
                    if (parsed.timezoneOffset !== undefined) ctx.timezoneOffset = parseInt(parsed.timezoneOffset, 10) || 0;
                } catch (e) {}
            } else if (r.k === 'cache' && r.v) {
                try { cacheRaw = JSON.parse(r.v); } catch (e) {}
            }
        }
    } catch (e) { console.error('getSiteContext error:', e); }
    ctx.cache = normalizeCache(cacheRaw);
    if (ctx.url) {
        ctx.footerHtml = '<div style="text-align:center;color:#666;font-size:12px;padding:20px 0;border-top:1px solid #eee;margin-top:30px;"><a href="' + esc(ctx.url) + '/">' + esc(ctx.name) + '</a> · CF Static Export Pro</div>';
    }
    // ttl_site=0 → 不缓存（每请求直查 D1）；>0 按配置 TTL 写 KV
    if (ctx.cache.ttl_site > 0) {
        try { await env.KV_CACHE.put(cacheKey, JSON.stringify(ctx), { expirationTtl: ctx.cache.ttl_site }); } catch (e) {}
    }
    return ctx;
}

/** 站点级 HTML 转义（供 site 上下文内拼 footer 等） */
export function siteEsc(s) {
    return esc(s);
}
