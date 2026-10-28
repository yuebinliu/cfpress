/**
 * 循环预览：把每个风格包的 tpl.json 交给 Worker 的 style/preview 渲染，
 * 落盘 6 页 HTML + 一个总览索引页（本地浏览器打开即可挑风格）。
 *
 * 用法：node tools/preview-styles.mjs [--slugs=a,b] [--out=tmp/preview] [--endpoint=https://doc.yuebin.uk]
 *
 * 说明：渲染在 Worker 侧（与线上同一套 tplPreviewHtml），资源走 R2（需先跑 tools/upload-styles-r2.mjs）。
 *      支持「只补跑某些包」——结果与 tmp/preview/results.json 合并，索引页不会丢其它包。
 */
import fs from 'fs';
import path from 'path';

const args = {};
for (const a of process.argv.slice(2)) {
    const i = a.indexOf('=');
    if (i > 0) args[a.slice(2, i)] = a.slice(i + 1);
}
const ENDPOINT = (args.endpoint || 'https://doc.yuebin.uk').replace(/\/$/, '');
const OUT = args.out || 'tmp/preview';
const USER = args.user || 'cfse_admin';
const PASS = args.pass || 'TestPass123!';
const KINDS = ['pageHome', 'pageCategory', 'pageTag', 'pageArchive', 'single', 'item'];
const KIND_LABEL = { pageHome: '首页', pageCategory: '分类', pageTag: '标签', pageArchive: '归档', single: '单页', item: '列表条目' };
const STYLE_NAMES = {
    'agencygrove-dark': 'AgencyGrove Dark', 'bluehost-blueprint': 'Bluehost Blueprint', booknest: 'BookNest',
    'consultinggrove-dark': 'ConsultingGrove Dark', 'denovr-lite': 'Denovr Lite', extendable: 'Extendable',
    'idea-flow': 'Idea Flow', 'kitchio-lite': 'Kitchio Lite', lexionic: 'Lexionic', 'neve-fse': 'Neve FSE',
    ollie: 'Ollie', 'ona-architecture': 'Ona Architecture', 'online-courses-fse': 'Online Courses FSE',
    raft: 'Raft', saaslauncher: 'SaaSLauncher', 'savara-lite': 'Savara Lite', 'spectra-one': 'Spectra One',
    'the-coffee-shop': 'The Coffee Shop', 'the-family-blog': 'The Family Blog', 'therapy-coach': 'Therapy Coach',
    'tourze-lite': 'Tourze Lite', twentytwentyfive: 'Twenty Twenty-Five', twentytwentyfour: 'Twenty Twenty-Four',
    twentytwentythree: 'Twenty Twenty-Three', twentytwentytwo: 'Twenty Twenty-Two', unibiz: 'UniBiz',
    variations: 'Variations', 'webrand-lite': 'WeBrand Lite', 'yith-wonder': 'YITH Wonder',
};

const DEFAULT_SLUGS = ['agencygrove-dark', 'bluehost-blueprint', 'booknest', 'consultinggrove-dark', 'denovr-lite',
    'extendable', 'idea-flow', 'kitchio-lite', 'lexionic', 'neve-fse', 'ollie', 'ona-architecture',
    'online-courses-fse', 'raft', 'saaslauncher', 'savara-lite', 'spectra-one', 'the-coffee-shop',
    'the-family-blog', 'therapy-coach', 'tourze-lite', 'twentytwentyfive', 'twentytwentyfour',
    'twentytwentythree', 'twentytwentytwo', 'unibiz', 'variations', 'webrand-lite', 'yith-wonder'];

const slugList = (args.slugs || '').split(',').map((s) => s.trim()).filter(Boolean);
const SLUGS = slugList.length ? slugList : DEFAULT_SLUGS;

async function login() {
    const r = await fetch(ENDPOINT + '/api/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: USER, password: PASS }),
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok || !j.access) throw new Error('登录失败：HTTP ' + r.status + ' ' + JSON.stringify(j).slice(0, 200));
    return j.access;
}

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

function anchor(html, slug, kind, name) {
    const leftovers = (html.match(/\{\{[^}]{0,60}\}\}/g) || []);
    const title = `${name} · ${KIND_LABEL[kind] || kind}`;
    // 注入 <base>：确保相对资源/链接解析到线上（tpl 内多为 {{SITE_URL}} 绝对地址，这里是兜底）
    let out = html.replace(/<head([^>]*)>/i, `<head$1>\n<title>${esc(title)}</title>\n<base href="${ENDPOINT}/">`);
    if (!/<head/i.test(html)) out = `<base href="${ENDPOINT}/">\n` + out;
    return { html: out, leftovers };
}

async function main() {
    fs.mkdirSync(OUT, { recursive: true });
    const token = await login();
    console.log('已登录，开始循环预览 ' + SLUGS.length + ' 个风格包 → ' + OUT);

    const results = [];
    for (const slug of SLUGS) {
        const name = STYLE_NAMES[slug] || slug;
        const dir = path.join(OUT, slug);
        fs.mkdirSync(dir, { recursive: true });
        let tplJson = null;
        try {
            const r = await fetch(`${ENDPOINT}/wp-content/themes/${slug}/tpl.json`, { cache: 'no-store' });
            if (!r.ok) throw new Error('HTTP ' + r.status);
            tplJson = await r.json();
        } catch (e) {
            console.log(`  ✗ ${slug} 取 tpl.json 失败：${e.message}（R2 未上传完成？）`);
            results.push({ slug, name, ok: false, err: e.message });
            continue;
        }
        let pages = [];
        try {
            const r = await fetch(ENDPOINT + '/api/templates/style/preview', {
                method: 'POST',
                headers: { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' },
                body: JSON.stringify({ tpl: tplJson.tpl || {}, id: slug }),
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok || !j.pages) throw new Error('HTTP ' + r.status + ' ' + JSON.stringify(j).slice(0, 160));
            pages = j.pages;
        } catch (e) {
            console.log(`  ✗ ${slug} 预览渲染失败：${e.message}`);
            results.push({ slug, name, ok: false, err: e.message });
            continue;
        }
        let leftoverTotal = 0;
        for (const pg of pages) {
            const a = anchor(pg.html || '', slug, pg.kind, name);
            leftoverTotal += a.leftovers.length;
            const file = path.join(dir, pg.kind + '.html');
            fs.writeFileSync(file, a.html, 'utf8');
        }
        const sizes = pages.map((p) => (p.html || '').length);
        console.log(`  ✓ ${slug.padEnd(22)} 6 页  ${sizes.join('/')}  chars  占位符残留=${leftoverTotal}`);
        results.push({ slug, name, ok: true, leftoverTotal, sizes });
    }

    // ---- 总览索引页 ----
    // 与其他轮次的结果合并（支持「只补跑某个包」而不丢索引里的其它包）
    let prevResults = [];
    try { prevResults = JSON.parse(fs.readFileSync(path.join(OUT, 'results.json'), 'utf8')); } catch (e) { prevResults = []; }
    const pool = new Map(prevResults.map((r) => [r.slug, r]));
    for (const r of results) pool.set(r.slug, r);
    const merged = [];
    for (const s of [...new Set([...SLUGS, ...DEFAULT_SLUGS])]) {
        if (pool.has(s)) { merged.push(pool.get(s)); pool.delete(s); }
    }
    for (const r of pool.values()) merged.push(r);

    const okList = merged.filter((r) => r.ok);
    const nav = merged.map((r) => {
        if (!r.ok) return `<option value="" disabled>${esc(r.name)}（失败：${esc(r.err)}）</option>`;
        return `<option value="${esc(r.slug)}">${esc(r.name)}${r.leftoverTotal ? `  ⚠️残留${r.leftoverTotal}` : ''}</option>`;
    }).join('\n');
    const indexHtml = `<!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>风格画廊预览（${okList.length} 套）</title>
<style>
  body{margin:0;font:14px/1.5 system-ui,"Segoe UI",sans-serif;display:flex;flex-direction:column;height:100vh}
  header{padding:8px 12px;border-bottom:1px solid #ddd;display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#fafafa}
  select{padding:4px 6px;font-size:14px}
  a.btn{padding:4px 10px;border:1px solid #bbb;border-radius:4px;text-decoration:none;color:#333;background:#fff}
  .muted{color:#888}
  iframe{flex:1;border:0;width:100%}
</style></head><body>
<header>
  <strong>风格画廊</strong>
  <label>主题 <select id="t">${nav}</select></label>
  <label>页面 <select id="k">${KINDS.map((k) => `<option value="${k}">${KIND_LABEL[k]}</option>`).join('')}</select></label>
  <a class="btn" id="open" href="#" target="_blank">↗ 新窗口打开</a>
  <span class="muted">共 ${okList.length} 套成功 · 数据源：线上 R2 + Worker 渲染</span>
</header>
<iframe id="f"></iframe>
<script>
  const t = document.getElementById('t'), k = document.getElementById('k'), f = document.getElementById('f'), open = document.getElementById('open');
  function go(){ if(!t.value) return; const u = t.value + '/' + k.value + '.html'; f.src = u; open.href = u; }
  t.addEventListener('change', go); k.addEventListener('change', go); go();
</script>
</body></html>`;
    fs.writeFileSync(path.join(OUT, 'index.html'), indexHtml, 'utf8');
    fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(merged, null, 2), 'utf8');

    console.log('--------------------------------------------------');
    console.log(`完成：成功 ${okList.length}/${merged.length}`);
    console.log('索引页：' + path.resolve(path.join(OUT, 'index.html')));
}

main().catch((e) => { console.error('FATAL', e); process.exit(1); });
