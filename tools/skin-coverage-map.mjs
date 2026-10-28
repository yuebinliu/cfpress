#!/usr/bin/env node
/**
 * skin-coverage-map.mjs —— 「主题用到了什么」× 「worker 支持什么」× 「产物里有没有」
 *
 * 判据（用户定调）：**不是**"契约规定必须有"，而是
 *   ① 主题源码里真的用到了某个输出单元（WP 模板标签 / 主题 helper）
 *   ② worker 侧恰好支持它对应的洞
 *   ⇒ 那就**应该**被提取出来；没提取 = 缺陷，要说明"为什么做不了"。
 *
 * 输出三列对照：
 *   符号（源码里出现的）→ 对应洞 → 产物里有没有 → worker 支持吗 → 结论
 *
 * 用法：
 *   node tools/skin-coverage-map.mjs --theme-dir=tmp/themes/twentyten \
 *        --tpl=worker/static/wp-content/themes/twentyten/tpl.json
 */
import fs from 'node:fs';
import path from 'node:path';

const args = {};
for (const a of process.argv.slice(2)) {
    if (!a.startsWith('--')) continue;
    const i = a.indexOf('=');
    if (i > 0) args[a.slice(2, i)] = a.slice(i + 1);
}
const THEME_DIR = args['theme-dir'] || 'tmp/themes/twentyten';
const TPL = args.tpl || 'worker/static/wp-content/themes/twentyten/tpl.json';
const CONTRACT = 'docs/skin-contract.json';

/* ---------- 映射表：源码符号 → 应产出的洞 ----------
 * tokens 为空数组 = worker 侧**没有**对应能力（这才是"做不了"的硬原因）
 * note 说明为什么/边界                                                */
const MAP = [
    { re: /\bthe_title\s*\(/, name: 'the_title', tokens: ['{{POST_TITLE}}'] },
    { re: /\bthe_content\s*\(/, name: 'the_content', tokens: ['{{POST_CONTENT}}'] },
    { re: /\bthe_excerpt\s*\(/, name: 'the_excerpt', tokens: ['{{POST_EXCERPT}}'] },
    { re: /\bthe_permalink\s*\(/, name: 'the_permalink', tokens: ['{{POST_URL}}'] },
    { re: /\bthe_ID\s*\(/, name: 'the_ID', tokens: ['{{CFSE_POST_ID}}'], note: '单页评论标记用' },
    { re: /\b(?:the_date|the_time|get_the_date|get_the_time|the_modified_date|get_the_modified_date)\s*\(/, name: 'the_date/the_time', tokens: ['{{POST_DATE}}'] },
    { re: /\bthe_author\s*\(|\bget_the_author\s*\(/, name: 'the_author', tokens: ['{{POST_AUTHOR}}', '{{POST_AUTHOR_URL}}'] },
    { re: /\bthe_category\s*\(|\bget_the_category_list\s*\(/, name: 'the_category/get_the_category_list', tokens: ['{{POST_CATEGORIES}}'] },
    { re: /\bthe_tags\s*\(|\bget_the_tag_list\s*\(/, name: 'the_tags/get_the_tag_list', tokens: ['{{POST_TAGS}}'] },
    { re: /\bthe_post_thumbnail\s*\(|\bget_the_post_thumbnail\s*\(/, name: 'the_post_thumbnail', tokens: ['{{POST_THUMBNAIL}}'] },
    { re: /\bcomments_popup_link\s*\(|\bget_comments_number\s*\(/, name: 'comments_popup_link', tokens: ['{{COMMENT_COUNT}}', '{{COMMENT_COUNT_TEXT}}'] },
    { re: /\bprevious_post_link\s*\(/, name: 'previous_post_link', tokens: ['{{PREV_URL}}', '{{PREV_TITLE}}'] },
    { re: /\bnext_post_link\s*\(/, name: 'next_post_link', tokens: ['{{NEXT_URL}}', '{{NEXT_TITLE}}'] },
    { re: /\bcomments_template\s*\(/, name: 'comments_template', tokens: ['{{COMMENTS}}'] },
    { re: /\bwp_nav_menu\s*\(|\bwp_page_menu\s*\(/, name: 'wp_nav_menu', tokens: ['{{MENU}}'], note: '经典提取器未实现（块主题已实现）' },
    { re: /\bdynamic_sidebar\s*\(|\bis_active_sidebar\s*\(/, name: 'dynamic_sidebar', tokens: ['{{SIDEBAR_CATEGORIES}}', '{{SIDEBAR_TAGS}}', '{{SIDEBAR_ARCHIVES}}'] },
    { re: /\bwp_get_archives\s*\(/, name: 'wp_get_archives', tokens: ['{{SIDEBAR_ARCHIVES}}'] },
    { re: /\bwp_list_categories\s*\(|\bwp_dropdown_categories\s*\(/, name: 'wp_list_categories', tokens: ['{{SIDEBAR_CATEGORIES}}'] },
    { re: /\bwp_tag_cloud\s*\(/, name: 'wp_tag_cloud', tokens: ['{{SIDEBAR_TAGS}}'] },
    { re: /\bbloginfo\s*\(|\bget_bloginfo\s*\(/, name: 'bloginfo', tokens: ['{{SITE_NAME}}', '{{SITE_DESC}}'] },
    { re: /\bhome_url\s*\(|\bsite_url\s*\(/, name: 'home_url/site_url', tokens: ['{{SITE_URL}}'] },
    { re: /\bget_search_form\s*\(/, name: 'get_search_form', tokens: [], note: 'worker 只有 {{SEARCH_ACTION}}（给 action 值），没有 search form 的洞 —— **能力缺口**' },
    { re: /\bbody_class\s*\(/, name: 'body_class', tokens: [], note: 'worker 无 {{BODY_CLASS}} —— **能力缺口**（"单页双列/单列"开关的前提）' },
    { re: /\bpost_class\s*\(/, name: 'post_class', tokens: [], note: 'worker 无 {{POST_CLASS}} —— 能力缺口（条目的 post-xxx 类）' },
    { re: /\bwp_link_pages\s*\(/, name: 'wp_link_pages', tokens: [], note: '多页文章分页 —— 静态化不支持' },
    { re: /\bwp_register\s*\(|\bwp_loginout\s*\(|\bwp_meta\s*\(/, name: 'wp_register/loginout/meta', tokens: [], note: '侧边栏 Meta（登录/Feed 链接）—— 静态站无意义' },
    { re: /\bedit_post_link\s*\(/, name: 'edit_post_link', tokens: [], note: '后台编辑链接 —— 静态站无意义（应删）' },
    { re: /\bget_avatar\s*\(/, name: 'get_avatar', tokens: ['{{COMMENT_AVATAR}}'], note: '评论头像（comments 段）' },
    { re: /\bwp_head\s*\(|\bwp_footer\s*\(|\bwp_body_open\s*\(/, name: 'wp_head/footer', tokens: [], note: 'WP 运行时输出，非洞（由提取器整体固化）' },
    { re: /\bget_header\s*\(|\bget_footer\s*\(|\bget_sidebar\s*\(|\bget_template_part\s*\(/, name: 'get_*（模板装配）', tokens: [], note: '模板装配指令，非输出单元' },
    // 主题 helper（输出 HTML 的）：单列，由人判断
    { re: /\btwentyten_posted_on\s*\(/, name: 'twentyten_posted_on()', tokens: ['{{POST_DATE}}', '{{POST_AUTHOR}}'], note: '主题 helper：输出日期+作者' },
    { re: /\btwentyten_posted_in\s*\(/, name: 'twentyten_posted_in()', tokens: ['{{POST_CATEGORIES}}', '{{POST_TAGS}}'], note: '主题 helper：输出分类/标签' },
    { re: /\btwentyten_header_image\s*\(/, name: 'twentyten_header_image()', tokens: [], note: '主题 helper：头图 —— worker 无对应洞（能力缺口）' },
];

/* ---------- worker 支持集（契约） ---------- */
const contract = JSON.parse(fs.readFileSync(CONTRACT, 'utf8'));
const SUPPORTED = new Set(Object.keys(contract.tokens || {}));

/* ---------- 产物里实际出现的洞 ---------- */
const tpl = JSON.parse(fs.readFileSync(TPL, 'utf8'));
const flat = JSON.stringify(tpl);
const inTpl = (tok) => flat.includes(tok);

/* ---------- 扫主题源码 ---------- */
const files = fs.readdirSync(THEME_DIR).filter((f) => f.endsWith('.php'));
const src = files.map((f) => ({ f, s: fs.readFileSync(path.join(THEME_DIR, f), 'utf8') }));

const pad = (s, n) => String(s).padEnd(n);
const rows = [];
for (const m of MAP) {
    const hits = src.filter((x) => m.re.test(x.s)).map((x) => x.f);
    if (!hits.length) continue;
    const tokTxt = m.tokens.length ? m.tokens.join(' ') : '—（无对应洞）';
    const inT = m.tokens.length ? m.tokens.map((t) => (inTpl(t) ? '有' : '缺')) : ['—'];
    const sup = m.tokens.length ? m.tokens.map((t) => (SUPPORTED.has(t) ? '支持' : '不支持')) : ['—'];
    rows.push({ name: m.name, tokTxt, inT: inT.join('/'), sup: sup.join('/'), note: m.note || '', hits });
}

console.log(`\n主题用到的输出单元 × worker 支持 × 产物覆盖      theme=${THEME_DIR}\n`);
console.log(pad('源码符号', 34) + pad('对应洞', 34) + pad('产物', 14) + pad('worker', 14) + '结论 / 说明');
console.log('-'.repeat(132));
let gaps = 0;
for (const r of rows) {
    const missing = r.tokTxt !== '—（无对应洞）' && r.inT.includes('缺') && r.sup.includes('支持');
    const noCap = r.tokTxt === '—（无对应洞）';
    const verdict = missing ? '❌ 该有却没提取（提取器缺口）' : noCap ? '⚠️ worker 无此能力' : '✅';
    if (missing) gaps++;
    console.log(pad(r.name, 34) + pad(r.tokTxt, 34) + pad(r.inT, 14) + pad(r.sup, 14) + verdict + (r.note ? ' ｜ ' + r.note : ''));
}
console.log('-'.repeat(132));
console.log(`可提取却漏掉（提取器缺口）：${gaps} 项\n`);
