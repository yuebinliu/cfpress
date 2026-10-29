# Installing the plugin

Three things have to agree before the first export: the Worker URL, the auth key, and which site is the source of truth.

## 1. Get a Worker auth key

The plugin talks to the Worker over HTTP and authenticates with `X-Auth-Key`. The Worker reads that value from the `AUTH_KEY_SECRET` secret.

```bash
cd cfpress/worker
npx wrangler secret put AUTH_KEY_SECRET --config wrangler.prod.toml
```

Pick a long random string. Wrangler asks for the value and stores it; it is not written to a file.

If you skip this step the Worker has no secret to compare against, and every plugin request is rejected with `401`.

## 2. Install the plugin

Download the plugin zip from the repository's **Releases** page, then in WordPress open **Plugins → Add New → Upload Plugin**, choose the zip and activate it.

> If the Releases page is still empty, the plugin has not been published yet. The CMS works without it — come back to this page when there is a zip to install.

## 3. Fill in the settings

In **Settings → CF Static Export**, enter:

- **Worker URL** — the site's public address, for example `https://example.com`. No trailing slash, no `/admin`.
- **Auth key** — the exact value you gave `AUTH_KEY_SECRET`.

Save, then use the **Test connection** button. A green result means the Worker answered and accepted the key. `401` means the two keys differ — retype both rather than trimming them.

## 4. First export

Press **Export**. This is the big one: it walks every post, uploads media to R2, writes posts and taxonomies into D1, and renders each post into a static page.

Expect the first run to take a while on a large site. Later exports only touch what changed.

Two things worth knowing before you press it:

- the first export **overwrites** anything already in D1 with the same post ID;
- if you set posts to draft inside the CMS, a later export replaces that state with WordPress's own. See [Sync rules](/plugin/sync-rules.html).

When it finishes, open the site. Lists come live from D1, so they are correct immediately.

## 5. Keep your theme (optional)

The plugin can capture the WordPress theme you are currently using and store it as a CF Press style pack. The static site then starts out looking like the site you already had, instead of a default skin.

Run it once after the first export, then refine the CSS in the CMS under **Templates**.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `401` on every request | `AUTH_KEY_SECRET` and the plugin's auth key differ |
| Export finishes, pages 404 | the Worker's site URL is wrong, so R2 keys and public paths disagree |
| Media missing after export | the bucket binding is missing or points at another bucket |
| Lists empty but posts exist | posts were exported as drafts |

Next: [Sync rules](/plugin/sync-rules.html).
