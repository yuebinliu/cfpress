#!/usr/bin/env node
/**
 * 扫描 worker/static/wp-content/themes/<slug>/tpl.json → 生成内置风格包清单 index.json
 * （权威清单：与风格目录同层；Worker 端 KV tpl:index 缺失时会回退读它）
 *
 * 用法：在 worker/ 目录下执行
 *   node scripts/build-themes-index.mjs
 * 已挂到 package.json 的 deploy 脚本：npm run deploy
 */
import fs from 'fs';
import path from 'path';

const themesRoot = path.resolve(process.cwd(), 'static/wp-content/themes');
if (!fs.existsSync(themesRoot)) {
  console.log('[themes-index] 目录不存在，跳过:', themesRoot);
  process.exit(0);
}

const items = [];
for (const name of fs.readdirSync(themesRoot).sort()) {
  const dir = path.join(themesRoot, name);
  let st;
  try { st = fs.statSync(dir); } catch (e) { continue; }
  if (!st.isDirectory()) continue;

  const tplFile = path.join(dir, 'tpl.json');
  if (!fs.existsSync(tplFile)) continue;

  let displayName = name;
  try {
    const j = JSON.parse(fs.readFileSync(tplFile, 'utf8'));
    if (j && typeof j.name === 'string' && j.name.trim() !== '') displayName = j.name.trim();
  } catch (e) { /* 解析失败就用目录名 */ }

  const mt = fs.statSync(tplFile).mtime;
  const pad = (n) => String(n).padStart(2, '0');
  const updated = `${mt.getUTCFullYear()}-${pad(mt.getUTCMonth() + 1)}-${pad(mt.getUTCDate())} ${pad(mt.getUTCHours())}:${pad(mt.getUTCMinutes())}:${pad(mt.getUTCSeconds())}`;

  items.push({ id: name, name: displayName, updated, source: 'builtin' });
}

const outFile = path.join(themesRoot, 'index.json');
fs.writeFileSync(outFile, JSON.stringify(items, null, 2) + '\n', 'utf8');
console.log(`[themes-index] 写入 ${items.length} 个内置风格 -> ${path.relative(process.cwd(), outFile)}`);
for (const it of items) console.log('  -', it.id, '(' + it.name + ')');
