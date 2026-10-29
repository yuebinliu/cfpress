# Writing in the CMS

Once you are signed in, `/admin/` is where everything happens. The left rail is the whole product: posts, taxonomies, media, comments, menus, themes, settings.

## Posts

The editor accepts Markdown or rich text — the toggle is in the toolbar, and you can switch mid-post. Each post has a title, a slug, an excerpt, and a publish state.

Two things work differently from WordPress:

- **Single pages are pre-rendered.** Saving a published post writes its HTML to R2 immediately, so the page is served as a static file from the edge.
- **Lists are live.** The moment a post is published it appears in the home list, category pages and archives, with no rebuild step. Publish is the only action you need.

Drafts stay in D1 and are never written to R2, so an unfinished post has no public HTML to leak.

## Taxonomies and menus

Categories and tags are managed under their own screens and behave as you would expect. A taxonomy with no published posts is hidden from the sidebar automatically.

Menus are **separate from taxonomies**. The header navigation is a menu tree you build yourself, with items that can point at a category, a tag, a post, or any custom URL. If the menu is empty, the header simply omits the nav — it will not guess.

## Themes

A theme is a directory under `wp-content/themes/<id>/` containing:

- `tpl.json` — the HTML templates with `{{PLACEHOLDER}}` tokens;
- `style.css` — the visual layer.

Switching the active theme in the admin takes effect on the next request. Five themes ship built in, including a premium and a minimal variant of the same layout. Because templates are data, you can iterate on a theme in the browser without redeploying the Worker.

## The document library

Long-form documentation does not belong in the post stream — it has hierarchy, and it should not appear in "latest posts". CF Press has a separate library for it:

- source files live in R2 under a prefix (for example `docs/`);
- metadata lives in D1;
- the public URL space is separate as well, so docs sit at `/docs/...` rather than inside a category;
- the sidebar tree is generated from file paths, and relative links between files are rewritten automatically.

You write Markdown locally, upload the files, and the library renders them through the active theme. Because the sources are plain files, they can live in git next to the code that documents them.

## Comments

Comments are stored in D1 and injected when a page is served. An approved comment appears immediately with no rebuild. Moderation lives in the admin; if you connect WordPress, comments can also sync back there.

Next: [The WordPress plugin](/plugin/overview.html).
