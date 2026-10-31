---
title: Themes
order: 4
---

# Themes and templates

A theme in CF Press is a directory, not a plugin. It holds the HTML that wraps your content and the CSS that styles it. Because templates are data sitting in R2, you can change the look of a running site from the browser, without redeploying the Worker.

## What a theme contains

```
wp-content/themes/<id>/
  tpl.json     the templates
  style.css    the visual layer (plus any images or fonts you reference)
```

`tpl.json` is a single JSON object. Five built-in themes ship with CF Press:

| id | what it is |
|---|---|
| `twentyten` | the default, a classic two-column blog |
| `twentyeleven` | same layout, refined typography |
| `cfse-demo` | warmer palette, longer excerpts |
| `cfse-minimal` | no sidebar, text-first |
| `docs-wiki` | documentation layout, close to what this site uses |

Built-in themes are read-only. When you edit one, CF Press saves your changes as `<id>-custom` and leaves the original intact — so an update to the Worker never overwrites your work.

## The six templates

`tpl.json` holds six page templates plus two optional skeletons:

| key | wraps |
|---|---|
| `pageHome` | the home list |
| `pageCategory` | a category archive |
| `pageTag` | a tag archive |
| `pageArchive` | a date archive |
| `single` | one post |
| `item` | one row in a list — used by all four list pages |
| `sidebar` | the sidebar blocks (optional) |
| `comments` | the comment list, form and reply thread (optional) |

The list pages share `item`, which is why changing a card once changes it everywhere. `pageHome`, `pageCategory`, `pageTag` and `pageArchive` are full-page shells: they own `<html>`, `<head>` and the footer.

## Placeholders

Templates are plain HTML with `{{TOKEN}}` slots. The ones you will actually use:

**Lists** — `{{CONTENT}}` is the rendered list of `item` cards. Also `{{TITLE}}`, `{{TOTAL_POSTS}}`, `{{PAGINATION_PREV}}`, `{{PAGINATION_NEXT}}`, and on archives `{{YEAR}}` / `{{MONTH}}`.

**Site-wide** — `{{SITE_NAME}}`, `{{SITE_URL}}`, `{{SITE_DESC}}`, `{{SITE_LOGO}}`, `{{SITE_BANNER}}`, `{{MENU}}`, `{{SIDEBAR_CATEGORIES}}`, `{{SIDEBAR_ARCHIVES}}`, `{{SIDEBAR_TAGS}}`, `{{SITE_FOOTER}}`, `{{SOCIAL_LINKS}}`.

**Single post** — `{{POST_TITLE}}`, `{{POST_CONTENT}}`, `{{POST_DATE}}`, `{{POST_URL}}`, `{{POST_AUTHOR}}`, `{{POST_CATEGORIES}}`, `{{POST_TAGS}}`, `{{POST_THUMBNAIL}}`, `{{PREV_TITLE}}` / `{{PREV_URL}}`, `{{NEXT_TITLE}}` / `{{NEXT_URL}}`, `{{COMMENTS}}`.

**Cards** — `{{POST_TITLE}}`, `{{POST_URL}}`, `{{POST_DATE}}`, `{{POST_EXCERPT}}`, `{{POST_THUMBNAIL}}`, `{{POST_CATEGORIES}}`, `{{POST_TAGS}}`, `{{POST_AUTHOR}}`, `{{COMMENT_COUNT_TEXT}}`.

Optional sections use conditionals: `{{#IF_THUMBNAIL}}…{{/IF_THUMBNAIL}}` disappears when there is no thumbnail. The same pattern exists for `IF_CATEGORIES`, `IF_TAGS`, `IF_AUTHOR`, `IF_PREV`, `IF_NEXT` and `IF_COMMENTS`. Wrapping an optional block in its condition is what keeps empty markup out of the page.

## Editing a theme in the admin

Open **Templates**. Three things live there:

- **Preview** — renders a chosen theme in an iframe, page by page.
- **Upload** — pick a local folder containing `tpl.json` and its assets. Every file is written to `wp-content/themes/<id>/` in R2; the folder name (or the id you type) becomes the theme id. Uploading `tpl.json` backs up the previous version as `tpl.json.bak`.
- **Edit** — visual editing of the six templates, plus a raw JSON mode for the whole file. **Save** writes to R2, **Revert** restores `.bak`.

To change `style.css`, upload the theme folder again with the new file in it. There is no separate CSS editor, and no build step.

## Switching themes

Pick a theme and press **Apply**. It takes effect on the next request for list pages, archives and the sidebar.

One catch, and it bites everyone once: **already-generated post pages keep the old theme**. Post pages are static HTML in R2, written when the post was published. After switching themes, go to **Settings → Rebuild all post pages**. That regenerates every one with the new templates. The rebuild runs in batches; a site with a few hundred posts takes a couple of minutes.

## Where the document shell fits

Documentation pages do not use these six templates. They use a single separate template, the *document shell*, edited under **Documents → Document template**. It is deliberately independent: restyling your blog should not break the docs, and vice versa. See [The document library](/docs/documents.html).

Next: [Settings](/docs/settings.html).
