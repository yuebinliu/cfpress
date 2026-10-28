# CF Press

> Cloudflare-native CMS — static pages in R2, live lists from D1. One Worker, no servers.

[![Deploy to Cloudflare](https://deploy.workers.cloudflare.com/button)](https://deploy.workers.cloudflare.com/?url=https://github.com/yuebinliu/cfpress/tree/main/worker)

CF Press is a self-hosted CMS that runs entirely on Cloudflare: **D1** for content, **R2** for media and pre-rendered single pages, **KV** for site settings, and a **Worker** for rendering. Single pages are generated to R2 as static HTML, while lists, categories, tags and archives render live from D1 — publish and it's instantly up to date.

## Features

- **Static + dynamic split** — single pages cached in R2, listings live from D1
- **Built-in admin** — content, media, comments, styles, settings (no separate backend)
- **Theme system** — a theme is just a directory + `tpl.json`; switch it in the admin
- **Optional WordPress sync** — use the separate **CF Sync** plugin to import an existing WordPress site
- **Runs on the Cloudflare free tier**

## Requirements

- Node.js 18+
- A Cloudflare account and `wrangler` (`npm i -g wrangler` or use `npx wrangler`)

## Install

```bash
git clone https://github.com/yuebinliu/cfpress.git
cd cfpress/worker
npm install
npx wrangler login

# Create D1 + KV + R2 and generate wrangler.toml automatically
node scripts/setup.mjs            # add --name=cfpress to name resources, --dry-run to preview

# Deploy
npx wrangler deploy
```

Then open `https://<your-worker>.<account>.workers.dev/admin/` and follow the setup screen to create the admin account and set the site name / URL.

### Bind a custom domain

Cloudflare Dashboard → **Workers & Pages** → your Worker → **Settings → Domains & Routes** → **Add custom domain** → `cfpress.org` (or your own).

## One-click deploy (GitHub Actions)

No local tooling needed:

1. Add repository secrets (Settings → Secrets and variables → Actions):
   - `CLOUDFLARE_API_TOKEN` — API token with **Workers Scripts:Edit, D1:Edit, KV:Edit, R2:Edit**
   - `CLOUDFLARE_ACCOUNT_ID` — your Cloudflare account ID
   - `AUTH_KEY_SECRET` — *optional*, the X-Auth-Key used by the sync plugin
2. Go to **Actions → Deploy CF Press → Run workflow**, set the worker name (default `cfpress`).
3. It creates D1 / KV / R2, generates `wrangler.toml`, deploys, and sets the secret.
4. Open `https://<worker>.<account>.workers.dev/admin/` to finish setup.

> **Repository owner?** The button creates a *copy* of the repo in your own account, so if `cfpress` already exists there it will report "project already exists". Change the project name in the setup page (e.g. `cfpress-site`), or delete the leftover project first. Owners can also just use the CLI install above.

## Themes

Built-in packs live in `worker/static/wp-content/themes/`:

| id | description |
|----|-------------|
| `cfse-demo` | CF Press Premium (EN) |
| `cfse-minimal` | CF Press Minimal (EN) |
| `docs-wiki` | Docs shell |
| `twentyten` / `twentyeleven` | Classic blog themes |

Add your own: create `worker/static/wp-content/themes/<id>/tpl.json` + `style.css`, then register it in `themes/index.json`.

## Project layout

```
worker/            # the CMS Worker
  src/             # routes, rendering, admin API, template engine
  static/          # assets, admin SPA, themes
  scripts/         # setup.mjs (resource bootstrap)
  migrations/      # D1 migrations
  wrangler.toml    # default config (used by Deploy to Cloudflare)
schema.sql         # D1 schema
```

## License

MIT
