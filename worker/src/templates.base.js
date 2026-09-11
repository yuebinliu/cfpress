/**
 * 模板基础源文件 templates.base.js
 * 路径：worker/templates.base.js
 * ⚠️ 【源文件，不直接部署】
 * 插件PHP读取此文件，在下方“注入点标记”处注入以下内容，生成 worker/src/templates.js：
 *   1) SITE 常量（站点名/站点URL，来自 WP blogname / cf_site_url）
 *   2) 渲染公共 helper（buildPostUrl / parseTaxonomy / renderItems / applyIfBlock …）
 *   3) 4 个渲染函数 renderHomepage/renderCategoryPage/renderTagPage/renderArchivePage
 * 部署Worker请使用生成产物 templates.js
 *
 * ⚠️ 本文件只作为“纯壳”：
 *   - 仅保留公共工具函数（escapeHtml）与注入标记；
 *   - 不要在此处再定义 SITE/helper/renderXxx，否则 PHP 注入的同名声明会造成重复声明，templates.js 语法损坏、部署失败。
 */

// ============================================
// HTML转义公共工具【全局，所有渲染函数依赖，不可删除】
// ============================================
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ==========【PHP自动注入标记，不要删除、不要修改这行！】==========
{{INJECT_TEMPLATES}}
// ==========【PHP自动注入标记结束】==========
