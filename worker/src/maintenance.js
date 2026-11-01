/**
 * 维护任务：清理 R2 里的孤儿单页。
 *
 * 什么算孤儿：形状像文章单页（`<slug>/index.html`）、但 D1 里已经不该存在的对象。
 * 覆盖三种真实场景 ——
 *   ① 文章转草稿 / 设私有 → 状态不再是 published
 *   ② 改了 slug → 旧路径没人记得，只剩 R2 里那份
 *   ③ 文章被删除 → D1 行没了，单页还在
 *
 * 它**只管文章单页**。文档成品在 `_pages/` 下（`_pages/<abc>/index.html` 这类 key 同样以
 * `/index.html` 结尾），源稿在 `_src/`，媒体在 `wp-content/`——这些都按前缀排除，
 * 否则第一次跑就会把文档库的目录页删掉（删了不会 404，会退化成每次现场渲染，白白变慢）。
 */

/** 不是文章单页的区域，一律不碰 */
const RESERVED_PREFIXES = ['wp-content/', '_pages/', '_src/', 'assets/', 'media/', 'style/', 'hl/', 'preview/'];

export function postStoragePath(row, pattern) {
    const pat = String(pattern || '{slug}/index.html');
    const slug = String((row && row.slug) || '').toLowerCase();
    const date = String((row && (row.date || '')) || '');
    const m = date.match(/^(\d{4})-(\d{2})-(\d{2})/);
    return pat
        .replace('{year}', m ? m[1] : '')
        .replace('{month}', m ? m[2] : '')
        .replace('{day}', m ? m[3] : '')
        .replace('{slug}', slug);
}

/**
 * @param {object} env
 * @param {object} opts { delete?: boolean, limit?: number }  limit=扫描返回的样本数（不传=50）
 * @returns {Promise<{pattern:string, expected:number, orphanCount:number, orphans:Array, deleted:number}>}
 */
export async function purgeOrphanPages(env, opts) {
    const o = opts || {};
    const { getSiteContext } = await import('./site');
    const site = await getSiteContext(env);
    const pattern = (site && site.storagePattern) || '{slug}/index.html';

    // 1) 当前结构下「应该有」的 key
    const rows = await env.D1_DATABASE.prepare("SELECT slug, date FROM posts WHERE status = 'published'").all();
    const want = {};
    (rows.results || []).forEach(function (r) { want[postStoragePath(r, pattern)] = true; });

    // 2) 扫 R2，挑形状像单页、又不在应有集合里的
    const found = [];
    let cursor;
    do {
        const page = await env.R2_BUCKET.list({ limit: 1000, cursor: cursor });
        (page.objects || []).forEach(function (obj) {
            const k = String(obj.key || '');
            if (!/\/index\.html$/.test(k) && k !== 'index.html') return;
            for (let i = 0; i < RESERVED_PREFIXES.length; i++) {
                if (k.indexOf(RESERVED_PREFIXES[i]) === 0) return;
            }
            if (!want[k]) found.push({ key: k, size: obj.size || 0 });
        });
        cursor = page.truncated ? page.cursor : undefined;
    } while (cursor);

    // 3) 可选删除
    let deleted = 0;
    if (o.delete) {
        for (const obj of found) {
            try { await env.R2_BUCKET.delete(obj.key); deleted++; } catch (e) { /* 单个失败继续 */ }
        }
    }

    const limit = Number(o.limit) > 0 ? Number(o.limit) : 50;
    return {
        pattern: pattern,
        expected: Object.keys(want).length,
        orphanCount: found.length,
        orphans: o.delete ? [] : found.slice(0, limit),
        truncated: !o.delete && found.length > limit,
        deleted: deleted,
    };
}
