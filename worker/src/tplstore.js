/**
 * tplstore.js —— 模板存储（KV，Worker 后台「模板」页使用）
 *   tpl:active            = {single:{id,name,content}, item:{id,name,content}}  渲染路径唯一读取点（id='default'/content 空 = 用内置模板）
 *   tpl:index             = {single:[{id,name,updated}], item:[...]}            后台模板页清单
 *   tpl:store:<type>:<id> = {id,name,content,updated}                          每套模板正文
 */
export async function getActiveTpl(env) {
    try {
        const v = await env.KV_CACHE.get('tpl:active');
        if (!v) return null;
        const o = JSON.parse(v);
        return (o && typeof o === 'object') ? o : null;
    } catch (e) { return null; }
}

/** 取当前激活的自定义模板正文；未配置/默认 → 返回空串（调用方回退内置模板） */
export async function getActiveTplContent(env, type) {
    const o = await getActiveTpl(env);
    const e = o && o[type];
    if (!e || e.id === 'default' || !e.content) return '';
    return String(e.content);
}
