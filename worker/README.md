# CF Press — Worker CMS

This directory is the CF Press Worker: D1 + R2 + KV bindings, edge rendering, and the built-in admin. For full install/deploy instructions see the repository root [`README.md`](../README.md).

Quick start:

```bash
cd worker
npm install
npx wrangler login
node scripts/setup.mjs      # create D1 / KV / R2 and generate wrangler.toml
npx wrangler deploy
```

Then open `/admin/` on the deployed Worker to create the admin account and set the site name / URL.

- `src/` — routes, rendering, admin API, template engine
- `static/` — assets, admin SPA, themes (`static/wp-content/themes/`)
- `scripts/setup.mjs` — resource bootstrap + `wrangler.toml` generator
- `schema.sql` — D1 schema
