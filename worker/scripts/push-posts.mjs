#!/usr/bin/env node
/**
 * push-posts.mjs —— 把 posts-src/ 下的 Markdown 文章发布到线上
 *
 * 和 push-docs.mjs 的区别：文章不是"原稿+成品"两件套，文章正文就存在 D1 的 posts 表里
 * （content_format='md'，渲染层再转 HTML），发布接口会顺带写单页、清列表缓存。
 *
 * 用法（在 worker/ 目录下）：
 *   node scripts/push-posts.mjs --token=eyJ...            # 全部
 *   node scripts/push-posts.mjs --token=eyJ... --only=slug
 *   node scripts/push-posts.mjs --token=eyJ... --dry-run
 *   node scripts/push-posts.mjs --token=$CFP_TOKEN --base=https://cfpress.org
 *
 * 已存在（按 slug 判断）就 PUT 更新，不存在就 POST 创建 —— 可以反复跑。
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const srcDir = path.resolve(root, '..', 'posts-src');

const argv = process.argv.slice(2);
const flag = (n) => argv.includes('--' + n);
const arg = (n, d = '') => {
    const hit = argv.find((a) => a.startsWith('--' + n + '='));
    return hit ? hit.slice(n.length + 3) : d;
};

const base = arg('base', 'https://cfpress.org').replace(/\/+$/, '');
const token = arg('token', process.env.CFP_TOKEN || '');
const dryRun = flag('dry-run');
const only = arg('only', '');

if (!token && !dryRun) {
    console.error('缺少 --token=（或环境变量 CFP_TOKEN）。它是一次性的 access token，不是登录密码。');
    process.exit(1);
}

function parseFrontmatter(src) {
    const s = String(src || '');
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

async function api(method, p, body) {
    const res = await fetch(base + p, {
        method,
        headers: Object.assign({ Authorization: 'Bearer ' + token }, body ? { 'Content-Type': 'application/json' } : {}),
        body: body ? JSON.stringify(body) : undefined,
    });
    const text = await res.text();
    let json = {};
    try { json = JSON.parse(text); } catch { json = { raw: text.slice(0, 200) }; }
    if (res.status >= 400) throw new Error(method + ' ' + p + ' → ' + res.status + ' ' + (json.error || ''));
    return json;
}

const files = fs.existsSync(srcDir)
    ? fs.readdirSync(srcDir).filter((f) => f.endsWith('.md')).sort()
    : [];

if (!files.length) {
    console.error('posts-src/ 里没有 .md 文件：' + srcDir);
    process.exit(1);
}

// 现有文章：slug → wp_id（用于判断 POST 还是 PUT）
let existing = {};
if (!dryRun) {
    const list = await api('GET', '/api/posts?per=100&status=published');
    for (const p of list.posts || []) existing[p.slug] = p.wp_id;
    const drafts = await api('GET', '/api/posts?per=100&status=draft');
    for (const p of drafts.posts || []) existing[p.slug] = p.wp_id;
}

let created = 0, updated = 0;

for (const file of files) {
    const raw = fs.readFileSync(path.join(srcDir, file), 'utf8');
    const { data, body } = parseFrontmatter(raw);
    const slug = String(data.slug || file.replace(/\.md$/, '')).trim();
    if (only && slug !== only) continue;

    const categories = data.category ? [{ name: data.category, slug: data.category.toLowerCase().replace(/[^a-z0-9]+/g, '-') }] : [];
    const tags = data.tags ? String(data.tags).split(',').map((t) => ({ name: t.trim(), slug: t.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-') })).filter((t) => t.name) : [];

    const payload = {
        title: data.title || slug,
        slug,
        content: body.trim(),
        content_format: 'md',
        status: 'published',
        categories,
        tags,
    };
    // frontmatter 里的 excerpt 优先于自动截断 —— 自动摘要会把句子切成半截
    if (data.excerpt) payload.excerpt = String(data.excerpt).trim();

    if (dryRun) {
        console.log('[dry-run] ' + (existing[slug] ? 'PUT  ' : 'POST ') + slug + '  (' + body.length + ' 字符, ' + tags.length + ' tags)');
        continue;
    }

    if (existing[slug]) {
        await api('PUT', '/api/posts/' + existing[slug], payload);
        console.log('更新  ' + slug);
        updated++;
    } else {
        const r = await api('POST', '/api/posts', payload);
        console.log('新建  ' + slug + ' → ' + r.slug);
        created++;
    }
}

console.log('\n完成：新建 ' + created + '，更新 ' + updated + (dryRun ? '（dry-run，未改动线上）' : ''));
if (!dryRun) console.log('提示：首页列表是实时查 D1 的，立即可见；RSS 有 TTL，要立刻看就清一次缓存。');
