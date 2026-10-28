/**
 * 数据库操作 - 关联表版本 v2
 * taxonomies: PRIMARY KEY (type, slug)；post_taxonomy 含 taxonomy_type —— 修复同名 category/tag slug 串扰
 * 所有列表查询支持 perPage 参数（WP 设置页「列表每页文章数」经 routes.js 的 CFSE_PER_PAGE 常量传入）
 */

// 安全获取计数的辅助函数
function safeCount(result) {
    if (result === null || result === undefined) return 0;
    // 如果 result 是对象（如 { count: '5' }）
    if (typeof result === 'object' && result.count !== undefined) {
        return parseInt(result.count) || 0;
    }
    // 如果 result 是字符串或数字
    return parseInt(result) || 0;
}

export async function getTaxonomyInfo(db, type, slug) {
    try {
        const row = await db.prepare(
            "SELECT type, slug, name FROM taxonomies WHERE type = ? AND slug = ?"
        ).bind(type, slug).first();
        return row || null;
    } catch (error) {
        console.error('getTaxonomyInfo error:', error);
        return null;
    }
}

export async function getPostsByTaxonomy(db, type, slug, page = 1, perPage = 10) {
    const offset = (page - 1) * perPage;
    try {
        const posts = await db.prepare(
            `SELECT p.* FROM posts p
             INNER JOIN post_taxonomy pt ON p.wp_id = pt.post_id AND pt.taxonomy_type = ?
             INNER JOIN taxonomies t ON pt.taxonomy_slug = t.slug AND pt.taxonomy_type = t.type
             WHERE t.slug = ? AND t.type = ? AND p.status = 'published'
             ORDER BY p.date DESC LIMIT ? OFFSET ?`
        ).bind(type, slug, type, perPage, offset).all();

        const totalResult = await db.prepare(
            `SELECT COUNT(*) as count FROM posts p
             INNER JOIN post_taxonomy pt ON p.wp_id = pt.post_id AND pt.taxonomy_type = ?
             INNER JOIN taxonomies t ON pt.taxonomy_slug = t.slug AND pt.taxonomy_type = t.type
             WHERE t.slug = ? AND t.type = ? AND p.status = 'published'`
        ).bind(type, slug, type).first();

        const total = safeCount(totalResult);

        return {
            posts: posts.results || [],
            total: total,
            currentPage: page,
            totalPages: Math.ceil(total / perPage)
        };
    } catch (error) {
        console.error('getPostsByTaxonomy error:', error);
        return { posts: [], total: 0, currentPage: page, totalPages: 0 };
    }
}

export async function getRecentPosts(db, page = 1, perPage = 10) {
    const offset = (page - 1) * perPage;
    try {
        const posts = await db.prepare(
            "SELECT * FROM posts WHERE status = 'published' ORDER BY date DESC LIMIT ? OFFSET ?"
        ).bind(perPage, offset).all();

        const totalResult = await db.prepare(
            "SELECT COUNT(*) as count FROM posts WHERE status = 'published'"
        ).first();

        const total = safeCount(totalResult);

        return {
            posts: posts.results || [],
            total: total,
            currentPage: page,
            totalPages: Math.ceil(total / perPage)
        };
    } catch (error) {
        console.error('getRecentPosts error:', error);
        return { posts: [], total: 0, currentPage: page, totalPages: 0 };
    }
}

export async function searchPosts(db, query, page = 1, perPage = 20) {
    const offset = (page - 1) * perPage;
    try {
        const posts = await db.prepare(
            `SELECT * FROM posts WHERE status = 'published' 
             AND (title LIKE ? OR content LIKE ? OR excerpt LIKE ?)
             ORDER BY date DESC LIMIT ? OFFSET ?`
        ).bind(`%${query}%`, `%${query}%`, `%${query}%`, perPage, offset).all();

        const totalResult = await db.prepare(
            `SELECT COUNT(*) as count FROM posts WHERE status = 'published' 
             AND (title LIKE ? OR content LIKE ? OR excerpt LIKE ?)`
        ).bind(`%${query}%`, `%${query}%`, `%${query}%`).first();

        const total = safeCount(totalResult);

        return {
            posts: posts.results || [],
            total: total,
            currentPage: page,
            totalPages: Math.ceil(total / perPage)
        };
    } catch (error) {
        console.error('searchPosts error:', error);
        return { posts: [], total: 0, currentPage: page, totalPages: 0 };
    }
}

export async function getPostsByArchive(db, year, month = null, page = 1, perPage = 10) {
    const offset = (page - 1) * perPage;
    try {
        let startDate = `${year}-01-01`;
        let endDate = `${parseInt(year) + 1}-01-01`;
        if (month) {
            const paddedMonth = String(month).padStart(2, '0');
            startDate = `${year}-${paddedMonth}-01`;
            const nextMonth = parseInt(month) === 12 ? 1 : parseInt(month) + 1;
            const nextYear = parseInt(month) === 12 ? parseInt(year) + 1 : parseInt(year);
            endDate = `${nextYear}-${String(nextMonth).padStart(2, '0')}-01`;
        }
        const sql = `SELECT * FROM posts WHERE status = 'published' AND date >= ? AND date < ? ORDER BY date DESC LIMIT ? OFFSET ?`;
        const posts = await db.prepare(sql).bind(startDate, endDate, perPage, offset).all();
        
        const countSql = `SELECT COUNT(*) as count FROM posts WHERE status = 'published' AND date >= ? AND date < ?`;
        const totalResult = await db.prepare(countSql).bind(startDate, endDate).first();
        const total = safeCount(totalResult);

        return {
            posts: posts.results || [],
            total: total,
            currentPage: page,
            totalPages: Math.ceil(total / perPage)
        };
    } catch (error) {
        console.error('getPostsByArchive error:', error);
        return { posts: [], total: 0, currentPage: page, totalPages: 0 };
    }
}

/**
 * 侧边栏 - 分类列表（按 name 排序，与 WP categories widget 顺序一致）
 * 取消 count 数字：不再 JOIN posts 做 COUNT 聚合（原先每次页面/导出渲染都重算，
 * 数据量大时是读行噩梦）。改用 EXISTS 半连接保留「只列含已发布文章的分类」语义，
 * 命中即停，避免全量扫描；索引 idx_pt_slug + idx_posts_status 可走。
 */
export async function getSidebarCategories(db) {
    try {
        const result = await db.prepare(
            `SELECT t.slug, t.name
             FROM taxonomies t
             WHERE t.type = 'category'
               AND EXISTS (
                 SELECT 1 FROM post_taxonomy pt
                 JOIN posts p ON p.wp_id = pt.post_id AND p.status = 'published'
                 WHERE pt.taxonomy_type = t.type AND pt.taxonomy_slug = t.slug
               )
             ORDER BY t.name COLLATE NOCASE ASC`
        ).all();
        return result.results || [];
    } catch (error) {
        console.error('getSidebarCategories error:', error);
        return [];
    }
}

/**
 * 侧边栏 - 标签列表（仅含已发布文章；顺序与 WP tag cloud 一致：name ASC）
 * 同 getSidebarCategories：去掉 COUNT，改用 EXISTS 半连接。
 */
export async function getSidebarTags(db) {
    try {
        const result = await db.prepare(
            `SELECT t.slug, t.name
             FROM taxonomies t
             WHERE t.type = 'tag'
               AND EXISTS (
                 SELECT 1 FROM post_taxonomy pt
                 JOIN posts p ON p.wp_id = pt.post_id AND p.status = 'published'
                 WHERE pt.taxonomy_type = t.type AND pt.taxonomy_slug = t.slug
               )
             ORDER BY t.name COLLATE NOCASE ASC`
        ).all();
        return result.results || [];
    } catch (error) {
        console.error('getSidebarTags error:', error);
        return [];
    }
}

/**
 * 侧边栏 - 归档月份（date 前 7 位 YYYY-MM，按时间倒序）
 * 取消 count 数字：仅取去重月份列表，不再 COUNT(*)。
 */
export async function getArchiveMonths(db) {
    try {
        const result = await db.prepare(
            `SELECT DISTINCT substr(date, 1, 7) AS ym
             FROM posts
             WHERE status = 'published'
             ORDER BY ym DESC`
        ).all();
        return result.results || [];
    } catch (error) {
        console.error('getArchiveMonths error:', error);
        return [];
    }
}

/**
 * 侧边栏完整数据（一次调用取齐三段，供列表页渲染时并行查询后合并缓存）
 */
export async function getSidebarData(db) {
    const [categories, tags, archives] = await Promise.all([
        getSidebarCategories(db),
        getSidebarTags(db),
        getArchiveMonths(db),
    ]);
    return { categories, tags, archives };
}
