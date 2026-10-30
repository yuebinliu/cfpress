---
title: What a Cloudflare-native CMS actually costs
slug: what-a-cloudflare-cms-actually-costs
category: Engineering
tags: cloudflare, cost, d1, r2
excerpt: Four meters decide what a Cloudflare CMS actually costs — and only one of them is about money. Here is what each page turns on.
---

The pitch for running a CMS on Cloudflare is "$0". That number is real, but it is also the least interesting thing about the architecture. What matters is that every page request has a *shape*, and each shape has a price you can predict before you publish anything.

This post walks through the four meters Cloudflare actually reads, and what a normal post or document page turns on for each of them.

## The four meters

**Requests.** Every time a visitor hits a URL that is not a static asset, a Worker runs. Requests are the coarsest meter and the one people watch.

**CPU time.** Measured in milliseconds per request, and on the free plan the ceiling is small — a few milliseconds of actual CPU, not wall-clock time. Waiting on D1 or R2 does not burn it; parsing, rendering, and looping do. This is the limit that decides what you can do inside one request, and it is the reason batch operations are chunked instead of done in one go.

**D1 rows read and written.** Reads are cheap and generous, writes much less so. Every list page is a read; every publish is a write.

**R2 storage and operations.** Storage is the cheap part. Class A operations (writes, lists) and Class B (reads) are separate meters, and a media library full of large images is billed on both bytes and reads.

KV is the fifth meter, but it only matters at the margins here: site context, cached fragments. The design keeps it small on purpose.

## What one page costs

A **published post** is a file. It was rendered once, when you pressed publish, and written to R2. Serving it is a read — and if the edge already has it, not even that. No database is touched, which is why a post page survives a traffic spike without touching the D1 budget.

A **list page** — home, category, tag, archive — queries D1 on every uncached request, then renders. That is one read-heavy query plus a `COUNT`, and it is the page that scales worst if you get popular. Which is exactly why the cache TTL for lists exists, and why setting it to zero is a deliberate choice rather than a default.

A **document page** is the hybrid: the source Markdown lives in R2, the rendered HTML is written back to R2 on first request, and after that it is served like a post. The first visitor pays for rendering; everyone else pays for a read.

**Media** is the only thing that grows without bound. A rendered page is a few kilobytes; a photograph is not. Ten 4MB images per post, three hundred posts, and R2 starts being the line item that matters — not the CMS.

## Where the free plan actually bends

Three limits shape the product more than any price list:

1. **CPU per request.** Rendering Markdown is fine. Rendering two hundred documents in one request is not. Every bulk action in the admin is therefore chunked, and the batch size is a setting rather than a constant — because a template can get expensive.
2. **Request body size.** 25MB per request. Importing a directory of documentation through the browser hits this quickly; the script version uploads one file at a time for that reason.
3. **D1 write volume.** Publishing writes a row and touches taxonomy tables. Normal publishing will not trouble it. A loop that republishes every post on a schedule will.

None of these is a billing trap. They are design constraints that leak into the product: the chunked rebuilds, the "delete the rendered page and rebuild on next visit" strategy, the separate upload path for large imports.

## What actually moves the needle

If you want to keep the bill at zero, the order of impact is roughly:

- **Cache the lists.** A cached home page turns hundreds of D1 reads into one. This is the single biggest lever and it is one number in the settings.
- **Do not rebuild everything for one change.** Editing one post should invalidate one page. Editing a template is the only case that justifies a mass rebuild.
- **Keep images sane.** Resize before upload. The CMS does not do it for you, and a 4MB hero image is served to every visitor on every device.
- **Let static pages be static.** Injecting per-request logic into a page that could be a file is how a cheap site becomes an expensive one.

## The honest summary

A small site — a few hundred posts, a few thousand documents reads a day — runs inside the free allowances without thinking about it. The failure mode is not a surprise invoice; it is a page that gets popular and a list TTL set to zero, which turns every visitor into a database query.

The point of the split is not that it is free. It is that the cost of each request is decided when you write the code, not discovered at the end of the month.
