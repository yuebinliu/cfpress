---
title: WordPress plugin
order: 1
---

# WordPress plugin

**CFSE Sync for WordPress.** It connects an existing WordPress install to a CF Press Worker: posts, categories, tags and media go out to Cloudflare, and posts written in the CMS can come back into WordPress.

It is a companion, not a dependency. If you are starting fresh, skip this page — the CMS alone is the whole product.

## What it does

| Direction | What moves | Started from |
|---|---|---|
| WordPress → CF Press | posts, media, categories, tags | **Export** in the plugin |
| CF Press → WordPress | posts written in the CMS | **Pull from Worker** in the plugin |
| Comments | both ways | automatic on arrival, plus a manual sync |

Most export plugins are one-way: WordPress is the source of truth and the static site is a read-only copy. Here either side can be edited and the other catches up.

## Install

**1. Give the Worker a key.** The plugin authenticates with an `X-Auth-Key` header; the Worker reads the expected value from `AUTH_KEY_SECRET`.

```bash
cd cfpress/worker
npx wrangler secret put AUTH_KEY_SECRET --config wrangler.prod.toml
```

Use a long random string. Skip this and every plugin request comes back `401`.

**2. Install the zip.** WordPress admin → **Plugins → Add New → Upload Plugin** → choose the zip → **Activate**.

**3. Fill in the settings.** **Settings → CFSE Sync** (the menu label is Chinese: 「CFSE 同步」):

- **站点地址（Worker 域名）** — the site's public address, e.g. `https://example.com`. No trailing slash, no `/admin`.
- **Auth Key** — exactly what you gave `AUTH_KEY_SECRET`.
- **托管目标** — the hosting target table. One row per site this WordPress install writes into; the default row is enough for a single site.

Save, then press **Test connection**. Green means the Worker answered and accepted the key.

**4. First export.** Press **Export**. It walks every post, uploads media to R2, writes posts and taxonomies into D1, and renders each post into a page. The first run on a large site takes a while; later runs only touch what changed.

## Sync rules worth knowing

**Publish state lives in D1.** Set a post to draft in the CMS, then run a full export — WordPress's status overwrites it and the post is published again. Pick one side to own publishing; do not draft in one place and bulk-export from the other.

**Pull has two modes.** *Additive* (default) inserts posts the CMS created and leaves existing ones alone. *Overwrite* replaces matching posts from the CMS copy — a one-way door for that post, so use it for a deliberate migration, not as a habit.

**Media is content-addressed.** Re-exporting does not duplicate files, and replacing an image keeps its URL so posts pick up the new file without edits. Uploaded files are de-duplicated by content, so the same image referenced in ten posts is stored once.

**Attachments count as media.** Images, and also zips, PDFs, audio and video linked in a post body. Anything under `wp-content/uploads/` and referenced by `src` or `href` is copied to R2, and the URL is rewritten to the CMS domain.

**Comments stay one thread.** A comment left on the CF Press site is written into WordPress with its approval state; comments moderated in WordPress can be pushed back. An unapproved comment never renders on the static site, whichever side it came from.

## Two caveats

**Deleting the plugin deletes its settings.** WordPress runs `uninstall.php`, which removes the CMS URL, the auth key and the target list. Updating by uploading a new zip over the old one is fine; *deleting* is not a way to "reset".

**Trash and draft do not unpublish instantly.** The CMS row updates immediately, but the rendered page stays on R2 until orphan cleanup runs. If a retraction has to be immediate, open **Settings → Content maintenance** in the CMS and press **Clear orphan files**.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `401` on every request | `AUTH_KEY_SECRET` and the plugin's auth key differ |
| Export finishes but pages 404 | The Worker's site URL is wrong, so R2 keys and public paths disagree |
| Media missing after export | The R2 binding is missing or points at another bucket |
| Lists empty but posts exist | Posts were exported as drafts |
| Nothing syncs, log says no target | The hosting target is unset or disabled |

Next: [Obsidian publisher](/plugin/obsidian-plugin.html).
