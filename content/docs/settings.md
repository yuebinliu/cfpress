---
title: Settings
order: 5
---

# Settings

Everything that is not a post lives under **Settings**: site identity, navigation, cache durations, permalinks, and backups. This page walks through it in the order it appears.

Two rules apply to almost everything here. Changes to *dynamic* pages (lists, archives, sidebars) take effect on the next request. Changes that affect *static* post pages need a rebuild — the field says so when it matters.

## Site information

- **Site name** — the title bar, the footer, the sidebar heading.
- **Description** — used in the RSS channel and the meta description of list pages.
- **Logo** and **banner** — full URLs, rendered as `{{SITE_LOGO}}` and `{{SITE_BANNER}}`. The upload button stores the file in the media library and fills the URL for you. Leave either blank and the block is omitted, so a theme does not need to know whether you have one.
- **Post layout** — *two columns* keeps the sidebar, *single column* hides it. This is applied when a post page is rendered, so existing pages change only after a rebuild.
- **Custom head HTML** — injected before `</head>` on every page: analytics, AdSense verification, a canonical tag. About 8KB. Here the ordering is friendlier than elsewhere: list pages pick it up immediately, and existing static post pages get it **when they are served**, so no rebuild is needed.

## Social links

One per line, `service|url`:

```
x|https://x.com/yourhandle
github|https://github.com/you
```

The service name is free text; it is used as the link label. Rendered wherever the theme puts `{{SOCIAL_LINKS}}`.

## Navigation menu

The header menu is a list you build yourself: type, label, target, depth.

- **Type** — custom URL, category, tag, or a single post. Category and tag rows show a dropdown of what actually exists, so you cannot point a menu item at a slug you deleted.
- **Depth** — 0 is top level, 1 and 2 nest under the item above. Anything deeper is not supported, which keeps the mobile menu honest.
- **Empty menu** — the header simply has no navigation. It will not fall back to "all categories".

Menus live only in CF Press. Importing site settings from WordPress does not touch them.

## Cache

CF Press serves three kinds of pages, and they age differently:

| Page | Where it comes from | Cache |
|---|---|---|
| Post pages | static HTML in R2 | not cached at the edge, the file *is* the page |
| Lists, archives | D1 query, rendered per request | TTL you set |
| Sidebar | D1 query | TTL you set |

The four numbers under **Cache** are the TTLs: list pages, sidebar, site info, and feed. Zero means *query every time* — correct but slower and it costs D1 reads. `Site info` is the odd one out: it is cached in KV, and setting it to a low value is what makes a name change visible quickly.

Three presets exist because picking four numbers is a chore:

- **All D1** — everything live, fewest moving parts, most D1 reads.
- **Mixed** — sidebar cached, lists live. A reasonable default.
- **All cached** — highest traffic, changes take up to one TTL to appear.

**Purge list cache on publish** is the switch that decides whether a new post shows up in the home list *immediately* or after the TTL. It clears the first page and the sidebar through the edge cache, which does not consume KV writes. Turn it on unless you have a reason not to.

Two more knobs are here because they change query shape rather than freshness: **posts per page** on lists, and **feed items**.

## Batch tasks

Rebuilds and bulk operations read two numbers: **batch size** (how many items one request handles) and **concurrency** (how many run at once). The defaults are conservative. Raise concurrency only if you have measured that a single Worker request finishes comfortably inside the CPU limit — parallel work does not create CPU budget, it spends it faster.

## Permalinks

Post URLs are a pattern, not a stored field:

```
/%year%/%monthnum%/%postname%/
```

Supported tokens: `%postname%`, `%year%`, `%monthnum%`, `%day%`, `%post_id%`, `%category%`.

Changing the pattern is a two-step operation, and the second step is not optional:

1. **Preview** shows what the new URLs will look like.
2. **Start migration** rewrites the stored HTML to the new paths. Old files are kept until you press **Clean up old files**.

If you only switch the pattern without migrating, every existing post returns 404 — the links point at paths that were never written. **Clean up orphans** removes objects in R2 that no post in D1 points at; useful after a large import, and safe to run because the source of truth is the database.

## Content maintenance

- **Rebuild all post pages** — re-renders every published post with the current theme. Run this after switching themes, after editing a template, and after changing the site name if you care about static pages.
- **Purge all caches** — empties the edge cache and the KV site context. This is what makes a settings change visible immediately rather than after a TTL.

## Backup and restore

**Export** writes one JSON file containing posts, categories and tags, the document index, and site settings. It deliberately excludes:

- user accounts (they contain password hashes),
- media files in R2,
- document sources in R2.

Those three need their own backup — the document sources are plain files and belong in git, which is the easiest place for them.

**Import** is additive. A post whose slug already exists is skipped rather than overwritten, so importing the same file twice is harmless. Site settings are the exception: they ask for confirmation before replacing what you have, because a wrong site URL breaks styles and links on every page.

Next: [The document library](/docs/documents.html).
