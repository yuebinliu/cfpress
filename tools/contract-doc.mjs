#!/usr/bin/env node
/**
 * 手册生成器：docs/skin-contract.json  →  docs/skin-contract.md
 *   node tools/contract-doc.mjs
 * 只做搬运，不重排、不摘要：rule 输出原文，prose 原样贴出（散文不失真）。
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const hb = JSON.parse(fs.readFileSync(path.join(ROOT, 'docs/skin-contract.json'), 'utf8'));

const GROUP_TITLE = {
  site: '站点级（值来自 site:ctx，后台可配）',
  'list-pages': '页面壳（首页 / 分类 / 标签 / 归档 / 搜索）',
  post: '文章（列表条目 item 与单页都用）',
  'single-only': '仅单页 single',
  'sidebar-skeleton': '侧边栏骨架段（sidebar）内部',
  'comment-skeleton': '评论骨架段（comments）内部',
  conditionals: '条件块（{{#IF_X}} … {{/IF_X}}）',
  other: '其它',
};

const cell = (s, max = 110) => {
  let v = String(s == null ? '' : s).replace(/\|/g, '\\|').replace(/\r?\n/g, ' ').trim();
  if (v.length > max) v = v.slice(0, max) + '…';
  return v || '—';
};

const out = [];
out.push('# 风格模板契约（手册）', '');
out.push('> ⚙️ **本文件由 `tools/contract-doc.mjs` 从 `docs/skin-contract.json` 生成 —— 不要手改。**');
out.push('> 改契约请改 json；工程/审计内容（行号、覆盖率、待办）见 `docs/skin-audit.json`。');
out.push('> 一致性由 `node tools/contract-check.mjs` 断言（手册 ≡ 渲染器实际替换集）。', '');
out.push(hb.about || '', '');
out.push(`共 **${hb.counts.total}** 项：${hb.counts.placeholders} 个占位符 + ${hb.counts.conditionals} 个条件块，分 ${hb.counts.groups} 组。`, '');

/* ---------- 规则 ---------- */
out.push('## 规则', '');
for (const r of hb.rules || []) {
  out.push('### ' + r.title, '');
  if (r.kind === 'prose') out.push(r.md || '', '');
  else out.push(r.text || '', '');
}

/* ---------- 命名 ---------- */
if (hb.naming) {
  out.push('## 命名规则', '');
  for (const [k, v] of Object.entries(hb.naming)) out.push(`- **${k}**：${v}`);
  out.push('');
}
if (hb.tokenVsLabel) {
  out.push('## token 与 label（别混）', '');
  for (const [k, v] of Object.entries(hb.tokenVsLabel)) out.push(`- **${k}**：${v}`);
  out.push('');
}

/* ---------- 字段说明 ---------- */
out.push('## 字段说明', '');
for (const [k, v] of Object.entries(hb.columns || {})) out.push(`- **${k}**：${v}`);
out.push('');

/* ---------- tokens 按组 ---------- */
const order = ['site', 'list-pages', 'post', 'single-only', 'sidebar-skeleton', 'comment-skeleton', 'conditionals', 'other'];
out.push('## 占位符与条件块', '');
for (const g of order) {
  const rows = Object.values(hb.tokens).filter((t) => t.group === g);
  if (!rows.length) continue;
  out.push('### ' + (GROUP_TITLE[g] || g), '');
  if (g === 'conditionals') {
    out.push('| 条件块 | 条件 | 可用模板 | 说明 |', '|---|---|---|---|');
    for (const t of rows.sort((a, b) => a.token.localeCompare(b.token))) {
      out.push(`| \`${t.flag}\` | ${cell(t.condition)} | ${cell((t.pages || []).join(', '))} | ${cell(t.note)} |`);
    }
  } else {
    out.push('| 占位符 | 值形态 | 转义 | 可用模板 | 输出 / 说明 |', '|---|---|---|---|---|');
    for (const t of rows.sort((a, b) => a.token.localeCompare(b.token))) {
      const desc = [t.emitted_form, t.note].filter(Boolean).join(' ｜ ');
      out.push(`| \`${t.token}\` | ${cell(t.scope, 20)} | ${cell(t.escape, 10)} | ${cell((t.pages || []).join(', '), 80)} | ${cell(desc)} |`);
    }
  }
  out.push('');
}

fs.writeFileSync(path.join(ROOT, 'docs/skin-contract.md'), out.join('\n'));
console.log('已生成 docs/skin-contract.md（' + out.length + ' 行）');
