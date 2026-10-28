#!/usr/bin/env node
/**
 * skin-css-audit.mjs —— 风格包 head CSS 的三层归属实测
 *
 * 判据（见本轮讨论）：对一个风格包的 5 个「整页页型」（pageHome/pageCategory/pageTag/pageArchive/single）
 *   ① 站点级   该 <style id> 在**全部**页型出现，且内容逐字节相同        → 可外链共享（一份）
 *   ② 块类型级 只在**部分**页型出现，但出现处内容逐字节相同（按需加载）  → 可外链（宁可多带）
 *   ③ 块实例级 跨页型内容**不一致**（wp-container-* 这类实例化规则）     → 必须跟页面走
 *
 * 额外观测：③ 里可能仍有「跨页型公共行」（页型间共享的容器规则，例如都来自 header/footer），
 *           这部分在「抽共享段」之后也能跟共享段走 —— 故单独统计 commonBytes。
 *
 * 用法：
 *   node tools/skin-css-audit.mjs [--root=worker/static/wp-content/themes] [--json=tmp/skin-css-audit.json] [--detail]
 *
 * 只读：不修改任何文件。--detail 打印每包的逐项分类明细（含 wp-container 类名与 body 的配对率）。
 */
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import crypto from 'node:crypto';

const args = {};
for (const a of process.argv.slice(2)) {
    if (a === '--detail') { args.detail = '1'; continue; }
    const i = a.indexOf('=');
    if (a.startsWith('--') && i > 0) args[a.slice(2, i)] = a.slice(i + 1);
}
const ROOT = args.root || 'worker/static/wp-content/themes';
const JSON_OUT = args.json || '';
const DETAIL = args.detail === '1';

const PAGE_KINDS = ['pageHome', 'pageCategory', 'pageTag', 'pageArchive', 'single'];
const ALL_KINDS = ['single', 'item', 'pageHome', 'pageCategory', 'pageTag', 'pageArchive'];

const gz = (s) => zlib.gzipSync(Buffer.from(s, 'utf8'), { level: 6 }).length;
const K = (n) => (n / 1024).toFixed(1) + 'K';
const sha = (s) => crypto.createHash('sha1').update(s, 'utf8').digest('hex').slice(0, 6);
const pad = (s, n) => String(s).padEnd(n);

function stylesOf(html) {
    const out = [];
    const re = /<style([^>]*)>([\s\S]*?)<\/style>/g;
    let m;
    while ((m = re.exec(html))) {
        const idm = m[1].match(/id=['"]([^'"]*)['"]/);
        out.push({ id: idm ? idm[1] : '(no-id)', css: m[2] });
    }
    return out;
}
const classes = (html) => new Set(html.match(/wp-container-[a-z0-9-]+/g) || []);
/** 在**所有**出现的页型里都存在的「行」的字节和（保守口径：行级公共部分） */
function commonBytes(values) {
    const sets = values.map((c) => c.split('\n').filter((l) => l.trim()));
    if (sets.length < 2) return 0;
    let common = sets[0];
    for (let i = 1; i < sets.length; i++) {
        const s = new Set(sets[i]);
        common = common.filter((l) => s.has(l));
    }
    return common.reduce((a, l) => a + l.length + 1, 0);
}

const packs = fs.readdirSync(ROOT).filter((d) => fs.existsSync(path.join(ROOT, d, 'tpl.json'))).sort();
const report = { root: ROOT, generatedAt: new Date().toISOString(), packs: [] };
const grand = { site: 0, siteGz: 0, siteDup: 0, type: 0, typeGz: 0, inst: 0, instCommon: 0, single: 0, singleGz: 0, savedGz: 0 };

console.log(`\n风格包 head CSS 三层归属实测    root=${ROOT}    包数=${packs.length}\n`);
console.log(pad('包', 18) + pad('① 站点级 raw/gzip', 24) + pad('② 块类型级 raw/gzip', 24) + pad('③ 块实例级 raw/gzip', 24) + 'single 整页 raw/gzip');
console.log('-'.repeat(122));

for (const p of packs) {
    const j = JSON.parse(fs.readFileSync(path.join(ROOT, p, 'tpl.json'), 'utf8'));
    const tpl = j.tpl || j;
    const presentPages = PAGE_KINDS.filter((k) => typeof tpl[k] === 'string' && tpl[k].length > 0);

    const byId = new Map();
    for (const kind of ALL_KINDS) {
        const html = tpl[kind];
        if (typeof html !== 'string') continue;
        for (const st of stylesOf(html)) {
            const key = st.id === '(no-id)' ? '(no-id):' + sha(st.css) : st.id;
            if (!byId.has(key)) byId.set(key, new Map());
            byId.get(key).set(kind, st.css);
        }
    }

    const rec = { pack: p, site: [], type: [], inst: [] };
    for (const [key, byKind] of byId) {
        const kinds = [...byKind.keys()];
        const values = [...byKind.values()];
        const pageKinds = kinds.filter((k) => PAGE_KINDS.includes(k));
        const entry = {
            id: key, kinds, byKind,
            bytes: Math.max(...values.map((c) => c.length)),
            css: values[0],
            commonBytes: commonBytes(values),
        };
        if (new Set(values).size > 1) rec.inst.push(entry);
        else if (pageKinds.length === presentPages.length) rec.site.push(entry);
        else rec.type.push(entry);
    }

    const sumRaw = (arr) => arr.reduce((a, e) => a + e.bytes, 0);
    const sumGz = (arr) => gz(arr.map((e) => e.css).join('\n'));
    const siteRaw = sumRaw(rec.site), typeRaw = sumRaw(rec.type), instRaw = sumRaw(rec.inst);
    const siteGz = sumGz(rec.site), typeGz = sumGz(rec.type), instGz = sumGz(rec.inst);
    const singleHtml = tpl.single || '';
    const singleGz = gz(singleHtml);
    // ③ 里跨页型公共的那部分（页型间共享，例如同来自 header/footer）
    const instCommon = rec.inst.reduce((a, e) => a + e.commonBytes, 0);

    console.log(pad(p, 18)
        + pad(`${K(siteRaw)} / ${K(siteGz)}`, 24)
        + pad(`${K(typeRaw)} / ${K(typeGz)}`, 24)
        + pad(`${K(instRaw)} / ${K(instGz)}`, 24)
        + `${K(singleHtml.length)} / ${K(singleGz)}`);

    if (DETAIL) {
        console.log(`   ── 站点级 ${rec.site.length} 项；块类型级 ${rec.type.length} 项：` + rec.type.map((e) => `${e.id}[${e.kinds.join(',')}]`).join(' '));
        console.log(`      块实例级 ${rec.inst.length} 项：` + rec.inst.map((e) => `${e.id}(${K(e.bytes)})[${e.kinds.join(',')}]`).join(' '));
        for (const e of rec.inst) {
            const dc = classes(e.css);
            const db = classes(singleHtml);
            const hit = [...dc].filter((c) => db.has(c)).length;
            console.log(`      ↳ ${e.id}: 跨页型公共行 ${K(e.commonBytes)}/${K(e.bytes)}（${(e.commonBytes / e.bytes * 100).toFixed(0)}%）`
                + (dc.size ? `；wp-container 类 CSS ${dc.size} 个 / single body ${db.size} 个 / 命中 ${hit}` : ''));
        }
    }

    grand.site += siteRaw; grand.siteGz += siteGz;
    grand.type += typeRaw; grand.typeGz += typeGz; grand.inst += instRaw; grand.instCommon += instCommon;
    grand.siteDup += siteRaw * Math.max(0, presentPages.length - 1);
    grand.single += singleHtml.length; grand.singleGz += singleGz;
    grand.savedGz += siteGz * Math.max(0, presentPages.length - 1);   // 外链后省掉的冗余传输（每页型各存一份）
    report.packs.push({
        pack: p, presentPages,
        siteRaw, siteGz, typeRaw, typeGz, instRaw, instGz, instCommon,
        singleRaw: singleHtml.length, singleGz,
        site: rec.site.map(({ id, kinds, bytes }) => ({ id, kinds, bytes })),
        type: rec.type.map(({ id, kinds, bytes }) => ({ id, kinds, bytes })),
        inst: rec.inst.map(({ id, kinds, bytes, commonBytes }) => ({ id, kinds, bytes, commonBytes })),
    });
}

console.log('-'.repeat(122));
console.log(pad('合计', 18) + pad(`${K(grand.site)} / ${K(grand.siteGz)}`, 24)
    + pad(`${K(grand.type)} / ${K(grand.typeGz)}`, 24) + pad(`${K(grand.inst)}`, 24)
    + `${K(grand.single)} / ${K(grand.singleGz)}`);
console.log('');
console.log(`① 站点级：内容 ${K(grand.site)}（gzip ${K(grand.siteGz)}）。因为它被存进每一个页型，`);
console.log(`   冗余副本合计 ≈ ${K(grand.siteDup)} 原始 / **gzip ${K(grand.savedGz)}** —— 这就是「抽一份共享」能省下的传输量。`);
console.log(`② 块类型级 = ${K(grand.type)}（gzip ${K(grand.typeGz)}，按需加载：外链时宁可多带，换来一次缓存全站复用）。`);
console.log(`③ 块实例级 = ${K(grand.inst)}，其中**跨页型公共行 ${K(grand.instCommon)}**（${(grand.instCommon / Math.max(1, grand.inst) * 100).toFixed(0)}%）`);
console.log(`   —— 这部分与「哪一页」无关（多来自 header/footer/nav），抽共享段后也能一起共享，不必留在每页里。`);
console.log(`14 包 single 整页合计 ${K(grand.single)} → gzip ${K(grand.singleGz)}\n`);

if (JSON_OUT) {
    fs.mkdirSync(path.dirname(JSON_OUT), { recursive: true });
    fs.writeFileSync(JSON_OUT, JSON.stringify(report, null, 2));
    console.log(`明细已写入 ${JSON_OUT}\n`);
}
