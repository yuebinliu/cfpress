---
title: Installing the plugin
order: 2
---

# Installing the plugin

**CF Press Sync** is a free WordPress plugin that connects an existing WordPress site to a CF Press site. Posts, media, categories and comments move both ways, and it can deploy the CMS for you if you do not have one yet.

- **Needs:** WordPress 5.5+, PHP 7.0+ with cURL, and a CF Press Worker (or let the plugin create one).
- **Get it:** [download cf-press-sync-2.2.0.zip](/wp-content/uploads/6b96da7af8ba/cf-press-sync-2.2.0.zip) (130 KB), then **Plugins → Add New → Upload Plugin**. Updating later means uploading a newer zip over it — never deleting the plugin, see the caveats below.
- **Not sure this is the right path?** [Read why it exists first](/plugin/overview.html) — it is two minutes and covers the trade-offs.

It does three jobs:

| Job | What it means |
|---|---|
| **Move content** | posts, media, categories, tags, comments — out to Cloudflare, and back |
| **Install the CMS** | downloads the CF Press release and deploys the Worker, so you never touch a terminal |
| **Run the site** | daily incremental sync, per-post push, conflict protection, multi-site routing |

## Install

### 1. Pick the path that fits

**If CF Press is not deployed yet**, the plugin can do it for you: **Settings → CF Press Sync → 下载发行包**, then **部署到 Cloudflare**.

That second button runs `wrangler deploy` from PHP on your server. It needs three things: PHP `exec` not disabled, `wrangler` installed on the host, and `wrangler login` already done for that user. On ordinary shared hosting those are not available — in that case deploy from your own machine and come back to step 2.

**If the Worker already runs**, skip straight to step 2.

### 2. Give the Worker a key

The plugin authenticates with an `X-Auth-Key` header; the Worker compares it against `AUTH_KEY_SECRET`.

```bash
cd cfpress/worker
npx wrangler secret put AUTH_KEY_SECRET --config wrangler.prod.toml
```

Use a long random string. Skip this and every plugin request comes back `401`.

### 3. Install the plugin

WordPress admin → **Plugins → Add New → Upload Plugin** → choose the zip → **Activate**.

### 4. Fill in the settings

**Settings → CF Press Sync** (the menu label is Chinese: 「CF Press 同步」):

- **站点地址（Worker 域名）** — the site's public address, e.g. `https://example.com`. No trailing slash, no `/admin`.
- **Auth Key** — exactly what you gave `AUTH_KEY_SECRET`.
- **托管目标** — the target table. One row per site this install writes into; one default row is enough unless you run several.

Press **Test connection**. Green means the Worker answered and accepted the key.

### 5. Create the CMS login

A freshly deployed CMS has no users. Under **用户** on the same page, enter the username, password and email, then press **写入 / 重置密码**. That account is what you log into `/admin/` with.

### 6. First export

Press **同步文章到 CMS**. It walks every post, uploads media to R2, writes posts and taxonomies into D1, and renders each post into a page. Expect the first run to take a while on a large site; later runs only touch what changed.

When it finishes, open the site. Lists read D1 live, so they are correct immediately.

## What it writes, and what it does not

It writes `posts`, `taxonomies`, `post_taxonomy` and `comments`, plus media objects under `wp-content/uploads/`.

It does **not** touch the `documents` table. The document library belongs to the CMS; the plugin never reads or overwrites it.

Next: [How the sync behaves](/plugin/wordpress-plugin-sync.html).
