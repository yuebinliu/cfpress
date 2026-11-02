---
title: CF Press Publisher (Obsidian)
order: 2
---

# CF Press Publisher

[Download 1.3.0](/releases/obsidian/latest.zip) · 4.7 KB · Obsidian 1.0+

Publishes the note in front of you to your CF Press site. Markdown goes in as written, `![[image]]` attachments are uploaded, and the returned id is written back so the next publish updates instead of duplicating.

## Install

1. Download the zip and unzip it into `<your vault>/.obsidian/plugins/cfpress-publisher/` — the folder must end up containing `main.js` and `manifest.json`.
2. In Obsidian: **Settings → Community plugins → Installed plugins** → enable **CF Press Publisher**.

It is not in the community store yet, so this is a manual install. Create the folder if it does not exist.

## Settings

| Field | Value |
|---|---|
| **Endpoint** | `https://your-site/wp-json/wp/v2/posts` — the collection URL, not just the domain |
| **Username** | your CMS login name |
| **Application password** | created in the CMS under **Users → 🔑 App passwords** |

Authentication is HTTP Basic. The CMS only accepts application passwords on `/wp-json/*`, so your normal login password will not work.

## Publish

Command palette → **发布当前笔记**. Frontmatter is optional:

```yaml
---
title: Where it should be
status: publish        # default is draft
categories: [tech]
tags: [obsidian, workflow]
wp_id: 179354571311647
---
```

- **status** — `draft` unless you say otherwise.
- **categories / tags** — names or slugs; a term that does not exist yet is created on the fly.
- **wp_id** — filled in automatically after the first publish. Keep it, or the next publish creates a duplicate.
