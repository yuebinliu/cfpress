#!/usr/bin/env node
/**
 * 契约一致性断言
 *
 *   渲染器实际替换的 token  ≡  docs/skin-contract.json 的 tokens
 *
 * 用途：**改了渲染器就必须跑这个**。不一致说明手册漂移了（或渲染器漏了实现）。
 *   node tools/contract-check.mjs
 *
 * 退出码：0 = 一致；1 = 不一致。
 * 附加（warning，不影响退出码）：rules 文本里引用的 {{XXX}} 是否都在手册里
 *   —— 用来抓"散文引用了已改名 token"这类过期。反例可写成 ~~{{XXX}}~~ 豁免。
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const RENDERER_FILES = [
  'worker/src/templates.js',
  'worker/src/admin.js',
  'worker/src/comments.js',
  'worker/src/routes.js',
];
const HANDBOOK = 'docs/skin-contract.json';

const norm = (t) => {
  const m = /^\{\{[#/]IF_([A-Za-z0-9_]+)\}\}$/.exec(t);
  return m ? 'IF:' + m[1] : t;
};

/* ---------- 1) 扫渲染器：只有"确实被替换"的才算 ---------- */
const renderer = new Map();   // normToken -> [loc]
for (const rel of RENDERER_FILES) {
  const src = fs.readFileSync(path.join(ROOT, rel), 'utf8');
  const file = path.basename(rel);
  const lineOf = (i) => src.slice(0, i).split('\n').length;
  const add = (tok, i) => {
    const k = norm(tok);
    if (!renderer.has(k)) renderer.set(k, []);
    renderer.get(k).push(`${file}:${lineOf(i)}`);
  };
  for (const m of src.matchAll(/cfseReplace\(\s*[A-Za-z0-9_$]+\s*,\s*'(\{\{[^{}]*\}\})'/g)) add(m[1], m.index);
  for (const m of src.matchAll(/\brep\(\s*(?:[A-Za-z0-9_$]+\s*,\s*)?'(\{\{[^{}]*\}\})'/g)) add(m[1], m.index);
  for (const m of src.matchAll(/\.split\(\s*'(\{\{[^{}]*\}\})'\s*\)/g)) add(m[1], m.index);
  for (const m of src.matchAll(/\.?(?:cfse)?[Ii]fBlock\(\s*[A-Za-z0-9_$]+\s*,\s*'([A-Za-z0-9_]+)'/g)) add('IF:' + m[1], m.index);
}

/* ---------- 2) 读手册 ---------- */
const hb = JSON.parse(fs.readFileSync(path.join(ROOT, HANDBOOK), 'utf8'));
const manual = new Map(Object.keys(hb.tokens || {}).map((k) => [norm(k), k]));

/* ---------- 3) 双向比对 ---------- */
const missingInManual = [...renderer.keys()].filter((k) => !manual.has(k)).sort();   // 渲染器有、手册没写
const missingInRenderer = [...manual.keys()].filter((k) => !renderer.has(k)).sort(); // 手册写了、渲染器没有

console.log('渲染器 token: ' + renderer.size + '    手册 token: ' + manual.size);
if (missingInManual.length) {
  console.log('\n❌ 渲染器支持但手册没写（' + missingInManual.length + '）：');
  for (const k of missingInManual) console.log('   ' + k + '   ← ' + renderer.get(k).join(', '));
}
if (missingInRenderer.length) {
  console.log('\n❌ 手册写了但渲染器不替换（' + missingInRenderer.length + '）—— 这些写了会被静默清空：');
  for (const k of missingInRenderer) console.log('   ' + k);
}

/* ---------- 4) 附加：rules 里的 token 引用（warning） ---------- */
// 文档里的教学反例（明说“这样写不行”），不算过期引用
const EXAMPLES = new Set(['{{XXX}}', '{{POST_MODIFIED}}']);
const text = JSON.stringify(hb.rules || []);
const referenced = new Set();
for (const m of text.matchAll(/~~(\{\{[^{}]*\}\})~~|\{\{[^{}]*\}\}/g)) {
  const tok = m[1] || m[0];
  if (m[1]) continue;                       // ~~…~~ 是显式反例，豁免
  if (EXAMPLES.has(tok)) continue;          // 教学反例豁免（见上）
  if (!/^\{\{[A-Za-z0-9_]+\}\}$/.test(tok)) continue;   // 只查"看起来是 token"的
  referenced.add(norm(tok));
}
const staleRefs = [...referenced].filter((k) => !manual.has(k)).sort();
if (staleRefs.length) {
  console.log('\n⚠️  rules 文本里引用了手册中不存在的 token（warning，散文可能过期）：');
  console.log('   ' + staleRefs.join(' '));
}

/* ---------- 5) 结论 ---------- */
if (missingInManual.length || missingInRenderer.length) {
  console.log('\n结论：不一致 —— 手册与渲染器已漂移。');
  process.exit(1);
}
console.log('\n结论：一致 ✓（手册 ≡ 渲染器实际替换集）');
