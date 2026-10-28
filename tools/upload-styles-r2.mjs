/**
 * 把风格包（styles/<slug>/ 下的 tpl.json + 资源）上传到 R2。
 *   key = wp-content/themes/<slug>/<包内相对路径>
 * 与 tpl.json 里的引用路径同形 ⇒ 浏览器/预览 HTML 请求该路径时，
 * assets 未命中会自动落 R2 直出（routes.js 静态资源分支）。
 *
 * 用法（服务器上跑，文件已在本地磁盘）：
 *   node upload-styles-r2.mjs --slugs=a,b,c [--base=... --endpoint=https://doc.yuebin.uk --key=... --concurrency=6]
 *   node upload-styles-r2.mjs --all
 */
import fs from 'fs';
import path from 'path';
import crypto from 'crypto';

const args = {};
for (const a of process.argv.slice(2)) {
    const i = a.indexOf('=');
    if (i > 0) args[a.slice(2, i)] = a.slice(i + 1);
}
const BASE = args.base || '/mnt/optane/webstack/www/wp-content/plugins/cf/styles';
const ENDPOINT = (args.endpoint || 'https://doc.yuebin.uk').replace(/\/$/, '');
const AUTH_KEY = args.key || process.env.CFSE_KEY || '';
const CONC = parseInt(args.concurrency || '6', 10);
const MAX_RETRY = 4;

const MIME = {
    '.css': 'text/css; charset=utf-8',
    '.js': 'application/javascript; charset=utf-8',
    '.mjs': 'application/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.html': 'text/html; charset=utf-8',
    '.htm': 'text/html; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.gif': 'image/gif',
    '.webp': 'image/webp',
    '.avif': 'image/avif',
    '.ico': 'image/x-icon',
    '.woff': 'font/woff',
    '.woff2': 'font/woff2',
    '.ttf': 'font/ttf',
    '.otf': 'font/otf',
    '.eot': 'application/vnd.ms-fontobject',
    '.txt': 'text/plain; charset=utf-8',
    '.xml': 'application/xml; charset=utf-8',
    '.map': 'application/json; charset=utf-8',
};
const mimeOf = (f) => MIME[path.extname(f).toLowerCase()] || 'application/octet-stream';

function walk(dir) {
    const out = [];
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) out.push(...walk(p));
        else if (e.isFile()) out.push(p);
    }
    return out;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let uploaded = 0, skipped = 0, failed = 0, reqCount = 0;
const failures = [];

async function putOne(file, slug, rel) {
    const buf = fs.readFileSync(file);
    if (buf.length === 0) { skipped++; return true; }   // /upload 拒空 content；空文件本身无意义，跳过
    const body = {
        path: 'wp-content/themes/' + slug + '/' + rel,
        content: buf.toString('base64'),
        contentType: mimeOf(file),
        md5: crypto.createHash('md5').update(buf).digest('hex'),
    };
    for (let attempt = 1; attempt <= MAX_RETRY; attempt++) {
        try {
            reqCount++;
            const r = await fetch(ENDPOINT + '/upload', {
                method: 'POST',
                headers: { 'X-Auth-Key': AUTH_KEY, 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (r.status === 429) {
                console.log(`  [429] 限速，等 61s 重试 (${rel})`);
                await sleep(61000);
                continue;
            }
            const j = await r.json().catch(() => ({}));
            if (r.ok && j.success) {
                if (j.skipped) { skipped++; } else { uploaded++; }
                return true;
            }
            throw new Error('HTTP ' + r.status + ' ' + JSON.stringify(j).slice(0, 160));
        } catch (e) {
            if (attempt === MAX_RETRY) {
                failed++;
                failures.push(slug + '/' + rel + ' :: ' + e.message);
                console.log(`  ✗ ${slug}/${rel} :: ${e.message}`);
                return false;
            }
            await sleep(1500 * attempt);
        }
    }
    return false;
}

async function main() {
    let slugs = (args.slugs || '').split(',').map((s) => s.trim()).filter(Boolean);
    if (args.all) {
        slugs = fs.readdirSync(BASE, { withFileTypes: true })
            .filter((e) => e.isDirectory() && fs.existsSync(path.join(BASE, e.name, 'tpl.json')))
            .map((e) => e.name);
    }
    if (!slugs.length) { console.error('需要 --slugs=a,b 或 --all'); process.exit(1); }

    const tasks = [];
    for (const slug of slugs) {
        const dir = path.join(BASE, slug);
        if (!fs.existsSync(path.join(dir, 'tpl.json'))) { console.log(`SKIP(无 tpl.json) ${slug}`); continue; }
        for (const f of walk(dir)) tasks.push([f, slug, path.relative(dir, f).split(path.sep).join('/')]);
    }
    console.log(`待上传 ${tasks.length} 个文件（${slugs.length} 个包）→ ${ENDPOINT}  并发=${CONC}`);
    console.log('==================================================');

    let idx = 0;
    async function worker() {
        while (idx < tasks.length) {
            const t = tasks[idx++];
            const doneCount = uploaded + skipped + failed;
            if (doneCount > 0 && doneCount % 380 === 0) await sleep(61000); // 限速余量（600/分）
            await putOne(t[0], t[1], t[2]);
            const n = uploaded + skipped + failed;
            if (n % 40 === 0) console.log(`  ...进度 ${n}/${tasks.length}  (上传${uploaded} 跳过${skipped} 失败${failed})`);
        }
    }
    await Promise.all(Array.from({ length: CONC }, worker));

    console.log('==================================================');
    console.log(`完成：上传 ${uploaded}，跳过(内容一致) ${skipped}，失败 ${failed}，总请求 ${reqCount}`);
    if (failures.length) { console.log('失败清单：'); failures.slice(0, 40).forEach((f) => console.log('  ' + f)); }
}

main().catch((e) => { console.error('FATAL', e); process.exit(1); });
