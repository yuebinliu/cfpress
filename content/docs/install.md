---
title: Install
---

# Install CF Press

There are two ways to get a site running. The button is the short one: no Node, no terminal, about two minutes. The command line is for people who already have the repository, or who want to see exactly what gets created.

Both end in the same place: a Worker with a D1 database, a KV namespace and an R2 bucket.

## Option A — one-click deploy

[![Deploy to Cloudflare](https://deploy.workers.cloudflare.com/button)](https://deploy.workers.cloudflare.com/?url=https://github.com/yuebinliu/cfpress/tree/main/worker)

1. Click the button and sign in to GitHub and Cloudflare.
2. Cloudflare copies the repository into *your* GitHub account and reads `worker/wrangler.toml`.
3. It creates three resources in your account — D1 for content, KV for the site cache, R2 for pages and media — and fills their IDs into that copy.
4. The Worker builds and deploys. You get a `*.workers.dev` URL.

Open the URL. The database is empty, so you land in the setup wizard.

You need a Cloudflare account and a GitHub account. Nothing else — no local tooling.

> **One naming catch.** The button creates a new repository called `cfpress`. If your GitHub account already has a repository by that name, Cloudflare stops with "a project with this name already exists" and creates nothing. Rename the existing repository, or use Option B. This is the most common failure with deploy buttons, and it is a name collision, not a build error.

## Option B — command line

You need your Cloudflare account, Node.js 18 or newer, and a terminal.

### 1. Get the code

```bash
git clone https://github.com/yuebinliu/cfpress.git
cd cfpress/worker
npm install
npx wrangler login
```

The login opens a browser window. On a server without a browser, set `CLOUDFLARE_API_TOKEN` in the environment instead.

### 2. Provision the resources

```bash
node scripts/setup.mjs --name=cfpress
```

The script creates the same three resources and writes a local config file:

- a **D1 database** for content and lists;
- a **KV namespace** for the site context cache;
- an **R2 bucket** for static pages and media.

It prompts for confirmation before creating anything. To see what it would do without touching your account, add `--dry-run`; to skip the prompts in CI, add `--yes`.

> The generated config is written to `wrangler.prod.toml`, which is gitignored. Keep it out of version control — it contains your account's resource IDs.

If you prefer to create the resources by hand, run `wrangler d1 create`, `wrangler kv namespace create` and `wrangler r2 bucket create`, then copy the returned IDs into a config file yourself.

### 3. Deploy

```bash
npx wrangler deploy --config wrangler.prod.toml
```

Note the `--config` flag. The `wrangler.toml` in the repository holds placeholder IDs for the deploy button; your real IDs are in `wrangler.prod.toml`. Deploying without `--config` would publish a Worker with the wrong bindings.

Wrangler prints a `*.workers.dev` URL when it finishes. Open it and you land in the setup wizard.

## Either way — the setup wizard

Open `/admin/` and fill in:

- **Site name** and **site URL** — use the final public URL, without a trailing slash and without `/admin`;
- **Description** — used in the page title and meta description;
- **Admin username and password** — this is the only account, so store it properly.

Submitting the form creates the database tables and the first admin account. There is no separate migration step: the Worker creates any missing tables on its first request.

## Attach your domain

1. Add your domain to Cloudflare and point its nameservers at Cloudflare.
2. In the dashboard open **Workers & Pages → your Worker → Settings → Domains & Routes**.
3. Add a custom domain such as `example.com`.

Certificates usually issue within a minute. Nothing else needs to be configured — there is no origin to point at.

If you filled in the site URL before the domain was resolving, edit it afterwards in **Settings → Site info**. A wrong URL shows up as stylesheets and media requested from the wrong path.

## Upgrading

```bash
git pull
cd worker && npm install
npx wrangler deploy --config wrangler.prod.toml
```

Database changes are applied by the Worker itself, so there is no migration command. Take a backup first if the release notes mention schema changes.

## Continuous deployment (optional)

If you would rather not deploy from your laptop, the repository ships a GitHub Actions workflow. Add `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ACCOUNT_ID` as repository secrets, then run **Actions → Deploy CF Press → Run workflow**. It provisions missing resources and deploys the same way Option B does.

Next: [Writing in the CMS](/docs/writing.html).
