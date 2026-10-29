# What CF Press is

CF Press is a content management system that runs entirely on Cloudflare. There is no origin server to patch, no database to provision, and no separate host to pay for. A single Worker serves the whole site, and it fits inside Cloudflare's free plan for a normal blog or docs site.

## One idea: split what is static from what is not

Most CMS platforms pick one of two bad options. Fully static generators are fast but frozen — a new comment or a new post means a rebuild. Fully dynamic CMSs are fresh but slow and expensive, because every page hits a database.

CF Press splits the two:

| What | Where it lives | Why |
|---|---|---|
| Individual posts and pages | R2, as pre-rendered HTML | Reads cost nothing, and R2 charges no egress |
| Lists, categories, tags, archives, search | D1, queried at request time | Publishing shows up immediately, no rebuild |
| Site context (name, menu, styles, routes) | KV, one read per request | Keeps the hot path cheap |

The result: a published post is a static file, but the list that links to it is always current.

## What you get

- **A real admin**, not a config file. Markdown and rich-text editing, a media library, comment moderation, menu editing, and online theme switching.
- **Templates as data.** A theme is a directory with a `tpl.json` and a `style.css`. Switching themes takes effect immediately; five ship in the box.
- **A document library.** Long-form docs live as Markdown files in R2, rendered through the same theme, with an automatic sidebar tree.
- **Comments without a rebuild.** They live in D1 and are injected when the page is served.
- **An open write API.** A subset of the WordPress REST write API, plus application passwords, so scripts and editors can publish.

## If you already run WordPress

You do not have to choose. The companion plugin connects an existing WordPress site to CF Press and keeps both sides in sync, in both directions:

- posts, media and taxonomies export from WordPress to Cloudflare;
- posts written in CF Press can be pulled back into WordPress;
- comments sync either way.

That means you can keep the editing workflow you know while moving delivery to Cloudflare — or run both and let them mirror each other.

## What it is not

CF Press is not a hosted service. You deploy it into your own Cloudflare account, so your content lives in your own D1 and R2. There is no account to sign up for and nobody between you and your data.

Next: [Install CF Press](/docs/install.html).
