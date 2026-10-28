# -*- coding: utf-8 -*-
"""
从 cfse-demo/tpl.json 生成英文版 tpl.en.json。

设计：英文模板是**独立文件**（tpl.en.json），不改 tpl.json。
tplstore 按语言先试 tpl.<lang>.json，miss 再回退 tpl.json ⇒ 老风格包零影响。

替换策略：全部用「带上下文的精确串」，不用裸词替换，
避免「动/降/肤/出/评/开」这类单字图标误伤正文。
"""
import json
import os
import re
import sys

sys.stdout.reconfigure(encoding='utf-8', errors='replace')

HERE = os.path.dirname(os.path.abspath(__file__))
THEME = os.path.join(HERE, '..', 'worker', 'static', 'wp-content', 'themes', 'cfse-demo')
SRC = os.path.join(THEME, 'tpl.json')
OUT = os.path.join(THEME, 'tpl.en.json')

# 精确串替换表：中文原文 -> 英文。键必须与模板里的原文逐字一致。
PAIRS = [
    # ---- 通用 chrome ----
    ('placeholder="搜索文档…"', 'placeholder="Search docs…"'),
    ('aria-label="搜索"', 'aria-label="Search"'),
    ('>首页<', '>Home<'),
    ('>评论<', '>Comments<'),
    ('>内容<', '>Content<'),
    ('>归档<', '>Archive<'),
    ('>订阅<', '>Subscribe<'),
    ('>RSS 订阅<', '>RSS feed<'),
    ('>阅读全文 →<', '>Read more →<'),
    ('>分类<', '>Category<'),
    ('>标签<', '>Tag<'),
    # ---- 分页 / 计数（含变量，必须整句替换）----
    ('第 {{PAGE_NUMBER}} 页 · 共 {{TOTAL_POSTS}} 篇', 'Page {{PAGE_NUMBER}} · {{TOTAL_POSTS}} posts'),
    ('共 {{TOTAL_POSTS}} 篇文章', '{{TOTAL_POSTS}} posts'),
    ('{{COMMENT_COUNT}} 条评论', '{{COMMENT_COUNT}} comments'),
    ('评论（{{COMMENT_COUNT}}）', 'Comments ({{COMMENT_COUNT}})'),
    # ---- 首页 hero ----
    ('WordPress × Cloudflare · 静态化 + 独立 CMS',
     'WordPress × Cloudflare · Static export + standalone CMS'),
    ('把 WordPress', 'Turn WordPress'),
    ('变成一台静态站生成器', 'into a static site generator'),
    ('单页静态化进 R2，列表由 D1 实时渲染。既能把现有 WordPress 站一键搬上 Cloudflare，也能脱离 WordPress，用内置 CMS 独立建站。',
     'Single pages are exported to R2 as static HTML, while lists render live from D1. Move an existing WordPress site onto Cloudflare in one go — or leave WordPress behind and build with the built-in CMS.'),
    ('>5 分钟开始 →<', '>Start in 5 minutes →<'),
    ('>阅读使用指南<', '>Read the guides<'),
    # ---- 统计条 ----
    ('>需要维护的服务器<', '>servers to maintain<'),
    ('>6 类<', '>6 types<'),
    ('>可在线切换的页面模板<', '>of page templates, switchable online<'),
    ('>10 万<', '>100K<'),
    ('>免费额度 · 请求 / 天<', '>free requests per day<'),
    ('>2 种<', '>2 ways<'),
    ('>建站路线，同一套内核<', '>to build a site, one shared core<'),
    # ---- 两条路线 ----
    ('>两条路线，同一套内核<', '>Two paths, one core<'),
    ('保留 WordPress，或者直接开一个新站。数据层与渲染层是同一套。',
     'Keep WordPress, or start a brand-new site. Same data layer, same rendering layer.'),
    ('>WordPress 插件<', '>WordPress Plugin<'),
    ('保留熟悉的编辑体验。插件负责生成静态单页、同步 D1、上传 R2，还能把现有主题抓成风格包。',
     'Keep the editing experience you know. The plugin generates static pages, syncs to D1, uploads to R2, and can capture your current theme as a style pack.'),
    ('>了解插件 →<', '>Explore the plugin →<'),
    ('>独立 Worker CMS<', '>Standalone Worker CMS<'),
    ('不依赖 WordPress。D1 存内容、R2 存单页，后台自带 Markdown 与富文本双编辑器、媒体库与评论审核。',
     'No WordPress required. D1 holds content, R2 holds single pages, and the admin ships with Markdown and rich-text editors, a media library, and comment moderation.'),
    ('>部署 CMS →<', '>Deploy the CMS →<'),
    # ---- 特性卡 ----
    ('>为什么不是又一个静态插件<', ">Why this isn't just another static plugin<"),
    ('纯静态死板、纯动态慢。这套方案把两者拆开，各做各擅长的事。',
     'Purely static is rigid; purely dynamic is slow. This splits the two so each does what it does best.'),
    ('<div class="ico">动</div>', '<div class="ico">S/D</div>'),
    ('>动静分离<', '>Static/Dynamic Split<'),
    ('文章单页预渲染成 HTML 存 R2，列表、分类、标签、归档由 D1 实时查询，发布即更新。',
     'Posts are pre-rendered to HTML and stored in R2; lists, categories, tags, and archives query D1 live, so publishing updates instantly.'),
    ('<div class="ico">降</div>', '<div class="ico">3T</div>'),
    ('>三级降级生成<', '>Three-Tier Fallback<'),
    ('内部模板渲染 → 前台抓取 → 硬编码兜底。大批量导出不再因为一次超时整批失败。',
     'Internal template render → front-end fetch → hardcoded fallback. Large exports no longer fail wholesale on a single timeout.'),
    ('<div class="ico">肤</div>', '<div class="ico">SK</div>'),
    ('>在线换肤<', '>Online Skinning<'),
    ('一套风格就是一个目录加一份 tpl.json。后台切换即时生效，内置 14 套风格可直接用。',
     'A style is just a directory plus a tpl.json. Switching takes effect immediately, with 14 built-in styles ready to use.'),
    ('<div class="ico">出</div>', '<div class="ico">OUT</div>'),
    ('>多出口发布<', '>Multi-Target Publishing<'),
    ('除了 Cloudflare，还能发到 S3、GitHub、Netlify、Vercel、FTP、WebDAV 或本地目录。',
     'Beyond Cloudflare, publish to S3, GitHub, Netlify, Vercel, FTP, WebDAV, or a local directory.'),
    ('<div class="ico">评</div>', '<div class="ico">CM</div>'),
    ('>静态站评论<', '>Comments on a Static Site<'),
    ('评论写 D1，单页响应时注入。审核后立即显示，不必重建页面；还能回写到 WordPress。',
     'Comments live in D1 and are injected when the page is served. Approved comments appear instantly without a rebuild — and can sync back to WordPress.'),
    ('<div class="ico">开</div>', '<div class="ico">API</div>'),
    ('>开放写入口<', '>Open Write API<'),
    ('兼容 WordPress REST 写子集与应用密码。Obsidian 插件、脚本、任何 HTTP 客户端都能发文。',
     'A subset of the WordPress REST write API plus application passwords. Obsidian plugins, scripts, and any HTTP client can publish.'),
    # ---- 最新文章 / CTA / 页脚 ----
    ('>最新文章<', '>Latest Posts<'),
    ('>部署记录、功能拆解与使用技巧。<', '>Deployment notes, feature deep-dives, and practical tips.<'),
    ('>现在就把站点搬上 Cloudflare<', '>Move your site to Cloudflare today<'),
    ('免费额度足够跑一个个人站。装插件、填三个 ID、点发布。',
     'The free tier is enough for a personal site. Install the plugin, fill in three IDs, hit publish.'),
    ('>查看快速开始<', '>See Quick Start<'),
    # ---- 评论区 ----
    ('>回复</a>', '>Reply</a>'),
    ('>发表评论<', '>Leave a comment<'),
    ('邮箱不会被公开。带 <span class="required">*</span> 的为必填项。',
     'Your email address will not be published. Required fields are marked <span class="required">*</span>.'),
    ('<label for="comment">评论 ', '<label for="comment">Comment '),
    ('<label for="author">昵称 ', '<label for="author">Name '),
    ('<label for="email">邮箱</label>', '<label for="email">Email</label>'),
    ('<label for="url">网站</label>', '<label for="url">Website</label>'),
    ('>提交评论<', '>Post comment<'),
]

# 结构性替换（属性 / 声明）
STRUCT = [
    ('lang="zh-CN"', 'lang="en"'),
]


def apply(text):
    for a, b in STRUCT + PAIRS:
        text = text.replace(a, b)
    return text


def main():
    with open(SRC, encoding='utf-8') as f:
        data = json.load(f)

    out = {'name': 'CFSE Demo (English)', 'tpl': {}, 'sidebar': {}, 'comments': {}}
    for k, v in (data.get('tpl') or {}).items():
        out['tpl'][k] = apply(v)

    for section in ('sidebar', 'comments'):
        src = data.get(section) or {}
        out[section] = {k: apply(v) for k, v in src.items()}

    with open(OUT, 'w', encoding='utf-8') as f:
        json.dump(out, f, ensure_ascii=False, indent=2)

    # 自检：英文版里不该再有中文（{{}} 变量与 HTML 注释除外）
    left = []
    for k, v in out['tpl'].items():
        hits = re.findall(r'[\u4e00-\u9fa5]+', v)
        if hits:
            left.append((k, hits[:8]))
    for section in ('sidebar', 'comments'):
        for k, v in out[section].items():
            hits = re.findall(r'[\u4e00-\u9fa5]+', v)
            if hits:
                left.append((section + '.' + k, hits[:8]))

    print('written:', OUT)
    print('tpl kinds:', list(out['tpl'].keys()))
    if left:
        print('!! 仍有中文残留:')
        for k, hits in left:
            print('   ', k, hits)
    else:
        print('ok: 无中文残留')


if __name__ == '__main__':
    main()
