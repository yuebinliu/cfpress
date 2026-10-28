#!/usr/bin/env node
/**
 * split-tpl.mjs —— 风格包产物形态重组（v1 整页 → v2 共享段 + 每页型片段）
 *
 * v1（现状）
 *   { name, tpl: { single, item, pageHome, pageCategory, pageTag, pageArchive }, sidebar, comments }
 *   每个页型 = 一整页 HTML 字符串（head + header + 内容 + footer 全在里面）
 *
 * v2（重组）
 *   { name, shared: { header, footer }, tpl: { single: {pre,mid}, …, item: {whole} }, … }
 *   整页 = pre + shared.header + mid + shared.footer
 *
 * 切分方式：**纯机械的公共前缀 / 公共后缀**，只用 5 个「整页页型」计算，无启发式、不猜结构：
 *     s = max(各页型 <body…> 结束位置)   ← 保证所有页型都已越过 body 标签
 *     L = 从 s 起的最长公共前缀           → shared.header
 *     R = 从末尾起的最长公共后缀           → shared.footer
 *   不变量（逐字节，工具会断言）：pre + shared.header + mid + shared.footer === 原字符串
 *
 * 为什么从 body 标签之后开始算前缀：head 里 <title> 各页型不同，公共前缀在 head 里就断了。
 * 片段类页型（item，无 <body>）不参与共享，原样存 { whole }。
 *
 * 用法：
 *   node tools/split-tpl.mjs [--root=worker/static/wp-content/themes] [--check|--write] [--detail]
 *   --check 只校验与统计收益（默认）；--write 才写回 tpl.json（另存 .v1.bak 备份）
 *
 * ⚠️ 拼装逻辑必须与 worker/src/tplstore.js 的 joinTplPage() 保持一致（改一处要改两处）。
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
const DETAIL = args.detail === '1';

export const KINDS = ['single', 'item', 'pageHome', 'pageCategory', 'pageTag', 'pageArchive'];
const isFullPage = (s) => typeof s === 'string' && s.includes('<body');

/** v1 tpl{6 类} → { header, footer, parts{kind:{pre,mid}|{whole}} } */
export function splitPack(tpl) {
    const full = KINDS.filter((k) => isFullPage(tpl[k]));
    const parts = {};
    if (full.length < 2) {                       // 不足以算公共段：原样保留
        for (const k of KINDS) if (typeof tpl[k] === 'string') parts[k] = { whole: tpl[k] };
        return { header: '', footer: '', parts, full: [] };
    }
    const pages = full.map((k) => tpl[k]);
    // 各页型 <body …> 结束的偏移。⚠️ 它各页型长度不同（class 不同）⇒ 之后的比较必须用
    // 「相对各自 body 标签之后」的偏移，绝不能用绝对位置（否则从第一字节就错位、公共段恒为 0）。
    const afterBody = pages.map((h) => { const j = h.indexOf('>', h.indexOf('<body')); return j < 0 ? 0 : j + 1; });

    // 公共前缀：比较 pages[k][afterBody[k] + i]
    let L = 0;
    for (;;) {
        const c = pages[0][afterBody[0] + L];
        if (c === undefined) break;
        if (!pages.every((h, k) => h[afterBody[k] + L] === c)) break;
        L++;
    }
    // 公共后缀：从末尾起算（相对末尾天然对齐）；上限不得与前缀重叠
    const maxR = Math.min(...pages.map((h, k) => h.length - afterBody[k] - L));
    let R = 0;
    while (R < maxR && pages.every((h) => h[h.length - 1 - R] === pages[0][pages[0].length - 1 - R])) R++;

    const header = pages[0].slice(afterBody[0], afterBody[0] + L);
    const footer = pages[0].slice(pages[0].length - R);

    for (const k of KINDS) {
        const h = tpl[k];
        if (typeof h !== 'string') continue;
        if (!full.includes(k)) { parts[k] = { whole: h }; continue; }
        const ab = afterBody[full.indexOf(k)];
        parts[k] = { pre: h.slice(0, ab), mid: h.slice(ab + L, h.length - R) };
    }
    return { header, footer, parts, full };
}

/** v2 片段 → 整页（与 worker/src/tplstore.js 的 joinTplPage 保持一致） */
export function joinPage(parts, shared, kind) {
    const e = parts && parts[kind];
    if (!e) return '';
    if (typeof e === 'string') return e;                    // 兼容 v1（字符串）
    if (typeof e.whole === 'string') return e.whole;
    return (e.pre || '') + ((shared && shared.header) || '') + (e.mid || '') + ((shared && shared.footer) || '');
}

const packs = fs.readdirSync(ROOT).filter((d) => fs.existsSync(path.join(ROOT, d, 'tpl.json'))).sort();
const pad = (s, n) => String(s).padEnd(n);
const K = (n) => (n / 1024).toFixed(1) + 'K';
let grandShared = 0, grandSaved = 0, converted = 0, failures = 0;

console.log(`\n产物形态重组（v1 整页 → v2 共享 header/footer + 每页型片段）    root=${ROOT}\n`);
console.log(pad('包', 18) + pad('整页页型', 10) + pad('header', 10) + pad('footer', 10) + pad('去重可省', 12) + '校验');
console.log('-'.repeat(96));

for (const p of packs) {
    const file = path.join(ROOT, p, 'tpl.json');
    const j = JSON.parse(fs.readFileSync(file, 'utf8'));
    const tpl = j.tpl || j;
    const alreadyV2 = KINDS.some((k) => tpl[k] && typeof tpl[k] === 'object');
    if (alreadyV2) { console.log(pad(p, 18) + '—— 已是 v2 形态，跳过'); continue; }

    const { header, footer, parts, full } = splitPack(tpl);

    // 逐字节自校验
    let ok = true;
    const bad = [];
    for (const k of KINDS) {
        if (typeof tpl[k] !== 'string') continue;
        if (joinPage(parts, { header, footer }, k) !== tpl[k]) { ok = false; bad.push(k); }
    }

    const sharedBytes = header.length + footer.length;
    const saved = sharedBytes * Math.max(0, full.length - 1);
    grandShared += sharedBytes; grandSaved += saved;
    if (ok) converted++; else failures++;

    console.log(pad(p, 18) + pad(String(full.length), 10) + pad(K(header.length), 10) + pad(K(footer.length), 10)
        + pad(saved ? K(saved) : '—', 12) + (ok ? '✅ 逐字节相等' : '❌ ' + bad.join(',')));

    if (DETAIL) {
        for (const k of KINDS) {
            const e = parts[k]; if (!e) continue;
            console.log(`   ${k.padEnd(13)} ${e.whole !== undefined ? 'whole ' + K(e.whole.length) : 'pre ' + K(e.pre.length) + ' + mid ' + K(e.mid.length)}`);
        }
    }

    if (WRITE && ok) {
        const out = {
            name: j.name,
            shared: { header, footer },
            tpl: parts,
        };
        if (j.sidebar !== undefined) out.sidebar = j.sidebar;
        if (j.comments !== undefined) out.comments = j.comments;
        if (!fs.existsSync(file + '.v1.bak')) fs.copyFileSync(file, file + '.v1.bak');
        fs.writeFileSync(file, JSON.stringify(out));
    }
}

console.log('-'.repeat(96));
console.log(`包 ${packs.length}    可转换 ${converted}    校验失败 ${failures}`);
console.log(`共享段合计 ${K(grandShared)}；因「每页型各存一份」被重复的约 ${K(grandSaved)}（gzip 口径要另算）`);
console.log(WRITE ? '\n✅ 已写回（原文件备份为 tpl.json.v1.bak）\n' : '\n（--check 模式：未写任何文件）\n');
if (failures) process.exitCode = 1;
