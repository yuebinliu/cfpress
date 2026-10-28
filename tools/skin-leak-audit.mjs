#!/usr/bin/env node
/**
 * skin-leak-audit.mjs —— 扫风格包产物里的「快照泄漏」（漏认洞的证据）
 *
 * 背景：tpl.json 是「抓取那一刻」的快照。现状用「值锚猜」识别洞 ⇒ 猜不到的洞就留在产物里。
 *       这些残留都有共同特征：**只可能来自抓取时刻**。本工具按硬规则把它们扫出来。
 *
 * 硬规则（每条都只可能由快照产生，不是「可能正常」的噪音）：
 *   L1 探针残留     CFSEPROBE / cfseprobe（探针 token 没被清掉）
 *   L2 硬编码 host  产物里出现本站 host 的绝对 URL（本该是 {{SITE_URL}}）
 *   L3 归档 title   <title> 里是具体「月份 + 年份」（归档页标题被固化成抓取当月）
 *   L4 feed title   RSS link 的 title 里硬编码站点名（本该是 {{SITE_NAME}} &raquo; Feed）
 *   L5 具体时间戳   产物里出现 YYYY-MM-DD HH:MM（时间戳类残留）
 *
 * 用法：node tools/skin-leak-audit.mjs [--root=…] [--pack=twentyten] [--all]
 *      默认只打印有命中的包；--all 打印全部（含 0 命中的）。
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
const KINDS = ['single', 'item', 'pageHome', 'pageCategory', 'pageTag', 'pageArchive'];
const ONLY = args.pack || '';
const ALL = args.all === '1';

// 第三方域：出现在产物里是正常的（xfn 声明、WP 版权链接、社交链接等）
const EXTERNAL = /^(gmpg\.org|wordpress\.org|www\.wordpress\.org|w\.org|www\.w\.org|s\.w\.org|api\.w\.org|schema\.org|www\.schema\.org|fonts\.googleapis\.com|fonts\.gstatic\.com|example\.com|probe-cb\.example\.com|www\.youtube\.com|youtu\.be|twitter\.com|x\.com|www\.facebook\.com|facebook\.com|github\.com|creativecommons\.org|validator\.w3\.org|w3\.org|www\.w3\.org|purl\.org|ogp\.me)$/i;
const MONTHS = 'January|February|March|April|May|June|July|August|September|October|November|December';

function auditHtml(html) {
    const hits = [];
    if (typeof html !== 'string' || !html) return hits;

    // L1 探针残留
    const l1 = new Set(html.match(/CFSEPROBE[A-Za-z0-9_-]*|cfseprobe[A-Za-z0-9_-]*/g) || []);
    if (l1.size) hits.push({ rule: 'L1', label: '探针残留', detail: [...l1].slice(0, 4).join(' ') + (l1.size > 4 ? ` …(共${l1.size}种)` : '') });

    // L2 硬编码 host（排除第三方域）
    const hosts = new Map();
    for (const m of html.matchAll(/https?:\/\/([A-Za-z0-9.-]+)/g)) {
        const h = m[1];
        if (EXTERNAL.test(h)) continue;
        hosts.set(h, (hosts.get(h) || 0) + 1);
    }
    for (const [h, n] of [...hosts].sort((a, b) => b[1] - a[1]).slice(0, 3)) {
        hits.push({ rule: 'L2', label: 'host（人工判断）', detail: `${h} ×${n}` });
    }

    // L3 归档页 <title> 固化日期
    const tm = html.match(/<title>([\s\S]{0,240}?)<\/title>/);
    if (tm && new RegExp(`(${MONTHS})\\s*\\|?\\s*20\\d{2}`).test(tm[1])) {
        hits.push({ rule: 'L3', label: 'title 固化日期', detail: tm[1].replace(/\s+/g, ' ').trim().slice(0, 70) });
    }

    // L4 RSS link 的 title 硬编码站点名
    for (const m of html.matchAll(/type="application\/rss\+xml"[^>]{0,200}?title="([^"]{1,90})"/g)) {
        if (!m[1].includes('{{')) hits.push({ rule: 'L4', label: 'feed title 硬编码站点名', detail: m[1] });
    }

    // L5 具体时间戳
    const l5 = new Set(html.match(/\b20\d{2}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?/g) || []);
    if (l5.size) hits.push({ rule: 'L5', label: '具体时间戳', detail: [...l5].slice(0, 3).join(' ') + (l5.size > 3 ? ` …(共${l5.size})` : '') });

    return hits;
}

const packs = fs.readdirSync(ROOT)
    .filter((d) => fs.existsSync(path.join(ROOT, d, 'tpl.json')))
    .filter((d) => !ONLY || d === ONLY)
    .sort();

console.log(`\n风格包产物「快照泄漏」审计    root=${ROOT}    包数=${packs.length}\n`);
let totalHits = 0, packsWithHits = 0;
const ruleCount = { L1: 0, L2: 0, L3: 0, L4: 0, L5: 0 };

for (const p of packs) {
    const j = JSON.parse(fs.readFileSync(path.join(ROOT, p, 'tpl.json'), 'utf8'));
    const tpl = j.tpl || j;
    const lines = [];
    for (const k of KINDS) {
        const hits = auditHtml(tpl[k]);
        for (const h of hits) { ruleCount[h.rule]++; totalHits++; lines.push(`   ${k.padEnd(13)} ${h.rule} ${h.label.padEnd(22)} ${h.detail}`); }
    }
    if (lines.length) { packsWithHits++; console.log(`── ${p}（${lines.length} 处）`); lines.forEach((l) => console.log(l)); console.log(''); }
    else if (ALL) console.log(`── ${p}：干净`);
}

console.log('-'.repeat(80));
console.log(`命中包 ${packsWithHits}/${packs.length}    命中总数 ${totalHits}`);
console.log(`按规则：L1 探针残留 ${ruleCount.L1}    L2 硬编码 host ${ruleCount.L2}    L3 title 固化日期 ${ruleCount.L3}    L4 feed title ${ruleCount.L4}    L5 时间戳 ${ruleCount.L5}\n`);
