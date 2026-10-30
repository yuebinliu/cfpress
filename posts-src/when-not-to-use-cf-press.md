---
title: When not to use CF Press
slug: when-not-to-use-cf-press
category: Comparisons
tags: wordpress, comparisons, choosing
excerpt: A comparison that only lists advantages is an advertisement. These are the cases where WordPress, or a plain static generator, is the better answer.
---

A comparison page that only lists advantages is an advertisement. This one starts with the cases where you should pick something else, because those are the decisions that cost people the most time.

## Pick WordPress instead if

**You need plugins.** Not "a plugin for SEO" — WordPress does SEO natively well enough — but the long tail: a booking system, a membership paywall, a WooCommerce store, a form builder with conditional logic. CF Press has none of that and no plugin API to add it. Rebuilding a booking flow as a Cloudflare Worker is a project, not an afternoon.

**A non-technical person edits the site.** The admin here is competent and small: posts, media, taxonomies, themes, settings, comments. It is not a page builder. If your editor expects to drag blocks around and see the result immediately, they will find this cramped. WordPress with a block theme is genuinely better at that, and pretending otherwise wastes everyone's time.

**You need a team workflow.** Editorial roles, review states, scheduled publishing with approvals, revision history you can diff. There is one permission boundary here — admin versus everyone else — and drafts are drafts.

**You want someone else to be responsible.** WordPress has hosting companies, agencies, and a phone number you can call. CF Press is a repository and a Worker; when it breaks at 2am, the person who fixes it is you.

## Pick a static generator instead if

**Nobody needs to log in.** If publishing is "commit and push", Hugo or Eleventy with a CI pipeline is simpler than this. There is no database to back up, no admin to secure, no session to expire.

**Your content is truly static.** A brochure site with five pages does not need a CMS, and adding one is how a five-page site acquires a database and a maintenance burden.

## Where CF Press is the right answer

The honest description: **a content site that publishes regularly, is read by strangers, and has no budget for servers.**

- A blog or a documentation site where the author is also the person who runs it.
- A WordPress site whose publishing workflow is fine but whose hosting bill is not — the plugin exists precisely for this: keep the editor, serve from the edge.
- A site that must not go down because a database is being upgraded, because the pages are files.
- A project where "$0 and predictable" beats "cheap until it is not".

## The comparison, without the spin

| | CF Press | WordPress | Static generator |
|---|---|---|---|
| Server to maintain | none | yes (or a host) | none |
| Admin UI | yes, small | yes, mature | no |
| Plugin ecosystem | none | thousands | none |
| Editing for non-technical people | adequate | better | worse |
| Cost at low traffic | free tier | hosting + domain | free tier |
| Cost at high traffic | predictable, per-request | scales with host | near zero |
| Comments | built in, basic | built in, mature | add a service |
| Getting your data out | JSON export + files | database + files | the repo |

Two rows deserve expansion.

**Cost at high traffic.** A static generator wins outright — files on a CDN. CF Press is close, but list pages still hit D1, and at real scale that becomes the meter to watch. If you expect millions of page views a month, this is not the cheapest possible architecture; it is the cheapest one that still has an admin.

**Getting your data out.** The export writes posts, taxonomies, document metadata and site settings as JSON. Document sources are Markdown in a git repository. Media is in R2 and copies out with any S3-compatible tool. There is no proprietary format holding your content hostage, and that was a design requirement rather than a feature.

## What is actually unfinished

CF Press is beta. The plugin is a separate download and not on wordpress.org. There is no theme marketplace — five themes ship in the box and you edit `tpl.json` if you want a sixth. The admin is in Chinese with English strings being added; the docs are ahead of the UI on that front.

If those gaps are disqualifying, they are disqualifying today, not "soon".

## The test

Ask one question: **does anyone need to log in to publish?**

If no, use a static generator. If yes, ask whether that person needs plugins. If yes, use WordPress. If no, you are in the narrow band this was built for — and the docs will tell you honestly where the edges are.
