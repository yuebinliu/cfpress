# Write API

Everything the admin does goes through HTTP, so anything the admin can do, a script can do. Authentication is a single header:

```
X-Auth-Key: <AUTH_KEY_SECRET>
```

Set the secret once with `wrangler secret put AUTH_KEY_SECRET`. Requests without a matching key get a `401`.

## Run SQL in batches

`POST /d1-batch` executes a list of prepared statements against D1 and returns each result set in order.

```bash
curl -X POST https://example.com/d1-batch \
  -H "X-Auth-Key: $KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "statements": [
      { "sql": "SELECT slug, title FROM posts WHERE status = ? LIMIT ?", "params": ["published", 5] }
    ]
  }'
```

Up to 200 statements per call. The whole batch is not atomic — statements run in order, and the first failure returns `{ "success": false, "error": "...", "index": N }` so you know exactly which one broke.

This is the same channel the WordPress plugin uses, and it is the simplest way to script a bulk import.

## Delete pages

`POST /delete` removes objects from R2 and invalidates the matching pages.

```bash
curl -X POST https://example.com/delete \
  -H "X-Auth-Key: $KEY" \
  -H "Content-Type: application/json" \
  -d '{ "paths": ["/old-post/index.html", "/old-post/index.html.gz"] }'
```

Deleting a single page's HTML does not lose data: the post still exists in D1, so the next request re-renders it into R2 from the current template. That is the supported way to force a rebuild without touching content.

## Purge caches

`POST /purge-cache` clears edge cache entries by key or prefix — useful after changing a menu or a theme, where the HTML is cached but the data did not change.

```bash
curl -X POST https://example.com/purge-cache \
  -H "X-Auth-Key: $KEY" \
  -H "Content-Type: application/json" \
  -d '{ "keys": ["home:page:1"], "prefixes": ["category:", "tag:"] }'
```

## WordPress-compatible endpoints

A subset of the WordPress REST write API is available under `/wp-json/wp/v2/` (and `/wp/v2/`), authenticated with application passwords. This exists so editors that already speak the WordPress API — an Obsidian plugin, a publishing script, a mobile client — can publish without custom code.

The supported subset is deliberately small: creating and updating posts, and reading the taxonomies they need. For anything else, use `/d1-batch` directly.

## Rate limits

The key-authenticated channels are rate limited per source IP. A leak of the key therefore does not turn into an unbounded bill; sustained abuse is throttled before it reaches D1.
