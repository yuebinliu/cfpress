import { handleRequest } from './routes';
import { purgeOrphanPages } from './maintenance';

export default {
    async fetch(request, env, ctx) {
        const startTime = Date.now();
        try {
            const response = await handleRequest(request, env);
            response.headers.set('X-Response-Time', `${Date.now() - startTime}ms`);
            response.headers.set('X-Powered-By', 'CF-Static-Export-Pro');
            return response;
        } catch (error) {
            console.error('Worker error:', error);
            return new Response('Internal Server Error', {
                status: 500,
                headers: { 'Content-Type': 'text/plain' }
            });
        }
    },

    /**
     * 定时任务：每天清理一次孤儿单页。
     *
     * 为什么需要：文章转草稿、改 slug、被删除之后，D1 立刻变了，但 R2 里那份 HTML 还在，
     * 静态直出会继续把旧页面发给读者 —— 「以为撤回了，其实还挂在网上」。
     * 以前只能靠后台手动点「清理孤儿文件」，没人点就一直挂着。
     *
     * 口径与后台那个按钮完全一致（同一个 purgeOrphanPages），文档/媒体按前缀排除。
     */
    async scheduled(event, env, ctx) {
        try {
            const res = await purgeOrphanPages(env, { delete: true });
            console.log('[cron] 孤儿清理：应有单页 ' + res.expected + ' 个，发现孤儿 ' + res.orphanCount + ' 个，已删除 ' + res.deleted + ' 个');
        } catch (e) {
            console.error('[cron] 孤儿清理失败：', e);
        }
    }
};
