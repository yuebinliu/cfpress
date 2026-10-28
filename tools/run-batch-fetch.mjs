/**
 * 批量流程编排（本地跑）：
 *   1) 从 wp.org 拉「块主题」列表并下载 zip（本地能访问 wp.org；服务器不行）
 *   2) 把 zip 上传到服务器 /tmp/wp-themes/
 *   3) 在服务器上 nohup 跑「解压 → 逐个提取风格包 → 写日志」（不依赖本会话，可跨会话继续）
 *
 * 产出：/mnt/optane/webstack/www/wp-content/plugins/cf/styles/<slug>/（tpl.json + 主题资源，扁平）
 * 日志：/mnt/optane/webstack/www/cfse-batch-fetch.log
 * 下载的主题**保留**在服务器 wp-content/themes/（用户要本地看风格）
 */
import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';

const ROOT = 'C:/Users/dog/Documents/cf';
const OUT = path.join(ROOT, 'tmp/wp-themes');
const N = 30;
const HOST = 'root@192.168.2.2';
const PW = process.env.CFSE_SSH_PW || '';

const log = (m) => { const s = '[' + new Date().toISOString().slice(11, 19) + '] ' + m; console.log(s); };

// ---------- 1) 列表 + 下载 ----------
fs.mkdirSync(OUT, { recursive: true });
const api = 'https://api.wordpress.org/themes/info/1.2/?action=query_themes'
  + '&request[browse]=popular&request[tag]=full-site-editing&request[per_page]=' + N
  + '&request[fields][description]=0&request[fields][sections]=0';
const themes = ((await (await fetch(api)).json()).themes || []).filter((t) => t && t.slug);
log('wp.org 块主题 ' + themes.length + ' 个：' + themes.map((t) => t.slug).join(' '));

for (const t of themes) {
  const f = path.join(OUT, t.slug + '.zip');
  if (fs.existsSync(f) && fs.statSync(f).size > 10000) { log('  跳过（已下载） ' + t.slug); continue; }
  try {
    const r = await fetch(t.download_link || ('https://downloads.wordpress.org/theme/' + t.slug + '.zip'));
    if (!r.ok) { log('  ✗ ' + t.slug + ' HTTP ' + r.status); continue; }
    const buf = Buffer.from(await r.arrayBuffer());
    fs.writeFileSync(f, buf);
    log('  ✓ ' + t.slug + '  ' + Math.round(buf.length / 1024) + 'KB');
  } catch (e) { log('  ✗ ' + t.slug + ' ' + e.message); }
}
const zips = fs.readdirSync(OUT).filter((f) => f.endsWith('.zip'));
log('本地共 ' + zips.length + ' 个 zip，开始上传…');

// ---------- 2) 上传 ----------
execFileSync('plink', ['-ssh', '-batch', '-pw', PW, HOST, 'mkdir -p /tmp/wp-themes'], { stdio: 'inherit' });
for (const z of zips) {
  execFileSync('pscp', ['-pw', PW, '-batch', path.join(OUT, z), HOST + ':/tmp/wp-themes/'], { stdio: 'inherit' });
  log('  上传 ' + z);
}
log('上传完成');

// ---------- 3) 服务器上 nohup 跑解压+提取 ----------
const sh = `sh /tmp/batch-fetch-themes.sh`;
execFileSync('plink', ['-ssh', '-batch', '-pw', PW, HOST, 'nohup ' + sh + ' > /tmp/batch-fetch.out 2>&1 &'], { stdio: 'inherit' });
log('已在服务器后台启动；日志：/mnt/optane/webstack/www/cfse-batch-fetch.log');
