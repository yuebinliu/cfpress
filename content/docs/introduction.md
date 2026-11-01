---
title: What CF Press is
order: 1
---

# What CF Press is

A content management system that runs entirely on Cloudflare: one Worker, D1 for data, R2 for files, KV for settings. No origin server, no container, no database to provision, nothing to patch. You deploy once; everything after that is content.

It fits inside Cloudflare's free tier for a normal blog or docs site, and R2 charges nothing for the traffic your readers generate.

## The trade most CMSs force on you

**Static generators** are fast and cheap, but frozen. A new post, an edited paragraph, a moderated comment — each means rebuild and redeploy.

**Dynamic CMSs** are fresh, but every reader pays for it: PHP wakes up, the database is queried, the page is assembled again, for a page that has not changed in a year.

CF Press refuses the choice by splitting *per-page* from *per-list*:

| What | Where it lives | What a read costs |
|---|---|---|
| A single post or page | one HTML object in R2 | one object read — no database |
| Lists, categories, tags, archives, feed | D1, queried on request | one query, always current |
| Comments | D1, injected into the cached page while serving | one query, no rebuild |
| Name, menu, theme, routes | KV, one read per request, 30s in-memory | negligible |

The page you read is a file. The list that links to it is live.

## What that feels like

- **Publish, then look.** The post page renders on its first visit and is cached in R2 from then on; the list shows it in the same second. No build, no deploy, no cache warm-up.
- **Edit a comment, see it.** Comments are injected at request time, so a new reply or a moderation decision shows up on the next refresh without regenerating anything.
- **Change the theme in the admin.** A theme is a directory with a `tpl.json` and a `style.css`. Switch it and the next render uses it — no asset pipeline, no npm.
- **A read never wakes the database** unless something is missing or genuinely dynamic (a list, a search, a comment).

## What you get

- **A real admin**, not a config file: Markdown and rich-text editing, media library, comment moderation, menus, themes, users, app passwords.
- **Templates as data.** Five themes ship in the box, all editable online, all server-rendered — the HTML that leaves the Worker is the final HTML.
- **A document library.** Markdown files in R2, rendered through the same theme, with an automatic sidebar tree, anchors and per-page tables of contents.
- **An open write API.** A subset of the WordPress REST write API plus application passwords, so scripts and editors publish directly — and the WordPress and Obsidian plugins are thin clients over it.
- **A way out.** `/api/export` returns posts, taxonomies, documents and settings as JSON; media is a plain R2 prefix. Nothing is locked in a proprietary export format.

## Where it fits

| If what you need is | Use |
|---|---|
| A blog or docs site that is fast, nearly free, and has no server to babysit | **CF Press** |
| You already run WordPress and want to keep its editor but not its hosting bill | **CF Press + the WordPress plugin** |
| Drag-and-drop page building, 60,000 plugins, e-commerce, memberships | WordPress |
| A build pipeline over thousands of pages, control down to the component | Astro, Hugo, Eleventy |
| One content API feeding a site, an app and an agent | a headless CMS (Strapi, Payload, SonicJS) |

## What it is not

- **Not hosted.** You deploy it into your own Cloudflare account. There is no sign-up, no account, and nobody between you and your data.
- **Not a page builder.** Posts are Markdown or rich text, and layout comes from templates. If you need to drag blocks around, this is the wrong tool.
- **Not a plugin ecosystem.** Two integrations ship and the API is open, but there is no marketplace and no third-party theme store.
- **Not for arbitrary content models.** The schema is posts, taxonomies and documents. Products, courses or staff directories would need code, not configuration.

Next: [Install CF Press](/docs/install.html).
