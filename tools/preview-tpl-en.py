# -*- coding: utf-8 -*-
"""
把 tpl.en.json 渲染成一份可独立打开的英文首页预览。

用途：在路由改造完成前，先肉眼确认英文模板的文案、排版与占位符替换是否正常。
样式直接引用线上 style.css（预览文件本地打开也能拿到皮肤）。
"""
import json
import os
import re
import sys

sys.stdout.reconfigure(encoding='utf-8', errors='replace')

HERE = os.path.dirname(os.path.abspath(__file__))
THEME = os.path.join(HERE, '..', 'worker', 'static', 'wp-content', 'themes', 'cfse-demo')
OUT = os.path.join(HERE, '..', 'tmp', 'i18n', 'preview-en.html')

SITE = {
    'SITE_NAME': 'Yuebin',
    'SITE_DESC': 'Static WordPress export plus a standalone Cloudflare CMS',
    'SITE_URL': 'https://yuebin.uk/en',
    'SEARCH_ACTION': 'https://yuebin.uk/en/',
    'SITE_FOOTER': '&copy; 2026 Yuebin · Built with CF Static Export Pro',
}

MENU = ''.join(
    '<a href="{u}">{t}</a>'.format(u=u, t=t) for u, t in [
        ('https://yuebin.uk/en/category/start/', 'Quick Start'),
        ('https://yuebin.uk/en/category/guide/', 'Guides'),
        ('https://yuebin.uk/en/category/plugin/', 'Plugin'),
        ('https://yuebin.uk/en/about/', 'About'),
    ]
)

POSTS = [
    ('Meet CF Static Export Pro: one plugin, two ways to build a site',
     'quick-start', '2026-10-18',
     'Keep WordPress for editing, or run a standalone CMS on Workers. Same data layer, same renderer — pick either path.'),
    ('How static/dynamic separation works: what D1, R2 and KV each handle',
     'architecture', '2026-10-16',
     'Single pages are pre-rendered into R2; lists query D1 live. Here is where each piece of the stack sits.'),
    ('Stand up a standalone Cloudflare CMS in 5 minutes',
     'quick-start', '2026-10-14',
     'Three IDs, one deploy command, and a working admin with Markdown editing, media and comments.'),
    ('Caching and cost: why lists stay live and single pages stay fast',
     'architecture', '2026-10-11',
     'A 30-minute browser cache on single pages, zero cache on lists. The trade-off is deliberate.'),
    ('The style system: one directory, one skin',
     'guide', '2026-10-08',
     'A style is a folder plus a tpl.json. Switch it in the admin and the whole site changes instantly.'),
    ('Admin tour: content, categories, media, templates, comments',
     'guide', '2026-10-05',
     'Everything the built-in CMS gives you, screen by screen, without a single plugin.'),
]


def if_block(tpl, flag, cond):
    """处理 {{#IF_X}}...{{/IF_X}}：cond 为 False 时整块删除。"""
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


def fill(tpl, mapping):
    for k, v in mapping.items():
        tpl = tpl.replace('{{' + k + '}}', str(v))
    return tpl


def main():
    data = json.load(open(os.path.join(THEME, 'tpl.en.json'), encoding='utf-8'))
    home = data['tpl']['pageHome']
    item = data['tpl']['item']

    cards = []
    for title, cat, date, excerpt in POSTS:
        c = item
        c = fill(c, {
            'POST_TITLE': title,
            'POST_CATEGORIES': '<a href="https://yuebin.uk/en/category/%s/">%s</a>' % (cat, cat.title()),
            'POST_DATE': date,
            'POST_EXCERPT': excerpt,
            'POST_URL': 'https://yuebin.uk/en/%s/' % re.sub(r'[^a-z0-9]+', '-', title.lower()).strip('-')[:40],
            'COMMENT_COUNT': '0',
        })
        c = if_block(c, 'CATEGORIES', True)
        cards.append(c)

    sidebar_cats = ''.join(
        '<li><a href="https://yuebin.uk/en/category/{s}/">{n}</a> <span class="cnt">{c}</span></li>'.format(
            s=s, n=n, c=c) for s, n, c in [
        ('quick-start', 'Quick Start', 3), ('guide', 'Guides', 5),
        ('architecture', 'Architecture', 4), ('plugin', 'WordPress Plugin', 2),
    ])
    sidebar_arch = ''.join(
        '<li><a href="https://yuebin.uk/en/{y}/{m}/">{y} {m}</a> <span class="cnt">{c}</span></li>'.format(
            y=y, m=m, c=c) for y, m, c in [('2026', '10', 6), ('2026', '09', 4), ('2026', '08', 3)])

    html = home
    html = if_block(html, 'MENU', True)
    html = if_block(html, 'SOCIAL', False)
    html = if_block(html, 'hasPage', False)
    html = fill(html, dict(SITE, MENU=MENU, CONTENT=''.join(cards),
                           PAGINATION_PREV='', PAGINATION_NEXT='',
                           SIDEBAR_CATEGORIES=sidebar_cats,
                           SIDEBAR_ARCHIVES=sidebar_arch,
                           SITE_LOGO='', SITE_BANNER=''))
    # 预览文件在本地，把皮肤指到线上
    html = html.replace('{{SITE_URL}}/wp-content/themes/cfse-demo/style.css',
                        'https://yuebin.uk/wp-content/themes/cfse-demo/style.css')

    left = sorted(set(re.findall(r'\{\{[A-Za-z_#/]+\}\}', html)))
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    open(OUT, 'w', encoding='utf-8').write(html)

    print('written:', os.path.abspath(OUT))
    print('bytes:', len(html.encode('utf-8')))
    print('未替换占位符:', left if left else '无')


if __name__ == '__main__':
    main()
