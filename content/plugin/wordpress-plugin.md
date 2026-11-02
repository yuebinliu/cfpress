---
title: CF Press Sync (WordPress)
order: 1
---

# CF Press Sync

[Download 2.2.1](/releases/plugin/latest.zip) · 130 KB · WordPress 5.5+, PHP 7.0+ with cURL

Makes an existing WordPress site the editing backend for CF Press. Posts, media, categories and comments sync to Cloudflare; readers are served from the edge.

## Install

1. Download the zip → **Plugins → Add New → Upload Plugin** → **Activate**.
2. On the Worker, set a key: `npx wrangler secret put AUTH_KEY_SECRET` (any long random string).
3. **Settings → CF Press Sync**: enter the site URL and that same key, press **Test connection**.
4. Press **同步文章到 CMS**. Lists are live at once; each page renders on its first visit.

No Worker yet? Press **下载发行包** then **部署到 Cloudflare** — that deploys the CMS from this page. Both need `wrangler` installed and logged in on the machine running WordPress.

## Everyday use

- **Write in WordPress** — publishing queues the post and pushes it when the request ends.
- **Write in the CMS** — **拉取文章** brings those posts back into WordPress.
- **Comments** sync both ways; moderate on whichever side you prefer.
- **Daily cron** pushes anything that changed and was missed (50 posts/day by default).

## Three things to know

- **Don't delete the plugin to update it.** `uninstall.php` wipes the CMS URL, the auth key and the target list. Upload the new zip over the old one.
- **Draft or trash is not instantly offline.** The CMS row changes at once; the rendered page is removed by the daily cleanup — or immediately with **Settings → Content maintenance → Clear orphan files**.
- **20 MB per file.** Bigger media goes through the CMS media library instead.

## If something is off

| Symptom | Cause |
|---|---|
| `401` on every request | the key differs from `AUTH_KEY_SECRET` |
| Pages 404 after an export | site URL is wrong, so R2 keys and public paths disagree |
| Attachments missing | file over 20 MB, or the R2 binding is unset |
| Nothing syncs, log says no target | hosting target unset or disabled |
