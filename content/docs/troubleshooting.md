---
title: Troubleshooting
---

# When something looks wrong

Most problems on a CF Press site come from four places: the site URL, a stale static file, an un-migrated permalink change, or a deploy that used the wrong config. This page is ordered by symptom.

## The site returns 500, but `/admin/` works

The database is missing its tables. That happens when the Worker is deployed but the setup wizard was never completed — the tables are created by the initialisation step, not by the deploy.

Open `/admin/`, and if you land in the wizard, complete it. If the wizard is gone and the error persists, check that the D1 binding in your deployed config points at the database that actually holds your data: a deploy with placeholder IDs creates a fresh, empty database and silently leaves your content behind.

## Styles and images 404

The site URL almost always has the wrong shape. It must be the public origin, with no trailing slash and no `/admin`:

```
https://example.com          ← correct
https://example.com/         ← trailing slash: links get a double slash
https://example.com/admin    ← everything resolves under /admin
http://example.com           ← mixed content on an HTTPS site
```

Fix it in **Settings → Site information**, then purge the cache. If you set the URL before the domain was attached, this is exactly what happens.

## I changed the theme and nothing moved

List pages and archives pick up a new theme on the next request. **Post pages do not** — they are static HTML written when the post was published, and they keep the old markup until you regenerate them.

Go to **Settings → Rebuild all post pages**. Same answer for a template edit, and for a site name change if you care about what appears in the byline.

## I changed a setting and it took minutes to appear

That is the cache TTL doing its job. Lists, sidebars and the site context are cached for the durations under **Settings → Cache**. Either wait one TTL, or press **Purge all caches** to make it immediate.

If a change never appears at all, check whether it is one of the static things: post pages and document pages are files, not queries.

## Every post 404s after changing permalinks

You changed the pattern without migrating. The links now point at paths that were never written.

Go back to **Settings → Permalinks** and press **Start migration**. It rewrites the stored pages to the new paths and keeps the old files until you clean up. `Preview` before `Migrate` is the habit worth forming.

## A document 404s even though the file exists

Three things, in order:

1. The library's URL prefix (`xyz`) does not match the path you are visiting.
2. The file was written to R2 directly without registering it in `documents` — the router consults the database, not the bucket listing. Upload through the admin, or use `push-docs.mjs`.
3. The page is a section front page but there is no `index.md` at that level, and index generation is off for that library.

## An image will not upload

- Over 10MB — rejected before it is stored.
- Not jpg, jpeg, png, gif or webp — rejected by extension *and* by inspecting the first bytes, so renaming does not help.
- "Not a valid image" on a real image — usually a truncated download or a format the sniffer does not recognise (AVIF, HEIC). Convert to webp.

## Comments do not appear

Check, in this order: **mode** is `off` (nothing renders at all), **accept** is `off` (readers cannot submit), the comment is still `pending` (not approved), or the post already has 500 approved comments, which is the per-page ceiling.

If the comment came from WordPress and shows as *managed by WP*, it is read-only here by design — approve it in WordPress.

## The deploy went out with the wrong bindings

`wrangler deploy` reads `wrangler.toml`. If your real resource IDs are in `wrangler.prod.toml`, you must pass the config explicitly:

```bash
npx wrangler deploy --config wrangler.prod.toml
```

Deploying the default file publishes a Worker whose D1, KV and R2 bindings point at placeholders or at a different account's resources. The symptom is an empty site that still returns 200.

## I keep getting sent back to the login screen

The access token lives 15 minutes; the refresh token lives 7 days. If the refresh token is gone — cleared storage, private window, a different browser — the next request cannot renew and you land on the login form. Sign in again.

If it happens every few minutes on the same browser, check that cookies and local storage are not being cleared by an extension or a privacy setting.

## The site name keeps reverting

If the WordPress plugin is connected and set to sync site information, WordPress is the source of truth and will overwrite the name you set here on the next sync. Either change it in WordPress, or turn that sync off.

Next: [Write API](/docs/reference/api.html).
