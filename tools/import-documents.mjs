#!/usr/bin/env node
/**
 * import-documents.mjs —— 文档库批量导入脚本（本地 Node 跑）
 *
 * 遍历本地目录 → 每个 md/html 文件：
 *   1. 读 frontmatter（title/date/description）或默认（文件名=title，mtime=date）
 *   2. 格式判定：.md → 'md'；.html → 'html'
 *   3. R2 原稿：POST /api/documents/upsert（content_base64）
 *   4. 元数据写 D1（upsert 内完成）
 * 全部上传后 → 调 /api/documents/rebuild 整库重建（一次生成全部成品）
 *
 * 用法：
 *   node tools/import-documents.mjs \
 *     --base=https://doc.yuebin.uk --token=<JWT access token> \
 *     --abc=docs --dir=./my-docs [--dry-run]
 *
 * 说明：
 *   - token：Worker 后台登录后从 localStorage/响应里取 access token（Bearer）
 *   - abc：对应后台 docRoutes 里配置的 abc（原稿目录）
 *   - 不要求 xyz：成品路径由 docRoutes 推导
 */
import fs from 'fs';
import path from 'path';

function parseArgs(argv) {
  const out = {};
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith('--')) {
      const eq = a.indexOf('=');
      if (eq > 0) { out[a.slice(2, eq)] = a.slice(eq + 1); }
      else { out[a.slice(2)] = true; }
    }
  }
  return out;
}

function readFrontmatter(text) {
  const m = String(text).match(/^---\s*\n([\s\S]*?)\n---/);
  const meta = {};
  if (m) {
    const body = m[1];
    const get = (k) => {
      const mm = body.match(new RegExp('^\\s*' + k + '\\s*:\\s*(.+)$', 'm'));
      if (mm) return String(mm[1]).trim().replace(/^["']|["']$/g, '');
      return '';
    };
    meta.title = get('title');
    meta.date = get('date');
    meta.description = get('description');
  }
  return meta;
}

function extFormat(file) {
  if (/\.md$/i.test(file)) return 'md';
  if (/\.(html|htm)$/i.test(file)) return 'html';
  return null;
}

async function main() {
  const args = parseArgs(process.argv);
  const base = String(args.base || '').replace(/\/+$/, '');
  const token = String(args.token || '');
  const abc = String(args.abc || '').replace(/^\/+|\/+$/g, '');
  const dir = String(args.dir || '');
  const dry = !!args['dry-run'];
  if (!base || !token || !abc || !dir) {
    console.error('用法：node tools/import-documents.mjs --base=<站点URL> --token=<JWT> --abc=<原稿目录> --dir=<本地目录> [--dry-run]');
    process.exit(1);
  }
  if (!fs.existsSync(dir)) { console.error('目录不存在：' + dir); process.exit(1); }

  // 遍历目录收集 md/html
  const files = [];
  (function walk(d) {
    for (const ent of fs.readdirSync(d, { withFileTypes: true })) {
      const full = path.join(d, ent.name);
      if (ent.isDirectory()) { walk(full); continue; }
      if (extFormat(ent.name)) {
        // 相对路径（相对 dir，POSIX 分隔符）
        const rel = path.relative(dir, full).split(path.sep).join('/');
        files.push({ full, rel, format: extFormat(ent.name) });
      }
    }
  })(dir);
  files.sort((a, b) => a.rel.localeCompare(b.rel));
  console.log(`扫描到 ${files.length} 个文档`);

  // 校验：末段不能是 index
  const bad = files.filter((f) => {
    const baseName = f.rel.replace(/\.(md|html?)$/i, '').split('/').pop();
    return baseName.toLowerCase() === 'index';
  });
  if (bad.length) {
    console.error('以下文件末段是 index（与目录页 xyz/index.html 冲突），拒绝导入：');
    bad.forEach((b) => console.error('  ' + b.rel));
    process.exit(1);
  }

  // 组装批量载荷
  const payload = [];
  for (const f of files) {
    const text = fs.readFileSync(f.full, 'utf8');
    const meta = readFrontmatter(text);
    const title = meta.title || '';
    const b64 = Buffer.from(text, 'utf8').toString('base64');
    if (dry) { console.log(`[dry-run] ${f.rel} (${f.format}) title="${title}"`); continue; }
    payload.push({ path: f.rel, title, content_base64: b64 });
  }
  if (dry) { console.log(`[dry-run] 结束，未做任何写入（共 ${files.length} 篇）`); return; }

  console.log(`批量导入 ${abc}：${payload.length} 篇（一次请求，含整库重建）`);
  const r = await fetch(base + '/api/documents/import', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
    body: JSON.stringify({ abc, files: payload, rebuild: true }),
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) { console.error('批量导入失败：' + (j.error || r.status)); process.exit(1); }
  console.log(`上传：${j.success} 成功 / ${j.fail} 失败 / 共 ${j.total}`);
  (j.errors || []).forEach((e) => console.error('  FAIL ' + e.path + ': ' + e.error));
  console.log('重建结果：' + JSON.stringify(j.rebuild));
  if (j.fail > 0) { console.error('有失败项，请修复后重试'); process.exit(1); }
}

main().catch((e) => { console.error(e); process.exit(1); });
