---
title: Static where it should be, live where it matters
slug: static-where-it-should-be
category: Engineering
tags: architecture, static-site, d1, cloudflare
excerpt: Pay for rendering when content changes, not when it is read. The split is easy; invalidation is where the work lives.
---

Most CMS advice is a binary: cache everything, or cache nothing. Both are wrong for the same reason — they treat every page as if it had the same lifetime.

A post page changes when you edit it. A list changes when anyone publishes anything. A tag archive changes when one post gets tagged. Those are different problems, and they deserve different answers.

## The two failure modes

**Fully static.** Every publish triggers a site build. On a small site that is a ten-second delay in a CI pipeline; on a large one it is minutes, and the build queue becomes your publishing bottleneck. The usual workaround is to render lists client-side, which means shipping your content twice and hoping a crawler runs your JavaScript.

**Fully dynamic.** Every request is a query. It works, and it is simple, and it means your cheapest page — the one nobody edits — costs the same as your most expensive one. It also means the database is on the critical path for a page that could have been a file.

CF Press takes the split literally:

- A **published post** is rendered to HTML at publish time and written to R2. It is a file. Serving it touches no database.
- A **list page** — home, category, tag, archive — is rendered per request from D1. Publishing a post makes it appear immediately, with no build step.

The rule is not "static is better". The rule is *pay for rendering at the moment the content changes, not at the moment it is read*.

## What that costs in code

The interesting part is not the split, it is invalidation. A static file that is never invalidated is just a stale page with good latency.

Three rules cover almost everything:

**Editing one post invalidates one page.** The old file is deleted and rewritten. Nothing else in the site is touched.

**Publishing invalidates the lists.** They are not files, so there is nothing to delete — the next request simply queries D1 and sees the new row.

**Changing a template invalidates everything.** This is the honest exception. A template is shared by every page, so every rendered page is now wrong. The admin says so, and asks you to rebuild.

Documentation adds a fourth, and it is the one that took the longest to get right. A document page is rendered from Markdown on first request, and the result is cached as a file. Editing one document deletes its rendered page *and* the listing pages of its directory and every ancestor — because a table of contents three levels up now shows a different title. Deleting a whole section is still cheap: delete the sources, delete the outputs, do not walk the tree.

## The bill comes in consistency

A split architecture has two representations of the same thing: the database is the truth, the files are a derived cache. Every bug worth having in this design is a drift between those two.

We hit all of them:

- A post marked as a draft in the CMS but published in WordPress. The file existed, the row said draft. Now the rule is explicit: post state lives in D1, and the plugin treats D1 as authoritative on the way back.
- A page that kept serving an old template after a theme switch. The template changed, the file did not. Fixed by making the rebuild step part of the theme-switch instructions rather than a thing you are supposed to remember.
- A rendered document that outlived its source. Fixed by never letting a write path exist that does not also delete the output.

The lesson is unglamorous: if you keep a derived copy, every write path must be responsible for invalidating it, and there is no clever substitute for auditing that.

## When the split is not worth it

If your content changes hourly and your traffic is small, run everything live and cache nothing. The split buys you latency at the cost of a second representation, and at low traffic the latency does not matter.

If your content never changes, generate the whole thing at build time and skip the database entirely.

The split earns its complexity in the middle: sites that publish often enough for rebuilds to be annoying, and are read often enough for per-request queries to be wasteful.

That is most content sites, which is why the architecture is worth the extra moving part.
