#!/usr/bin/env node
/**
 * push-docs.mjs —— 把 content/ 下的 Markdown 原稿同步到线上
 *
 * 每篇做三件事（第 3 步最容易忘，忘了就是"改了没生效"）：
 *   1. 原稿    → R2  _src/<库>/<相对路径>
 *   2. 元数据  → D1  documents（title 取第一个 H1，excerpt 取首个正文段落）
 *   3. 删成品  → R2  _pages/<库>/<相对路径>.html + 该层及所有祖先的 index.html
 *
 * 目录约定：content/<库>/<路径>.md  ⇒  _src/<库>/<路径>.md  ⇒  /<库>/<路径>.html
 * （<库> 同时是 docRoutes 的 abc 与 xyz；若将来两者不同，加 --map 支持）
 *
 * 用法（在 worker/ 目录下）：
 *   node scripts/push-docs.mjs                    # 全部库
 *   node scripts/push-docs.mjs --only=plugin      # 只推一个库
 *   node scripts/push-docs.mjs --dry-run          # 只打印命令，不动线上
 *   node scripts/push-docs.mjs --bucket=cfpress --db=cfpress --config=wrangler.prod.toml
 */
import { execSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

/**
 * 剥 YAML frontmatter（只认文件开头）。
 * 这里自带一份而不是 import src/md.js：package.json 没有 "type": "module"，
 * 从 .mjs 里 import .js 会触发 MODULE_TYPELESS_PACKAGE_JSON 警告并重新解析一遍。
 */
function parseFrontmatter(src) {
    const s = String(src == null ? '' : src);
    const m = s.match(/^---[ \t]*\r?\n([\s\S]*?)\r?\n---[ \t]*(?:\r?\n|$)/);
    if (!m) return { data: {}, body: s };
    const data = {};
    for (const line of m[1].split(/\r?\n/)) {
        const hit = line.match(/^\s*([A-Za-z0-9_-]+)\s*:\s*(.*)$/);
        if (!hit) continue;
        let v = hit[2].trim();
        if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1);
        data[hit[1].toLowerCase()] = v;
    }
    return { data, body: s.slice(m[0].length) };
}

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const contentDir = path.resolve(root, '..', 'content');

const argv = process.argv.slice(2);
const dryRun = argv.includes('--dry-run');
function arg(name, dflt) {
    const hit = argv.find((a) => a.startsWith('--' + name + '='));
    return hit ? hit.slice(name.length + 3) : dflt;
}
const only = arg('only', '');
const bucket = arg('bucket', 'cfpress');
const dbName = arg('db', 'cfpress');
const config = arg('config', 'wrangler.prod.toml');

function say(msg) { console.log(msg); }

function run(cmd) {
    if (dryRun) { say('  [dry-run] ' + cmd); return ''; }
    try {
        return execSync(cmd, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
    } catch (e) {
        const err = String((e && (e.stderr || e.stdout || e.message)) || e).split('\n').filter(Boolean)[0] || '';
        throw new Error(err || 'command failed: ' + cmd);
    }
}

/** 递归收集 .md（跳过隐藏项），返回 [{ full, rel }]，rel 相对库目录 */
function walk(dir, base) {
    const out = [];
    for (const name of readdirSync(dir).sort()) {
        if (name.startsWith('.')) continue;
        const full = path.join(dir, name);
        const rel = base ? base + '/' + name : name;
        if (statSync(full).isDirectory()) out.push(...walk(full, rel));
        else if (/\.md$/i.test(name)) out.push({ full, rel });
    }
    return out;
}

/** 标题：frontmatter.title > 第一个 H1 > 文件名（侧栏要短、正文要完整时用 frontmatter） */
function titleOf(md, rel) {
    const { data, body } = parseFrontmatter(md);
    if (data.title) return String(data.title).replace(/\s+/g, ' ').slice(0, 200);
    const m = String(body).match(/^#\s+(.+)$/m);
    const fallback = rel.replace(/\.md$/i, '').split('/').pop() || 'doc';
    return (m ? m[1].trim() : fallback).replace(/\s+/g, ' ').slice(0, 200);
}

/** 摘要：frontmatter.description > 第一段正文（跳过标题/表格/代码块/列表），去掉行内标记 */
function excerptOf(md) {
    const { data, body } = parseFrontmatter(md);
    if (data.description) return String(data.description).replace(/\s+/g, ' ').slice(0, 180);
    for (const raw of String(body).split(/\r?\n/)) {
        const t = raw.trim();
        if (!t) continue;
        if (t.startsWith('#') || t.startsWith('|') || t.startsWith('```') || t.startsWith('>') || t.startsWith('- ') || t.startsWith('* ')) continue;
        const plain = t
            .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
            .replace(/\[([^\]]*)\]\([^)]*\)/g, '$1')
            .replace(/[*_`~]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
        if (plain) return plain.slice(0, 180);
    }
    return '';
}

/** SQL 字符串字面量（单引号翻倍） */
function q(s) { return "'" + String(s == null ? '' : s).replace(/'/g, "''") + "'"; }

/**
 * 一篇文档会取消哪些成品：
 *   自己那页 + 该层 index.html + 每一层祖先的 index.html（去重）
 * 撤销 index.html 是必须的 —— 目录列表是派生数据，子文档变了它就过期了。
 */
function outKeysFor(lib, rel) {
    const noExt = rel.replace(/\.md$/i, '');
    const segs = noExt.split('/');
    segs.pop();                                  // 去掉文件名，只剩目录链
    const keys = new Set();
    keys.add('_pages/' + lib + '/' + noExt + '.html');
    for (let i = segs.length; i >= 0; i--) {
        const d = i ? segs.slice(0, i).join('/') : '';
        keys.add('_pages/' + lib + '/' + (d ? d + '/' : '') + 'index.html');
    }
    return Array.from(keys);
}

// ── 主流程 ────────────────────────────────────────────────────
if (!statSync(contentDir, { throwIfNoEntry: false })) {
    say('找不到 content/ 目录：' + contentDir);
    process.exit(1);
}

const libs = readdirSync(contentDir)
    .filter((n) => !n.startsWith('.') && n !== 'README.md')
    .filter((n) => statSync(path.join(contentDir, n)).isDirectory())
    .filter((n) => !only || n === only);

if (!libs.length) {
    say(only ? '没有这个库：' + only : 'content/ 下没有库目录');
    process.exit(1);
}

const today = new Date().toISOString().slice(0, 10);
const rows = [];
let uploaded = 0, cleared = 0;

for (const lib of libs) {
    const files = walk(path.join(contentDir, lib), '');
    say('\n[' + lib + '] ' + files.length + ' 篇');
    for (const f of files) {
        const md = readFileSync(f.full, 'utf8');
        const srcKey = '_src/' + lib + '/' + f.rel;
        const ctype = 'text/markdown; charset=utf-8';

        say('  ↑ ' + f.rel);
        run('npx wrangler r2 object put "' + bucket + '/' + srcKey + '" --file="' + f.full + '" --content-type="' + ctype + '"');
        uploaded++;

        for (const ok of outKeysFor(lib, f.rel)) {
            if (dryRun) { say('    [dry-run] delete ' + ok); continue; }
            try {
                execSync('npx wrangler r2 object delete "' + bucket + '/' + ok + '"', { cwd: root, stdio: ['ignore', 'ignore', 'ignore'] });
                cleared++;
            } catch (e) { /* 不存在就算了 */ }
        }

        rows.push('  (' + [q(lib), q(f.rel), q('md'), q(titleOf(md, f.rel)), q(excerptOf(md)), q('published'), q(today), q(today)].join(',') + ')');
    }
}

// 元数据一次性提交（比逐篇 execute 快得多，也少一大堆子请求）
const sqlPath = path.join(root, '.push-docs.sql');
const sql = 'INSERT INTO documents (source_prefix, path, format, title, excerpt, status, date, updated_at) VALUES\n'
    + rows.join(',\n') + '\n'
    + 'ON CONFLICT(source_prefix, path) DO UPDATE SET\n'
    + '  format = excluded.format, title = excluded.title, excerpt = excluded.excerpt,\n'
    + '  status = excluded.status, updated_at = excluded.updated_at;\n';
writeFileSync(sqlPath, sql, 'utf8');

say('\n[documents] ' + rows.length + ' 条元数据');
run('npx wrangler d1 execute ' + dbName + ' --remote --config ' + config + ' --file="' + sqlPath + '"');
if (!dryRun) { try { unlinkSync(sqlPath); } catch (e) { /* ignore */ } }

say('\n完成：上传 ' + uploaded + ' 篇，清理成品 ' + cleared + ' 个 key'
    + (dryRun ? '（dry-run，未改动线上）' : ''));
say('提示：边缘缓存最多还留 60 秒；要立刻看新版就带 ?v=1 访问。');
