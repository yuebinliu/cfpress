#!/usr/bin/env node
/**
 * 风格包校验器：用契约（docs/skin-contract.json）校验一个风格包
 *
 *   node tools/skin-validate.mjs <风格目录 | tpl.json>
 *
 * 检查项：
 *   ① 每个 {{X}} 必须是契约里的已实现 token（否则渲染时会被静默清空）
 *   ② 每个 {{#IF_X}} 必须是契约里的条件块 flag
 *   ③ 条件块必须成对（每段内 {{#IF_X}} 与 {{/IF_X}} 计数一致）
 *   ④ 提示：single 里是否有半动态评论标记（没有则新评论须重建单页）
 * 退出码：0 = 通过；1 = 有问题。
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const HANDBOOK = path.join(ROOT, 'docs/skin-contract.json');
const tokenRe = /\{\{[#/]?[A-Z][A-Z0-9_]*\}\}/g;

/* ---------- 入口参数 ---------- */
let arg = process.argv[2];
if (!arg) {
  console.error('用法：node tools/skin-validate.mjs <风格目录 | tpl.json>');
  process.exit(2);
}
let file = arg;
if (fs.existsSync(file) && fs.statSync(file).isDirectory()) file = path.join(file, 'tpl.json');
if (!fs.existsSync(file)) { console.error('❌ 找不到：' + file); process.exit(2); }

/* ---------- 契约 ---------- */
const hb = JSON.parse(fs.readFileSync(HANDBOOK, 'utf8'));
const OK_PLACEHOLDER = new Set();
const OK_FLAG = new Set();
for (const [k, t] of Object.entries(hb.tokens || {})) {
  if (t.kind === 'conditional') OK_FLAG.add(t.flag);
  else OK_PLACEHOLDER.add(k);
}

/* ---------- 风格包 ---------- */
const pkg = JSON.parse(fs.readFileSync(file, 'utf8'));
const sections = [];
if (pkg.tpl && typeof pkg.tpl === 'object') for (const [k, v] of Object.entries(pkg.tpl)) if (typeof v === 'string') sections.push(['tpl.' + k, v]);
if (pkg.sidebar && typeof pkg.sidebar === 'object') for (const [k, v] of Object.entries(pkg.sidebar)) if (typeof v === 'string') sections.push(['sidebar.' + k, v]);
if (pkg.comments && typeof pkg.comments === 'object') for (const [k, v] of Object.entries(pkg.comments)) if (typeof v === 'string') sections.push(['comments.' + k, v]);

const unknown = [];
const badFlag = [];
const unpaired = [];
const used = new Set();

for (const [name, text] of sections) {
  const open = new Map(), close = new Map();
  for (const m of text.matchAll(tokenRe)) {
    const tok = m[0];
    const mm = /^\{\{([#/])?(IF_([A-Za-z0-9_]+))?\}\}$/.exec(tok);
    const isCond = /^\{\{[#/]IF_/.test(tok);
    if (isCond) {
      const flag = tok.replace(/^\{\{[#/]IF_/, '').replace(/\}\}$/, '');
      if (!OK_FLAG.has(flag)) badFlag.push(`${name}: ${tok}（flag「${flag}」不在契约）`);
      if (tok.startsWith('{{#')) open.set(flag, (open.get(flag) || 0) + 1); else close.set(flag, (close.get(flag) || 0) + 1);
      used.add('IF:' + flag);
    } else if (/^\{\{[A-Z][A-Z0-9_]*\}\}$/.test(tok)) {
      if (!OK_PLACEHOLDER.has(tok)) unknown.push(`${name}: ${tok}`);
      used.add(tok);
    }
  }
  for (const f of new Set([...open.keys(), ...close.keys()])) {
    if ((open.get(f) || 0) !== (close.get(f) || 0)) unpaired.push(`${name}: {{#IF_${f}}} ×${open.get(f) || 0} vs {{/IF_${f}}} ×${close.get(f) || 0}`);
  }
}

/* ---------- 报告 ---------- */
console.log('风格包：' + pkg.name + '   （' + file.replace(ROOT + '/', '') + '）');
console.log('段：' + sections.map((s) => s[0]).join(', '));
console.log('用到契约 token：' + used.size + ' / ' + (OK_PLACEHOLDER.size + OK_FLAG.size));

const problems = [];
if (unknown.length) problems.push(['❌ 未知占位符（渲染时会被静默清空）', unknown]);
if (badFlag.length) problems.push(['❌ 条件块 flag 不在契约', badFlag]);
if (unpaired.length) problems.push(['❌ 条件块未成对', unpaired]);
for (const [title, list] of problems) {
  console.log('\n' + title + '（' + list.length + '）：');
  for (const x of list) console.log('   ' + x);
}

/* soft checks */
const single = (pkg.tpl && pkg.tpl.single) || '';
if (single && !/<!--CFSE-COMMENTS-START/.test(single)) {
  console.log('\n⚠️  single 里没有半动态评论标记 <!--CFSE-COMMENTS-START id={{CFSE_POST_ID}}--> —— 新评论需重建单页才可见。');
}
if (single && !/\{\{#IF_COMP?MENTS\}\}/.test(single) && !/\{\{COMMENTS\}\}/.test(single)) {
  console.log('⚠️  single 里没有 {{COMMENTS}} —— 评论不会显示。');
}
if (!pkg.comments) console.log('⚠️  没有 comments 段 —— native 模式下评论区为空（embed 模式不受影响）。');
if (!pkg.sidebar) console.log('⚠️  没有 sidebar 段 —— 侧边栏回退到内置 <li>。');

if (problems.length) { console.log('\n结论：不通过。'); process.exit(1); }
console.log('\n结论：通过 ✓');
