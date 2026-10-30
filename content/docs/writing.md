---
title: Writing
---

# Writing in the CMS

Once you are signed in, `/admin/` is where everything happens. The top bar is the whole product: posts, categories and tags, media, users, templates, documents, settings, comments. It is a small surface on purpose — if a screen is not there, the feature does not exist.

## Posts

The editor accepts Markdown or rich text — the toggle is in the toolbar, and you can switch mid-post. Each post has a title, a slug, an excerpt, and a publish state.

Two things work differently from WordPress:

- **Single pages are pre-rendered.** Saving a published post writes its HTML to R2 immediately, so the page is served as a static file from the edge.
- **Lists are live.** The moment a post is published it appears in the home list, category pages and archives, with no rebuild step. Publish is the only action you need.

Drafts stay in D1 and are never written to R2, so an unfinished post has no public HTML to leak.

## Taxonomies and menus

Categories and tags are managed under their own screens and behave as you would expect. A taxonomy with no published posts is hidden from the sidebar automatically.

Menus are **separate from taxonomies**, and they live in **Settings → Navigation menu** rather than on a screen of their own. The header navigation is a tree you build yourself, with items that can point at a category, a tag, a post, or any custom URL. If the menu is empty, the header simply omits the nav — it will not guess.

## Themes

A theme is a directory under `wp-content/themes/<id>/` holding `tpl.json` (six HTML templates with `{{PLACEHOLDER}}` tokens) and `style.css`. Five ship built in; switching the active one takes effect on the next request for lists and archives.

Post pages are the exception — they are static HTML, so they keep the old theme until you rebuild them. That one detail is behind most "I switched themes and nothing happened" reports. [Themes and templates](/docs/themes.html) covers the six template kinds, the placeholders, and the rebuild step.

## The document library

Long-form documentation does not belong in the post stream — it has hierarchy, and it should not appear in "latest posts". CF Press has a separate library for it, with its own URL space (`/docs/...`), its own sidebar generated from file paths, and its own template rather than the blog theme's.

You write Markdown, upload it, and the library renders it on first request. Sources can live in git next to the code they document. [The document library](/docs/documents.html) has the full picture: library setup, `index.md`, link rewriting, and the shell template.

## Comments

Comments are stored in D1 and injected when a page is served. An approved comment appears immediately with no rebuild. Moderation lives in the admin; if you connect WordPress, comments can also sync back there.

Next: [The WordPress plugin](/plugin/overview.html). The rest of the admin has its own pages: [Themes](/docs/themes.html) · [Comments](/docs/comments.html) · [Media](/docs/media.html) · [Users](/docs/users.html) · [Settings](/docs/settings.html).
