/**
 * 路由规则 - 插件自动生成源模板 routes.template.js
 * 文件位置: worker/routes.template.js
 * 编辑此文件后，点击"初始化部署"即可生效
 *
 * 占位符说明：
 * {{ROUTE_PATTERN}}    - WordPress固定链接路由正则
 * {{STORAGE_PATTERN}}  - R2存储路径模板
 * {{SITE_URL}}         - 静态站点URL
 * {{CFSE_PER_PAGE}}    - 列表每页文章数（WP 设置页配置）
 */
import { getFromR2 } from './storage';
import { getPostsByTaxonomy, getTaxonomyInfo, getRecentPosts, getPostsByArchive, getSidebarData, searchPosts } from './database';
import { renderCategoryPage, renderTagPage, renderHomepage, renderArchivePage } from './templates';
import { getCache, setCache, deleteCache, purgeCache } from './cache';
import { getActiveTplContent } from './tplstore';
import { handleAdminRequest, handleWpV2 } from './admin';
import { getSiteContext } from './site';
import { getCacheConfig } from './cacheconfig';
import { mdToHtml } from './md';

const CFSE_PER_PAGE = {{CFSE_PER_PAGE}};

/* ---------- X-Auth-Key 写通道：共享限速 ----------
 * /purge-cache /style-list /builtin-sync /d1-batch /upload /delete 这些接口不走登录，
 * 只靠单一 X-Auth-Key。这里加一层「按来源 IP」的内存滑窗限速（isolate 级，0 KV 成本），
 * 避免 key 泄漏后被无限刷。
 * 额度 600 次/分钟：批量导出（默认 4 并发、每篇数个请求）峰值约 2~8 次/秒，留足余量。
 * 注：校验仍分散在各接口处（此处只做限速，不改动它们的鉴权逻辑）。
 */
const XAUTH_RATE_LIMIT = 600;
const xauthRateMap = new Map();

function xauthRateAllowed(ip) {
    const now = Date.now();
    const k = String(ip || 'unknown');
    let e = xauthRateMap.get(k);
    if (!e || now >= e.resetAt) {
        if (xauthRateMap.size > 256) {
            xauthRateMap.forEach(function (v, kk) { if (now >= v.resetAt) xauthRateMap.delete(kk); });
        }
        e = { n: 0, resetAt: now + 60000 };
        xauthRateMap.set(k, e);
    }
    e.n += 1;
    return e.n <= XAUTH_RATE_LIMIT;
}

const CACHE_TTL = {
    STATIC: 31536000,
    HTML: 3600,
    DYNAMIC: 300,
    ERROR: 60,
};

/**
 * 列表页统一缓存入口（纯 TTL 语义）。
 * ttl_list=0：直接渲染返回（不写 KV，最省配额）；ttl_list>0：KV 命中返回，未命中渲染并按 TTL 写 KV。
 */
async function listCached(env, cacheKey, buildFn) {
    const cfg = await getCacheConfig(env);
    if (!(cfg.ttl_list > 0)) return { hit: false, html: await buildFn() };
    const cached = await getCache(env.KV_CACHE, cacheKey);
    if (cached) return { hit: true, html: cached };
    const html = await buildFn();
    await setCache(env.KV_CACHE, cacheKey, html, cfg.ttl_list);
    return { hit: false, html: html };
}

export async function handleRequest(request, env) {
    const url = new URL(request.url);
    const path = url.pathname;

    // 健康检查（JSON + D1 探活）
    if (path === '/health') {
        let dbOk = true;
        try { await env.D1_DATABASE.prepare('SELECT 1 AS ok').first(); } catch (e) { dbOk = false; }
        const body = JSON.stringify({ ok: dbOk, db: dbOk ? 'up' : 'down', ts: new Date().toISOString() });
        return new Response(body, {
            status: dbOk ? 200 : 503,
            headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' },
        });
    }

    // X-Auth-Key 写通道统一限速（在各接口各自鉴权之前；防 key 泄漏后被无限刷）
    if (request.headers.get('X-Auth-Key')) {
        const xauthIp = request.headers.get('cf-connecting-ip') || request.headers.get('x-forwarded-for') || 'unknown';
        if (!xauthRateAllowed(xauthIp)) {
            return new Response(JSON.stringify({ error: 'too many requests' }), {
                status: 429,
                headers: { 'Content-Type': 'application/json', 'Retry-After': '60' },
            });
        }
    }

    // favicon：无站点图标，静默 204 避免 404 噪音
    if (path === '/favicon.ico' || path === '/favicon.png') {
        return new Response(null, { status: 204 });
    }

    // 清除缓存（导出文章后由插件调用）：body 传 { keys: [...], prefixes: [...] }
    // 开关在 WP 设置页：cf_purge_sidebar（sidebar 即时清）/ cf_purge_lists（列表页第一页即时清）
    if (path === '/purge-cache' && request.method === 'POST') {
        const providedKey = request.headers.get('X-Auth-Key');
        const validKey = env.AUTH_KEY_SECRET;
        if (!providedKey || !validKey || providedKey !== validKey) {
            return new Response('Unauthorized', { status: 401 });
        }
        let payload = {};
        try { payload = await request.json(); } catch (e) { payload = {}; }
        const keys = Array.isArray(payload.keys) ? payload.keys : [];
        const prefixes = Array.isArray(payload.prefixes) ? payload.prefixes : [];
        let removed = 0;
        for (const k of keys) { if (await deleteCache(env.KV_CACHE, String(k))) removed++; }
        for (const p of prefixes) { if (await purgeCache(env.KV_CACHE, String(p))) removed++; }
        return new Response(JSON.stringify({ success: true, removed: removed }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    // 风格资源清单（WP 端「同步风格资源到 static」用；Auth 校验同 /purge-cache）
    if (path === '/style-list' && request.method === 'GET') {
        const providedKey = request.headers.get('X-Auth-Key');
        const validKey = env.AUTH_KEY_SECRET;
        if (!providedKey || !validKey || providedKey !== validKey) {
            return new Response('Unauthorized', { status: 401 });
        }
        const list = await env.R2_BUCKET.list({ prefix: 'style/' });
        const items = (list.objects || []).map(function (o) { return { key: o.key, size: o.size }; });
        return new Response(JSON.stringify({ items: items }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    // 文章清单（WP 端「从 Worker 拉取文章」用；X-Auth-Key 鉴权，不走登录）
    //   GET /sync/posts?limit=200&since=YYYY-MM-DD HH:MM:SS&min_wp_id=0&wp_id=0
    //   - since：增量拉取（modified > since）
    //   - min_wp_id：只取 wp_id >= 该值的文章（WP 端用 1e11 过滤「Worker 端诞生」的篇，见 ROADMAP I.1）
    //   返回按 wp_id 升序；WP 端用 get_post(wp_id) 判断是否已存在 ⇒ 天然幂等，不会重复建文。
    if (path === '/sync/posts' && request.method === 'GET') {
        const providedKey = request.headers.get('X-Auth-Key');
        const validKey = env.AUTH_KEY_SECRET;
        if (!providedKey || !validKey || providedKey !== validKey) {
            return new Response('Unauthorized', { status: 401 });
        }
        const url = new URL(request.url);
        const limitRaw = parseInt(url.searchParams.get('limit') || '200', 10);
        const limit = Math.max(1, Math.min(1000, isNaN(limitRaw) ? 200 : limitRaw));
        const since = (url.searchParams.get('since') || '').trim();
        const minIdRaw = parseInt(url.searchParams.get('min_wp_id') || '0', 10);
        const minId = isNaN(minIdRaw) || minIdRaw < 0 ? 0 : minIdRaw;

        const cols = 'wp_id, slug, title, content, content_format, excerpt, date, modified, status, categories, tags, featured_image';
        const wpIdRaw = parseInt(url.searchParams.get('wp_id') || '0', 10);
        const wpId = isNaN(wpIdRaw) || wpIdRaw <= 0 ? 0 : wpIdRaw;

        const conds = [];
        const params = [];
        if (wpId > 0) { conds.push('wp_id = ?'); params.push(wpId); }
        if (since) { conds.push('modified > ?'); params.push(since); }
        if (minId > 0) { conds.push('wp_id >= ?'); params.push(minId); }
        let sql = 'SELECT ' + cols + ' FROM posts';
        if (conds.length) sql += ' WHERE ' + conds.join(' AND ');
        sql += ' ORDER BY wp_id ASC LIMIT ?';
        params.push(limit);

        let rows = [];
        try {
            const res = await env.D1_DATABASE.prepare(sql).bind(...params).all();
            rows = (res && res.results) || [];
        } catch (e) {
            return new Response(JSON.stringify({ error: 'D1 查询失败: ' + (e && e.message ? e.message : String(e)) }), {
                status: 500,
                headers: { 'Content-Type': 'application/json' },
            });
        }
        return new Response(JSON.stringify({ ok: true, total: rows.length, limit: limit, since: since, min_wp_id: minId, posts: rows }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    // 内置风格索引同步（WP 端「快照为新风格」后调用；X-Auth-Key 鉴权，不走登录）
    if (path === '/api/templates/style/builtin-sync' && request.method === 'POST') {
        const providedKey = request.headers.get('X-Auth-Key');
        const validKey = env.AUTH_KEY_SECRET;
        if (!providedKey || !validKey || providedKey !== validKey) {
            return new Response(JSON.stringify({ error: 'Unauthorized' }), { status: 401, headers: { 'Content-Type': 'application/json' } });
        }
        let payload = {};
        try { payload = await request.json(); } catch (e) { payload = {}; }
        const items = Array.isArray(payload.styles) ? payload.styles : [];
        const clean = items.map(function (x) {
            return { id: String(x.id || '').slice(0, 60), name: String(x.name || x.id || '').slice(0, 60), updated: String(x.updated || '').slice(0, 19), source: 'builtin', current: !!x.current };
        }).filter(function (x) { return x.id; });
        // 内置包索引：唯一键 tpl:index（部署时 scripts/sync-themes-index.mjs 扫描注入）
        await env.KV_CACHE.put('tpl:index', JSON.stringify(clean));
        return new Response(JSON.stringify({ ok: true, count: clean.length }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    // 批量执行 D1（插件导出时把整篇文章的 SQL 打包成一次请求；Auth 校验同上传接口）
    if (path === '/d1-batch' && request.method === 'POST') {
        const providedKey = request.headers.get('X-Auth-Key');
        const validKey = env.AUTH_KEY_SECRET;
        if (!providedKey || !validKey || providedKey !== validKey) {
            return new Response('Unauthorized', { status: 401 });
        }
        let payload = {};
        try { payload = await request.json(); } catch (e) { payload = {}; }
        const statements = Array.isArray(payload.statements) ? payload.statements.slice(0, 200) : [];
        const results = [];
        for (let i = 0; i < statements.length; i++) {
            const st = statements[i] || {};
            const sql = typeof st.sql === 'string' ? st.sql : '';
            const params = Array.isArray(st.params) ? st.params : [];
            if (!sql) { results.push(null); continue; }
            try {
                const stmt = env.D1_DATABASE.prepare(sql);
                const r = params.length ? await stmt.bind(...params).all() : await stmt.all();
                results.push(r.results || null);
            } catch (e) {
                console.error('d1-batch stmt #' + i + ' failed:', e);
                return new Response(JSON.stringify({ success: false, error: String((e && e.message) || e), index: i }), {
                    status: 500,
                    headers: { 'Content-Type': 'application/json' },
                });
            }
        }
        return new Response(JSON.stringify({ success: true, results: results }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    // 上传/删除管理接口
    if (path === '/upload' && request.method === 'POST') {
        return await handleUpload(request, env);
    }
    if (path === '/delete' && (request.method === 'POST' || request.method === 'DELETE')) {
        return await handleDelete(request, env);
    }

    // 静态资源（图片/css/js/字体等）
    if (path.match(/\.(css|js|png|jpg|jpeg|gif|svg|webp|ico|woff2?|ttf|eot)$/)) {
        const file = await getFromR2(env.R2_BUCKET, path.replace(/^\//, ''));
        if (file) {
            return new Response(file.body, {
                headers: {
                    'Content-Type': file.contentType || getContentType(path),
                    'Cache-Control': `public, max-age=${CACHE_TTL.STATIC}, immutable`,
                }
            });
        }
        if (env.WORDPRESS_URL) {
            const response = await fetch(env.WORDPRESS_URL + path);
            if (response.ok) return response;
        }
        return new Response('Not Found', { status: 404 });
    }

    // ============================================
    // 管理后台 API（/api/*）：JWT 登录 / 文章与分类标签管理
    // ============================================
    // ============================================
    // WP REST 兼容写入口（第三方 md 编辑器：Obsidian / Ulysses / 脚本）
    //   路径：/wp-json/wp/v2/* 与 /wp/v2/*
    //   认证：Basic Auth（用户名 + 应用密码），不走 /api/* 的 JWT 中间件
    // ============================================
    if (path.startsWith('/wp-json/') || path.startsWith('/wp/v2/')) {
        return await handleWpV2(request, env);
    }

    if (path.startsWith('/api/')) {
        return await handleAdminRequest(request, env);
    }

    // ============================================
    // ============================================
    // robots.txt：静态常量（不查库、不缓存；顺带挡爬虫抓后台与 API）
    // /sitemap.xml 已废弃：原先是「按需全量生成 + KV 缓存」，文章量大时单个请求要吃下
    // 全表查询 + 全量拼接（可能撞免费档 CPU 上限），对「有 RSS + 内链完整」的站点边际
    // 价值也很低 —— 现在不拦它，让它走后面的分支自然落到 404。
    // ============================================
    if (path === '/robots.txt') {
        return new Response('User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/\n', {
            headers: {
                'Content-Type': 'text/plain; charset=utf-8',
                'Cache-Control': 'public, max-age=86400',
            }
        });
    }

    // ============================================
    // RSS Feed：/feed 或 /feed/（由 D1 生成；KV TTL = ttl_feed，默认 4 小时）
    if (path === '/feed' || path === '/feed/') {
        const cacheKey = 'rss:feed';
        const site = await getSiteContext(env);
        const ttlFeed = site.cache.ttl_feed;
        const ccFeed = ttlFeed > 0 ? ('public, max-age=' + ttlFeed) : 'no-store';
        if (ttlFeed > 0) {
            const cached = await getCache(env.KV_CACHE, cacheKey);
            if (cached) { return new Response(cached, { headers: { 'Content-Type': 'application/rss+xml; charset=utf-8', 'Cache-Control': ccFeed, 'X-Cache': 'HIT' } }); }
        }
        const data = await getRecentPosts(env.D1_DATABASE, 1, site.cache.feed_count);
        const xml = cseRssXml(site, data.posts || [], '/feed/');
        if (ttlFeed > 0) { await setCache(env.KV_CACHE, cacheKey, xml, ttlFeed); }
        return new Response(xml, { headers: { 'Content-Type': 'application/rss+xml; charset=utf-8', 'Cache-Control': ccFeed, 'X-Cache': 'MISS' } });
    }

    // ============================================
    // 搜索：/?s=关键词（页面搜索框 action="{{SITE_URL}}/" method=get）
    // 分页：/?s=关键词&paged=N（复用首页壳渲染，sidebar 动态）
    // ============================================
    const searchQuery = url.searchParams.get('s');
    if (searchQuery && String(searchQuery).trim() !== '') {
        const q = String(searchQuery).trim().slice(0, 100);
        const searchPage = parseInt(url.searchParams.get('paged') || '1', 10) || 1;
        if (searchPage < 1) return buildNotFound('页面不存在');
        const cacheKey = 'search:' + q.toLowerCase() + ':page:' + searchPage;

        const data = await searchPosts(env.D1_DATABASE, q, searchPage, CFSE_PER_PAGE);
        const sidebar = await getSidebar(env);
        const site = await getSiteContext(env);
        const emptyHtml = data.total === 0
            ? '<div class="entry-content" style="padding:20px 0;"><p>没有找到与 “' + htmlEsc(q) + '” 相关的文章，试试其它关键词。</p></div>'
            : '';
        const html = renderHomepage(data, searchPage, data.total, {
            baseUrl: '/',
            sidebar: sidebar,
            site: site,
            pageTpl: await getActiveTplContent(env, 'pageHome'),
            itemTpl: await getActiveTplContent(env, 'item'),
            emptyHtml: emptyHtml,
            paginationBase: '/?s=' + encodeURIComponent(q) + '&paged=',
        });
        const lc = await listCached(env, cacheKey, async function () { return html; });
        return new Response(lc.html, {
            headers: {
                'Content-Type': 'text/html',
                'Cache-Control': `public, max-age=${CACHE_TTL.DYNAMIC}`,
                'X-Cache': lc.hit ? 'HIT' : 'MISS',
            }
        });
    }

    // ============================================
    // 归档页：/2024/ 或 /2024/09/
    // ============================================
    const archiveMatch = path.match(/^\/(\d{4})(?:\/(\d{1,2})(?:\/page\/(\d+))?)?\/?$/);
    if (archiveMatch && archiveMatch[1].length === 4) {
        const year = archiveMatch[1];
        const month = archiveMatch[2] ? String(archiveMatch[2]).padStart(2, '0') : null;
        const page = archiveMatch[3] ? parseInt(archiveMatch[3], 10) : 1;
        const cacheKey = `archive:${year}:${month || 'all'}:page:${page}`;

        const data = await getPostsByArchive(env.D1_DATABASE, year, month, page, CFSE_PER_PAGE);
        const baseUrl = `/${year}/${month ? month + '/' : ''}`;
        const sidebar = await getSidebar(env);
        const site = await getSiteContext(env);

        if (data && (data.posts.length > 0 || page === 1)) {
            const html = renderArchivePage(data, page, data.total, {
                year: year,
                month: month,
                baseUrl: baseUrl,
                sidebar: sidebar,
                site: site,
                pageTpl: await getActiveTplContent(env, 'pageArchive'),
                itemTpl: await getActiveTplContent(env, 'item')
            });
            const lc = await listCached(env, cacheKey, async function () { return html; });
            return new Response(lc.html, {
                headers: {
                    'Content-Type': 'text/html',
                    'Cache-Control': `public, max-age=${CACHE_TTL.DYNAMIC}`,
                    'X-Cache': lc.hit ? 'HIT' : 'MISS',
                }
            });
        }

        const emptyArchiveData = {
            posts: [],
            total: 0,
            currentPage: page,
            totalPages: 0
        };
        const htmlEmpty = renderArchivePage(emptyArchiveData, page, 0, {
            year: year,
            month: month,
            baseUrl: baseUrl,
            site: site,
            pageTpl: await getActiveTplContent(env, 'pageArchive'),
            itemTpl: await getActiveTplContent(env, 'item')
        });
        return new Response(htmlEmpty, {
            headers: {
                'Content-Type': 'text/html',
                'Cache-Control': `public, max-age=${CACHE_TTL.DYNAMIC}`,
            }
        });
    }

    // ============================================
    // 分类页
    // ============================================
    if (path.startsWith('/category/')) {
        const match = path.replace(/\/$/, '').match(/^\/category\/([^/]+)(?:\/page\/(\d+))?$/);
        // 无 slug 段时不进归档分支，落到下方文章单页查找（支持 slug 为 tag/category 的文章）
        if (match) {
        const slug = match ? match[1].toLowerCase() : '';
        const page = match && match[2] ? parseInt(match[2], 10) : 1;
        const cacheKey = `category:${slug}:page:${page}`;

        const data = await getPostsByTaxonomy(env.D1_DATABASE, 'category', slug, page, CFSE_PER_PAGE);
        const taxInfo = await getTaxonomyInfo(env.D1_DATABASE, 'category', slug);
        const baseUrl = `/category/${slug}/`;
        const sidebar = await getSidebar(env);
        const site = await getSiteContext(env);

        if (data && (data.posts.length > 0 || page === 1)) {
            const html = renderCategoryPage(data, page, data.total, {
                slug: slug,
                name: taxInfo ? taxInfo.name : slug,
                baseUrl: baseUrl,
                sidebar: sidebar,
                site: site,
                pageTpl: await getActiveTplContent(env, 'pageCategory'),
                itemTpl: await getActiveTplContent(env, 'item')
            });
            const lc = await listCached(env, cacheKey, async function () { return html; });
            return new Response(lc.html, {
                headers: {
                    'Content-Type': 'text/html',
                    'Cache-Control': `public, max-age=${CACHE_TTL.DYNAMIC}`,
                    'X-Cache': lc.hit ? 'HIT' : 'MISS',
                }
            });
        }
        return buildNotFound('分类不存在');
        }
    }

    // ============================================
    // 标签页
    // ============================================
    if (path.startsWith('/tag/')) {
        const match = path.replace(/\/$/, '').match(/^\/tag\/([^/]+)(?:\/page\/(\d+))?$/);
        // 无 slug 段时不进归档分支，落到下方文章单页查找（支持 slug 为 tag/category 的文章）
        if (match) {
        const slug = match ? match[1].toLowerCase() : '';
        const page = match && match[2] ? parseInt(match[2], 10) : 1;
        const cacheKey = `tag:${slug}:page:${page}`;

        const data = await getPostsByTaxonomy(env.D1_DATABASE, 'tag', slug, page, CFSE_PER_PAGE);
        const taxInfo = await getTaxonomyInfo(env.D1_DATABASE, 'tag', slug);
        const baseUrl = `/tag/${slug}/`;
        const sidebar = await getSidebar(env);
        const site = await getSiteContext(env);

        if (data && (data.posts.length > 0 || page === 1)) {
            const html = renderTagPage(data, page, data.total, {
                slug: slug,
                name: taxInfo ? taxInfo.name : slug,
                baseUrl: baseUrl,
                sidebar: sidebar,
                site: site,
                pageTpl: await getActiveTplContent(env, 'pageTag'),
                itemTpl: await getActiveTplContent(env, 'item')
            });
            const lc = await listCached(env, cacheKey, async function () { return html; });
            return new Response(lc.html, {
                headers: {
                    'Content-Type': 'text/html',
                    'Cache-Control': `public, max-age=${CACHE_TTL.DYNAMIC}`,
                    'X-Cache': lc.hit ? 'HIT' : 'MISS',
                }
            });
        }
        return buildNotFound('标签不存在');
        }
    }

    // ============================================
    // 首页 + 分页
    // ============================================
    if (path === '/' || path.startsWith('/page/')) {
        const page = path === '/' ? 1 : parseInt(path.replace('/page/', '').replace(/\/$/, '') || '1', 10);

        if (!page || page < 1) return buildNotFound('页面不存在');

        const cacheKey = `home:page:${page}`;

        const data = await getRecentPosts(env.D1_DATABASE, page, CFSE_PER_PAGE);
        const totalPosts = data.total;
        const sidebar = await getSidebar(env);
        const site = await getSiteContext(env);
        const html = renderHomepage(data, page, totalPosts, {
            baseUrl: '/',
            sidebar: sidebar,
            site: site,
            pageTpl: await getActiveTplContent(env, 'pageHome'),
            itemTpl: await getActiveTplContent(env, 'item')
        });
        const lc = await listCached(env, cacheKey, async function () { return html; });
        return new Response(lc.html, {
            headers: {
                'Content-Type': 'text/html',
                'Cache-Control': `public, max-age=${CACHE_TTL.DYNAMIC}`,
                'X-Cache': lc.hit ? 'HIT' : 'MISS',
            }
        });
    }

    // ============================================
    // 文章单页 - 自动适配 WordPress Permalink
    // 插件会根据你的 Permalink 设置替换下面两个占位符
    // 当前模板：{{ROUTE_PATTERN}} → {{STORAGE_PATTERN}}
    // ============================================
    const postMatch = path.match({{ROUTE_PATTERN}});
    if (postMatch) {
        // slug 统一小写（WP 存储为小写；%xx 十六进制也小写），避免 URL 大写编码命中不了 R2/D1
        const slug = postMatch[postMatch.length - 1].toLowerCase();
        const basePath = `{{STORAGE_PATTERN}}`;
        let storagePath = basePath;

        if (postMatch.length >= 4) {
            storagePath = storagePath.replace('{year}', postMatch[1] || '');
            storagePath = storagePath.replace('{month}', postMatch[2] || '');
            storagePath = storagePath.replace('{day}', postMatch[3] || '');
        }
        storagePath = storagePath.replace('{slug}', slug);

        // 单页为 R2 静态文件，直接读 R2，不套 KV 缓存（省 KV 读写且避免旧缓存不同步）
        let file = await getFromR2(env.R2_BUCKET, storagePath);
        if (!file && env.ENABLE_COMPRESSION) {
            file = await getFromR2(env.R2_BUCKET, storagePath + '.gz');
            if (file && !file.contentEncoding) file.contentEncoding = 'gzip';
        }

        if (file) {
            const payload = {
                body: bytesToBase64(file.body),
                contentType: file.contentType || 'text/html',
                contentEncoding: file.contentEncoding || null,
            };
            return buildBinaryResponse(payload, 'MISS');
        }

        if (env.WORDPRESS_URL) {
            const response = await fetch(`${env.WORDPRESS_URL}${path}`);
            if (response.ok) return response;
        }
        return buildNotFound('文章不存在');
    }

    return buildNotFound('页面不存在');
}

// ============================================
// 404 响应与 HTML 转义工具
// ============================================
function htmlEsc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function cseRssXml(site, posts, selfPath) {
    const siteName = String(site.name || '').replace(/&#0?39;/g, "'").replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
    const itemsXml = (posts || []).map(function (p) {
        const u = (site.url || '') + '/' + String(p.slug || '') + '/';
        const dateStr = String(p.date || '').slice(0, 10);
        const pubDate = dateStr ? new Date(dateStr + 'T00:00:00Z').toUTCString() : new Date().toUTCString();
        const cdata = function (s) { return String(s || '').split(']]>').join(']]]]><![CDATA[>'); };
        const bodyHtml = (p.content_format || 'html') === 'md' ? mdToHtml(p.content) : String(p.content || '');
        return '<item><title>' + htmlEsc(p.title) + '</title><link>' + htmlEsc(u) + '</link><guid isPermaLink="true">' + htmlEsc(u) + '</guid><pubDate>' + pubDate + '</pubDate><description>' + htmlEsc(p.excerpt || '') + '</description><content:encoded><![CDATA[' + cdata(bodyHtml) + ']]></content:encoded></item>';
    }).join('');
    return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>' + htmlEsc(siteName) + '</title><link>' + htmlEsc(site.url) + '</link><atom:link href="' + htmlEsc((site.url || '') + selfPath) + '" rel="self" type="application/rss+xml" /><description>' + htmlEsc(site.description || siteName) + '</description><language>zh-CN</language><lastBuildDate>' + new Date().toUTCString() + '</lastBuildDate>' + itemsXml + '</channel></rss>';
}

function buildNotFound(msg) {
    const m = htmlEsc(msg || '页面不存在');
    const html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>404 - 页面不存在</title><style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#f5f6f7;color:#333;margin:0;display:flex;min-height:90vh;align-items:center;justify-content:center;padding:20px}.box{background:#fff;border-radius:12px;box-shadow:0 2px 14px rgba(0,0,0,.06);padding:40px 46px;text-align:center;max-width:560px;width:100%}h1{font-size:34px;margin:0 0 6px;color:#222}p{color:#666;margin:10px 0;line-height:1.6}a{color:#007bff;text-decoration:none;font-weight:600}form{margin:18px 0 6px}input[type=text]{padding:10px 14px;border:1px solid #ddd;border-radius:8px 0 0 8px;font-size:15px;width:62%;max-width:280px;box-sizing:border-box}button{padding:10px 18px;border:0;background:#007bff;color:#fff;border-radius:0 8px 8px 0;font-size:15px;cursor:pointer}.links{display:flex;gap:22px;justify-content:center;margin-top:14px}</style></head><body><div class="box"><h1>404</h1><p>' + m + '</p><p style="color:#aaa;">你访问的页面不存在或已移动。</p><form action="/" method="get"><input type="text" name="s" placeholder="试试搜索站内内容…"><button type="submit">搜索</button></form><div class="links"><a href="/">← 返回首页</a></div></div></body></html>';
    return new Response(html, {
        status: 404,
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'Cache-Control': 'public, max-age=60',
        },
    });
}

// ============================================
// 侧边栏数据（纯 TTL 语义：ttl_sidebar=0 直查 D1，不读写 KV）
// ============================================
async function getSidebar(env) {
    const cacheKey = 'sidebar:data';
    const cfg = await getCacheConfig(env);
    const useCache = cfg.ttl_sidebar > 0;
    if (useCache) {
        try {
            const cached = await getCache(env.KV_CACHE, cacheKey);
            if (cached) return cached;
        } catch (e) { /* 忽略缓存读取失败 */ }
    }
    const data = await getSidebarData(env.D1_DATABASE);
    if (data && useCache) {
        await setCache(env.KV_CACHE, cacheKey, data, cfg.ttl_sidebar);
    }
    return data;
}

// ============================================
// 上传处理
// ============================================
async function handleUpload(request, env) {
    const providedKey = request.headers.get('X-Auth-Key');
    const validKey = env.AUTH_KEY_SECRET;
    if (!providedKey || !validKey || providedKey !== validKey) {
        console.warn('上传请求认证失败');
        return new Response('Unauthorized', { status: 401 });
    }
    try {
        const uploadData = await request.json();
        const filePath = uploadData.path;
        const fileContent = uploadData.content;
        const contentType = uploadData.contentType || 'text/html; charset=utf-8';
        const contentEncoding = uploadData.contentEncoding || null;

        if (!filePath || typeof filePath !== 'string' || filePath.trim() === '') {
            return new Response(
                JSON.stringify({ success: false, error: 'Invalid path' }),
                { status: 400, headers: { 'Content-Type': 'application/json' } }
            );
        }
        if (!fileContent) {
            return new Response(
                JSON.stringify({ success: false, error: 'Missing content' }),
                { status: 400, headers: { 'Content-Type': 'application/json' } }
            );
        }

        let bytes;
        try {
            const binary = atob(fileContent);
            bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }
        } catch (decodeError) {
            console.warn('Base64解码失败，使用原始内容', decodeError);
            bytes = new TextEncoder().encode(String(fileContent));
        }

        const httpMetadata = { contentType };
        if (contentEncoding) {
            httpMetadata.contentEncoding = contentEncoding;
        }
        // —— 图片/文件去重：PHP 端带 body.md5；R2 已有同 path 对象且 size+etag 一致则跳过（不重复 put）——
        const md5Hex = typeof uploadData.md5 === 'string' ? uploadData.md5.toLowerCase() : '';
        if (md5Hex) {
            try {
                const existing = await env.R2_BUCKET.head(filePath);
                if (existing) {
                    const etag = String(existing.httpEtag || '').replace(/^"|"$/g, '').toLowerCase();
                    if (existing.size === bytes.byteLength && etag === md5Hex) {
                        console.log(`♻️ 内容一致，跳过上传: ${filePath}`);
                        return new Response(
                            JSON.stringify({ success: true, skipped: true, path: filePath }),
                            { status: 200, headers: { 'Content-Type': 'application/json' } }
                        );
                    }
                }
            } catch (headError) {
                console.warn('head 检查失败，继续上传:', headError);
            }
        }
        // 显式写 checksums（md5），使 R2 etag 稳定等于该 md5，后续可复判跳过
        const putOptions = { httpMetadata };
        if (md5Hex) {
            putOptions.checksums = { md5: hexToBytes(md5Hex) };
        }
        await env.R2_BUCKET.put(filePath, bytes, putOptions);
        console.log(`✅ 文件上传成功: ${filePath}`);
        return new Response(
            JSON.stringify({ success: true, skipped: false, path: filePath }),
            { status: 200, headers: { 'Content-Type': 'application/json' } }
        );
    } catch (error) {
        console.error('上传错误:', error);
        return new Response(
            JSON.stringify({ success: false, error: error.message }),
            { status: 500, headers: { 'Content-Type': 'application/json' } }
        );
    }
}

// ============================================
// 删除处理
// ============================================
async function handleDelete(request, env) {
    const providedKey = request.headers.get('X-Auth-Key');
    const validKey = env.AUTH_KEY_SECRET;
    if (!providedKey || !validKey || providedKey !== validKey) {
        console.warn('删除请求认证失败');
        return new Response('Unauthorized', { status: 401 });
    }
    try {
        const data = await request.json();
        const paths = Array.isArray(data.paths) ? data.paths : (typeof data.path === 'string' && data.path.trim() !== '' ? [data.path] : []);
        if (!paths.length) {
            return new Response(
                JSON.stringify({ success: false, error: 'Invalid path' }),
                { status: 400, headers: { 'Content-Type': 'application/json' } }
            );
        }
        let deleted = 0;
        for (const p of paths) {
            if (typeof p === 'string' && p.trim() !== '') {
                await env.R2_BUCKET.delete(p);
                deleted++;
            }
        }
        console.log(`✅ 文件删除成功 ${deleted} 个: ${paths.join(', ')}`);
        return new Response(
            JSON.stringify({ success: true, deleted: deleted, paths: paths }),
            { status: 200, headers: { 'Content-Type': 'application/json' } }
        );
    } catch (error) {
        console.error('删除错误:', error);
        return new Response(
            JSON.stringify({ success: false, error: error.message }),
            { status: 500, headers: { 'Content-Type': 'application/json' } }
        );
    }
}

// ============================================
// 二进制缓存辅助
// ============================================
function buildBinaryResponse(payload, cacheStatus = 'MISS') {
    if (!payload || typeof payload.body !== 'string') return null;
    let body;
    try {
        body = base64ToBytes(payload.body);
    } catch (e) {
        console.error('缓存内容解码失败', e);
        return null;
    }
    const headers = {
        'Content-Type': payload.contentType || 'text/html',
        'Cache-Control': `public, max-age=${CACHE_TTL.HTML}`,
        'X-Cache': cacheStatus,
    };
    if (payload.contentEncoding) {
        headers['Content-Encoding'] = payload.contentEncoding;
    }
    return new Response(body, { headers });
}

function hexToBytes(hex) {
    const clean = String(hex).replace(/[^0-9a-fA-F]/g, '');
    const out = new Uint8Array(clean.length / 2);
    for (let i = 0; i < out.length; i++) {
        out[i] = parseInt(clean.substr(i * 2, 2), 16);
    }
    return out;
}

function bytesToBase64(arrayBuffer) {
    const arr = new Uint8Array(arrayBuffer);
    let binary = '';
    const chunk = 0x8000;
    for (let i = 0; i < arr.length; i += chunk) {
        binary += String.fromCharCode.apply(null, arr.subarray(i, i + chunk));
    }
    return btoa(binary);
}

function base64ToBytes(b64) {
    const binary = atob(b64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes;
}

function getContentType(path) {
    const ext = path.split('.').pop();
    const types = {
        'css': 'text/css',
        'js': 'application/javascript',
        'png': 'image/png',
        'jpg': 'image/jpeg',
        'jpeg': 'image/jpeg',
        'gif': 'image/gif',
        'svg': 'image/svg+xml',
        'webp': 'image/webp',
        'ico': 'image/x-icon',
        'woff2': 'font/woff2',
        'woff': 'font/woff',
        'ttf': 'font/ttf',
        'eot': 'application/vnd.ms-fontobject',
    };
    return types[ext] || 'application/octet-stream';
}