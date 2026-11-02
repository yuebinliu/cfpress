---
title: Obsidian publisher
order: 5
---

# Obsidian publisher

**MD Publisher** — a small Obsidian plugin that publishes the note you are looking at. Not affiliated with the CMS; it just speaks its write API.

Nothing is mirrored. The Markdown you write in Obsidian becomes the post, and Obsidian is only the editor.

## Install

The plugin is not in Obsidian's community store, so it is a manual install:

1. Download `md-publisher-1.2.0.zip`.
2. Unzip it into `<your vault>/.obsidian/plugins/md-publisher/` — the folder must contain `main.js` and `manifest.json`.
3. In Obsidian: **Settings → Community plugins → Installed plugins**, enable **MD Publisher**.

If the folder does not exist, create it. Obsidian only reads plugins from that directory.

## Settings

Open **Settings → MD Publisher** and fill in three things:

| Field | Value |
|---|---|
| **Endpoint** | `https://your-site/wp-json/wp/v2/posts` — the collection URL, not just the domain |
| **Username** | your CMS login name |
| **Application password** | created in the CMS under **Users → 🔑 App passwords** |

Authentication is HTTP Basic, exactly like WordPress. The CMS only accepts application passwords on `/wp-json/*` — your normal login password will not work.

Two optional switches:

- **Upload images** — push local attachments referenced as `![[image.png]]` to the CMS media library and replace them with the returned URL.
- **Image prefix** — fallback used when an attachment cannot be uploaded, e.g. `https://your-site/wp-content/uploads`.

## Publishing

Put the cursor in a note, open the command palette, run **发布当前笔记 (Markdown 原文)**.

The plugin sends the raw Markdown, not the rendered HTML, with `content_format: md` — so the CMS stores Markdown and renders it with the site's own theme. Code blocks, tables and headings all match what you see in Obsidian.

## Frontmatter

Everything is optional except the title, which falls back to the file name.

```yaml
---
title: Where it should be
slug: where-it-should-be
status: publish        # default is draft
categories: [tech]
tags: [obsidian, workflow]
wp_id: 179354571311647
---
```

- **status** — `draft` unless you say otherwise. That default is deliberate: a mis-click should not publish.
- **categories / tags** — names or slugs. A term that does not exist yet is created on the fly, so `categories: [tech]` works on a fresh site.
- **wp_id** — written back automatically after the first publish. That is what makes the second publish an update instead of a duplicate.

## One thing to watch

If you delete `wp_id` from the frontmatter (or copy a note that has one), the next publish creates a **new** post instead of updating. Check the frontmatter before re-publishing an old note.

Next: [Write API reference](/docs/reference/api.html).
