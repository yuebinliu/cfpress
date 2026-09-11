#!/usr/bin/env node
/**
 * 内置风格清单：部署前扫描 static/wp-content/themes/<slug>/tpl.json → 直接注入 KV tpl:index
 *
 * 为什么不再用 index.json 静态文件：文件是"滞后的真相"——往风格目录里放了新风格，
 * 文件不会自己更新，Worker 读它就永远只有旧的几个。改成"每次部署重新扫描并注入 KV"，
 * 新增/删除风格只要 `npm run deploy` 就立即反映。
 *
 * 用法（worker/ 目录）：npm run deploy  （= 本脚本 && wrangler deploy）
 */
import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';

const cwd = process.cwd();
const themesRoot = path.resolve(cwd, 'static/wp-content/themes');

// 从 wrangler.toml 解析 KV namespace id
let nsId = '';
try {
  const toml = fs.readFileSync(path.resolve(cwd, 'wrangler.toml'), 'utf8');
  const m = toml.match(/\[\[kv_namespaces\]\][\s\S]*?id\s*=\s*"([^"]+)"/);
  if (m) nsId = m[1];
} catch (e) { /* ignore */ }
if (!nsId) {
  console.error('[themes-index] 无法从 wrangler.toml 解析 KV namespace id，跳过注入');
  process.exit(0);
}

const items = [];
if (fs.existsSync(themesRoot)) {
  for (const name of fs.readdirSync(themesRoot).sort()) {
    const dir = path.join(themesRoot, name);
    let st;
    try { st = fs.statSync(dir); } catch (e) { continue; }
    if (!st.isDirectory()) continue;
    const tplFile = path.join(dir, 'tpl.json');
    if (!fs.existsSync(tplFile)) continue;      // 没有 tpl.json 的不算风格

    let displayName = name;
    try {
      const j = JSON.parse(fs.readFileSync(tplFile, 'utf8'));
      if (j && typeof j.name === 'string' && j.name.trim() !== '') displayName = j.name.trim();
    } catch (e) { /* 用目录名 */ }

    const mt = fs.statSync(tplFile).mtime;
    const pad = (n) => String(n).padStart(2, '0');
    const updated = `${mt.getUTCFullYear()}-${pad(mt.getUTCMonth() + 1)}-${pad(mt.getUTCDate())} `
      + `${pad(mt.getUTCHours())}:${pad(mt.getUTCMinutes())}:${pad(mt.getUTCSeconds())}`;

    items.push({ id: name, name: displayName, updated, source: 'builtin' });
  }
}

console.log(`[themes-index] 扫描到 ${items.length} 个内置风格：${items.map((i) => i.id).join(', ') || '(无)'}`);

const tmp = path.join(cwd, '.themes-index.tmp.json');
fs.writeFileSync(tmp, JSON.stringify(items, null, 2), 'utf8');
try {
  execFileSync(
    'npx',
    ['wrangler', 'kv:key', 'put', 'tpl:index', '--path=' + tmp, '--namespace-id=' + nsId],
    { stdio: 'inherit', shell: process.platform === 'win32' }
  );
  console.log('[themes-index] 已注入 KV tpl:index');
} catch (e) {
  console.error('[themes-index] 写入 KV 失败：' + e.message);
} finally {
  try { fs.unlinkSync(tmp); } catch (e) { /* ignore */ }
}
