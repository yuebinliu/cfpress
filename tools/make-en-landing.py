# -*- coding: utf-8 -*-
"""
生成英文介绍页 → worker/static/en/index.html

定位：**临时的对外介绍页**，不是双语内容站。
  · 用 tpl.en.json 的 pageHome 作外壳，视觉与主站一致
  · 去掉「最新文章」区（暂时没有英文文章，空列表比没有更难看）
  · 导航与 CTA 指向本页锚点 / 中文站首页
  · 加 hreflang 与 canonical，避免和中文首页抢排名

为什么放 assets 而不是 R2：
  wrangler.toml 是 assets-first，`static/en/index.html` 部署后 `/en/` 由边缘直接返回，
  **不需要改路由、不消耗 Worker 调用**。将来 CMS 支持多语言时，这个文件直接删掉即可。
"""
import json
import os
import re
import sys

sys.stdout.reconfigure(encoding='utf-8', errors='replace')

HERE = os.path.dirname(os.path.abspath(__file__))
THEME = os.path.join(HERE, '..', 'worker', 'static', 'wp-content', 'themes', 'cfse-demo')
OUT = os.path.join(HERE, '..', 'worker', 'static', 'en', 'index.html')

BASE = 'https://yuebin.uk'
SITE_URL = BASE + '/en'

MENU = ''.join(
    '<a href="#%s">%s</a>' % (h, t) for h, t in [
        ('overview', 'Overview'),
        ('routes', 'Two Paths'),
        ('features', 'Features'),
    ]
) + '<a href="%s/">中文</a>' % BASE


def if_block(tpl, flag, cond):
    open_tag = '{{#IF_' + flag + '}}'
    close_tag = '{{/IF_' + flag + '}}'
    while open_tag in tpl:
        s = tpl.find(open_tag)
        e = tpl.find(close_tag, s)
        if e == -1:
            break
        inner = tpl[s + len(open_tag):e]
        tpl = tpl[:s] + (inner if cond else '') + tpl[e + len(close_tag):]
    return tpl


def main():
    data = json.load(open(os.path.join(THEME, 'tpl.en.json'), encoding='utf-8'))
    html = data['tpl']['pageHome']

    # ① 去掉「最新文章」整块（暂无英文文章）
    html = re.sub(r'<section class="section" id="latest">[\s\S]*?</section>\s*', '', html, count=1)

    # ② 条件块
    html = if_block(html, 'MENU', True)
    html = if_block(html, 'SOCIAL', False)
    html = if_block(html, 'hasPage', False)

    # ③ 占位符
    repl = {
        'SITE_NAME': 'Yuebin',
        'SITE_DESC': 'Static WordPress export plus a standalone Cloudflare CMS',
        'SITE_URL': SITE_URL,
        'SEARCH_ACTION': SITE_URL + '/',
        'SITE_FOOTER': '&copy; 2026 Yuebin · Built with CF Static Export Pro',
        'MENU': MENU,
        'SITE_LOGO': '',
        'SITE_BANNER': '',
    }
    for k, v in repl.items():
        html = html.replace('{{' + k + '}}', v)

    # ④ 锚点：hero 与 section 加 id，导航才跳得动
    html = html.replace('<section class="hero">', '<section class="hero" id="overview">', 1)
    html = html.replace('<div class="routes">', '<div class="routes" id="routes">', 1)
    html = html.replace('<div class="feats">', '<div class="feats" id="features">', 1)

    # ⑤ CTA 指向中文站（目前英文内容只有一个介绍页）
    html = html.replace(SITE_URL + '/category/start/', BASE + '/category/start/')
    html = html.replace(SITE_URL + '/category/guide/', BASE + '/category/guide/')
    html = html.replace(SITE_URL + '/category/extend/', BASE + '/category/extend/')

    # ⑤b 静态资源不在 /en 下：CSS / 主题文件都要回到站点根
    html = html.replace(SITE_URL + '/wp-content/', BASE + '/wp-content/')

    # ⑥ head：canonical + hreflang + 英文描述
    head_extra = (
        '<link rel="canonical" href="%s/">\n'
        '<link rel="alternate" hreflang="en" href="%s/">\n'
        '<link rel="alternate" hreflang="zh-Hans" href="%s/">\n'
        '<link rel="alternate" hreflang="x-default" href="%s/">\n'
        % (SITE_URL, SITE_URL, BASE, BASE)
    )
    html = html.replace('</head>', head_extra + '</head>', 1)

    # ⑦ 搜索框在这个静态页里没有后端可查，改成返回中文站搜索
    html = html.replace(
        '<form class="srch" role="search" action="%s/" method="get">' % SITE_URL,
        '<form class="srch" role="search" action="%s/" method="get">' % BASE, 1)

    # ⑧ 页脚：英文站没有分类/归档内容，两列合并成一组链接
    html = re.sub(
        r'<div><h4>Content</h4><ul>\{\{SIDEBAR_CATEGORIES\}\}</ul></div>\s*'
        r'<div><h4>Archive</h4><ul>\{\{SIDEBAR_ARCHIVES\}\}</ul></div>',
        '<div><h4>Links</h4><ul>'
        '<li><a href="%s/">中文首页</a></li>'
        '<li><a href="%s/category/start/">Quick Start (中文)</a></li>'
        '<li><a href="%s/feed/">RSS</a></li>'
        '</ul></div>' % (BASE, BASE, BASE),
        html, count=1)
    html = html.replace(SITE_URL + '/feed/', BASE + '/feed/')

    left = sorted(set(re.findall(r'\{\{[A-Za-z_#/]+\}\}', html)))
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    open(OUT, 'w', encoding='utf-8').write(html)

    print('written:', os.path.abspath(OUT))
    print('bytes:', len(html.encode('utf-8')))
    print('未替换占位符:', left if left else '无')
    print('中文残留:', len(re.findall(r'[\u4e00-\u9fa5]', html)))


if __name__ == '__main__':
    main()
