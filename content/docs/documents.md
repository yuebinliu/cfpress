---
title: The document library
order: 6
---

# The document library

Posts are a stream. Documentation is a tree: it has sections, subsections, and pages that belong to other pages. Squeezing that into categories produces a category page nobody can navigate.

So documentation has its own library, with its own URL space, its own sidebar and its own template. Nothing in it appears in "latest posts", and nothing about your blog theme changes how it looks.

## One library, one prefix pair

Open **Documents → Libraries**. Each row maps two prefixes:

| | meaning | example |
|---|---|---|
| **abc** | where the source files live in R2 | `docs` |
| **xyz** | the public URL prefix | `docs` |

They are usually the same word, and the URL prefix must be unique across the site. One row is one library. A site with a manual at `/docs/` and plugin documentation at `/plugin/` has two rows, and the two trees never see each other.

This site does exactly that: `/docs/` for the CMS, `/plugin/` for the WordPress plugin.

## Paths are the hierarchy

There is no parent field and no ordering field to maintain. The file path *is* the structure:

```
content/docs/install.md              →  /docs/install.html
content/docs/reference/api.md        →  /docs/reference/api.html
content/docs/reference/index.md      →  /docs/reference/       (that section's front page)
```

The sidebar tree is generated from those paths, and the current page is highlighted by comparing paths — not by a stored id, which is why renaming a file cannot leave a stale entry behind.

### index.md is a section front page

A file named `index.md` is special: it renders at the directory URL rather than at `/index.html`.

```
content/docs/reference/index.md  →  /docs/reference/
```

Any directory level can have one. If a directory has no `index.md`, CF Press generates a listing page instead — files first, then subdirectories, paginated at 50 per page. That listing is what you get at `/docs/` on this site: there is no `content/docs/index.md`, only the files underneath.

### Relative links are rewritten

Write links the way you would in a plain Markdown repository:

```markdown
See [the API](reference/api.md) and [install](install.md#option-a).
```

The renderer resolves them against the current file's directory and rewrites them to the public HTML paths, keeping the `#anchor`. Absolute links that point inside the library (`/docs/...`) are rewritten too. External links, `mailto:`, and pure anchors are left alone.

## Uploading

Three routes, same result:

**Single file** — **Documents → Documents and upload**, choose a file, give it a path relative to the library root (`reference/api.md`). Title is optional; without one, the first `#` heading is used.

**A directory** — the bulk importer takes a folder, optionally strips the top-level folder name and adds a prefix. It reads files in the browser and posts them in one request, so the practical ceiling is the 25MB request body limit. For a manual of a few hundred pages, use the script instead.

**From the repository** — `worker/scripts/push-docs.mjs` walks `content/`, uploads each file, upserts the metadata, and invalidates the pages it affected:

```bash
cd worker
node scripts/push-docs.mjs --only=docs
node scripts/push-docs.mjs --dry-run     # print the commands, touch nothing
```

Keeping sources in git is the point of this route: a documentation change becomes a diff you can review and revert, and the backup of your docs is the repository.

## What happens on change

A document is two objects: the source (`_src/…`) and the rendered page (`_pages/…`). Rendering happens on first request, not on upload — so publishing twenty files does not render twenty pages.

| You change | What is invalidated |
|---|---|
| one document | its own rendered page, plus the listing pages of its directory and every ancestor |
| the document template | every rendered document page (up to 300 at a time; the rest rebuild as they are visited) |
| the library mapping | the site context; pages rebuild lazily |

Deleting a document is a delete of the source and its rendered page. Nothing else in the tree needs to be touched, which is why deleting a whole section does not get slower as the tree grows.

## The document template

Document pages do **not** use the six blog templates. They use one shell, edited under **Documents → Document template**. It is a single HTML file with placeholders:

| token | content |
|---|---|
| `{{DOC_TITLE}}` | the page title |
| `{{CONTENT}}` | the rendered article body |
| `{{DOC_TOC}}` | the "on this page" list, built from `h2`/`h3` |
| `{{DOC_SIDEBAR}}` | the navigation tree |
| `{{SITE_NAME}}` `{{SITE_URL}}` `{{SITE_DESC}}` | site identity |
| `{{BRAND_MARK}}` `{{BRAND_WORD}}` `{{SITE_NAV}}` | header brand and navigation |
| `{{SITE_FOOTER}}` `{{FOOTER_NAV}}` `{{CSS_URL}}` | footer and stylesheet |

`{{CONTENT}}` and `{{DOC_TITLE}}` are mandatory — saving without them is refused, because a shell missing `{{CONTENT}}` renders every document as a blank page.

The shell is **site-wide**, not per library: all libraries share one look. Styles come from the active theme, so a document page inherits your fonts and colours; anything documentation-specific goes in a `<style>` block inside the shell.

Editing the shell clears rendered document pages so they pick up the change. There is no separate "apply" step.

## When a page looks wrong

- **404 on a document that exists** — check the library row's `xyz` matches the URL prefix, then press **Rebuild library** in the Documents tab.
- **Old content after an edit** — the rendered page was not invalidated. Re-upload the file, or run `push-docs.mjs` again; both delete the stale output.
- **A section has no front page** — that is expected unless you add `index.md` at that level.
- **The sidebar is missing a page** — the file is not registered in `documents`. Uploading through the admin does this for you; a raw `r2 object put` does not.

Next: [Write API](/docs/reference/api.html).
