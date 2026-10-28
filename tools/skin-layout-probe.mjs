#!/usr/bin/env node
/**
 * skin-layout-probe.mjs —— 从产物推导「可切换的双列布局」选择器
 *
 * 目的：让「文章单页单列」（隐藏侧边栏 + 主内容占满）**不重抓**就能实现。
 * 依据：侧边栏容器在产物里是确定的 —— 它就包着 {{SIDEBAR_*}}，直接反查即可，不用猜。
 * 判定：只有「侧边栏容器」与「主内容容器」是**同一父元素的兄弟**才算真·双列（可切）；
 *       像 twentynineteen 那样 widget 落在 <footer> 里的，本来就单列 ⇒ 标 no-sidebar 跳过。
 * 产出：写进 tpl.json 的 layout 段 { sidebar:'#primary', main:'#container', ... }
 *
 * 用法：
 *   node tools/skin-layout-probe.mjs [--root=worker/static/wp-content/themes] [--pack=twentyten] [--all] [--write]
 *   默认只打印；--write 才写回 tpl.json。
 */
import fs from 'node:fs';
import path from 'node:path';

const args = {};
for (const a of process.argv.slice(2)) {
    if (!a.startsWith('--')) continue;
    const i = a.indexOf('=');
    if (i > 0) args[a.slice(2, i)] = a.slice(i + 1); else args[a.slice(2)] = '1';
}
const ROOT = args.root || 'worker/static/wp-content/themes';
const WRITE = args.write === '1';
const ONLY = args.pack || '';

const VOID = new Set(['br', 'img', 'hr', 'input', 'meta', 'link', 'source', 'area', 'base', 'col', 'embed', 'param', 'track', 'wbr']);
const TAG_RE = /<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b([^>]*?)(\/?)>/g;

function attrsOf(raw) {
    const id = (raw.match(/\bid=["']([^"']+)["']/) || [])[1] || '';
    const cls = (raw.match(/\bclass=["']([^"']+)["']/) || [])[1] || '';
    return { id, cls };
}
function selectorOf(el) {
    if (el.id) return '#' + el.id;
    const c = (el.cls || '').trim().split(/\s+/).filter(Boolean);
    return c.length ? '.' + c[0] : '';
}

/** 返回包含 pos 的标签栈（外 → 内） */
function stackAt(html, pos) {
    const re = new RegExp(TAG_RE.source, 'g');
    const stack = [];
    let m;
    while ((m = re.exec(html)) && m.index < pos) {
        const tag = m[2].toLowerCase();
        if (VOID.has(tag) || m[4] === '/') continue;
        if (m[1] === '/') {
            for (let i = stack.length - 1; i >= 0; i--) { if (stack[i].tag === tag) { stack.length = i; break; } }
        } else {
            const a = attrsOf(m[3]);
            stack.push({ tag, start: m.index, openEnd: m.index + m[0].length, id: a.id, cls: a.cls });
        }
    }
    return stack;
}

/** 返回 [from,to) 内「相对 from 层级为 0」的直接子元素区间（含 end） */
function topChildren(html, from, to) {
    const re = new RegExp(TAG_RE.source, 'g');
    re.lastIndex = from;
    const out = [];
    let depth = 0, cur = null, m;
    while ((m = re.exec(html)) && m.index < to) {
        const tag = m[2].toLowerCase();
        if (VOID.has(tag) || m[4] === '/') continue;      // 自闭合 / void：不影响层级
        if (m[1] === '/') {
            if (depth > 0) depth--;
            if (depth === 0 && cur) { cur.end = m.index + m[0].length; out.push(cur); cur = null; }
        } else {
            if (depth === 0) { const a = attrsOf(m[3]); cur = { tag, start: m.index, id: a.id, cls: a.cls, end: to }; }
            depth++;
        }
    }
    if (cur) out.push(cur);
    return out;
}

const packs = fs.readdirSync(ROOT)
    .filter((d) => fs.existsSync(path.join(ROOT, d, 'tpl.json')))
    .filter((d) => !ONLY || d === ONLY)
    .sort();

console.log(`\n单页双列布局推导    root=${ROOT}    ${WRITE ? '【写回】' : '（只读）'}\n`);
const pad = (s, n) => String(s).padEnd(n);
console.log(pad('包', 17) + pad('侧边栏选择器', 22) + pad('主内容选择器', 22) + '判定 / 说明');
console.log('-'.repeat(104));

let ok = 0, skip = 0, bad = 0;
for (const p of packs) {
    const file = path.join(ROOT, p, 'tpl.json');
    const j = JSON.parse(fs.readFileSync(file, 'utf8'));
    const tpl = j.tpl || j;
    const html = tpl.single || '';
    let line;
    if (!html) { console.log(pad(p, 17) + '—' + '  （无 single）'); skip++; continue; }

    const pos = html.search(/\{\{SIDEBAR_[A-Z]+\}\}/);
    if (pos < 0) { line = pad(p, 17) + pad('(无 SIDEBAR_* 占位符)', 22) + pad('—', 22) + '跳过（该包单页没有侧边栏块）'; console.log(line); skip++; continue; }

    // 判据：侧边栏 = 含 {{SIDEBAR_*}} 的那个**直接子元素**；主内容 = 含 {{POST_CONTENT}} 的直接子元素。
    // 两者必须是**同一个父元素（LCA）下的不同直接子元素**，才算真·双列（可切）。
    // 这样 twentyten 会取到 #primary / #container（而不是最内层的 #categories-3），
    // 而 twentynineteen（widget 落在 <footer> 里，与主内容同属一个子块）会被正确判为"本来就单列"。
    const posSide = html.search(/\{\{SIDEBAR_[A-Z]+\}\}/);
    const posContent = html.indexOf('{{POST_CONTENT}}');
    if (posSide < 0) { console.log(pad(p, 17) + pad('(无 SIDEBAR_*)', 22) + pad('—', 22) + '跳过（单页没有侧边栏块）'); skip++; continue; }
    if (posContent < 0) { console.log(pad(p, 17) + pad('?', 22) + pad('—', 22) + '跳过（单页没有 {{POST_CONTENT}}，无法定位主内容）'); bad++; continue; }

    const stContent = stackAt(html, posContent);
    const stSide = stackAt(html, posSide);
    let lca = null;
    for (let i = 0; i < Math.min(stContent.length, stSide.length); i++) {
        if (stContent[i].start !== stSide[i].start) break;
        lca = stContent[i];
    }
    if (!lca) { console.log(pad(p, 17) + pad('?', 22) + pad('?', 22) + '跳过（找不到公共祖先）'); bad++; continue; }

    const kids = topChildren(html, lca.openEnd, html.length);
    const mainKid = kids.find((k) => k.start <= posContent && posContent < k.end);
    const sideKid = kids.find((k) => k.start <= posSide && posSide < k.end);
    const sideSel = sideKid ? selectorOf(sideKid) : '';
    const mainSel = mainKid ? selectorOf(mainKid) : '';
    // 两道保护（误杀只是"该包不支持切换"，误放会藏掉页脚/主内容 ⇒ 宁可保守）：
    //   ① 主内容与侧边栏的选择器不能相同（块主题的 .wp-block-column 就撞过）
    //   ② 侧边栏容器不能是 footer 语义（twentynineteen 的 #colophon、自研包的 .foot 都撞过）
    const sideLooksFooter = !!(sideKid && (sideKid.tag === 'footer'
        || /footer|colophon|\bfoot\b/i.test((sideKid.id || '') + ' ' + (sideKid.cls || ''))));
    const paired = !!(mainKid && sideKid && mainKid.start !== sideKid.start
        && sideSel && mainSel && sideSel !== mainSel && !sideLooksFooter);

    const verdict = paired
        ? `✅ 双列（LCA <${lca.tag}${lca.id ? ' #' + lca.id : lca.cls ? ' .' + lca.cls.split(/\s+/)[0] : ''}>；主内容 <${mainKid.tag}${mainKid.id ? ' #' + mainKid.id : ''}>）`
        : sideLooksFooter ? '⊘ 跳过（侧边栏容器是 footer 语义 ⇒ 那是页脚 widget，不是右栏）'
            : (sideSel && sideSel === mainSel) ? '⊘ 跳过（侧边栏与主内容选择器相同，隐藏会误伤主内容）'
                : (mainKid && sideKid && mainKid.start === sideKid.start) ? '⊘ no-sidebar（侧边栏与主内容在同一子块内 ⇒ 本来就单列）'
                    : '跳过（主内容或侧边栏缺 id/class，无法安全定位）';
    console.log(pad(p, 17) + pad(sideSel || '—', 22) + pad(mainSel || '—', 22) + verdict);

    if (paired) ok++; else skip++;

    if (WRITE) {
        // 只在真的不同时才写 —— 否则会把「序列化格式差异」（PHP json_encode 转义 \uXXXX）也当成改动
        const next = paired ? { sidebar: sideSel, main: mainSel } : null;
        const cur = j.layout ? { sidebar: j.layout.sidebar, main: j.layout.main } : null;
        if (JSON.stringify(next) !== JSON.stringify(cur)) {
            if (next) j.layout = next; else delete j.layout;
            fs.writeFileSync(file, JSON.stringify(j));
        }
    }
}
console.log('-'.repeat(104));
console.log(`可切换双列 ${ok} 个    跳过/不适用 ${skip}    无法判定 ${bad}\n`);
