---
title: Comments
order: 7
---

# Comments

Comments are the one part of a static site that cannot be a file. A page baked to R2 has no way to accept a new row, so CF Press splits the job: the page stays static, the comment thread is assembled from D1 when the page is served.

Everything lives under **Comments** in the admin.

## Two settings, not one

**Mode** decides how the thread appears:

| mode | result |
|---|---|
| `off` | the comment area and the form are removed from the page |
| `native` | the theme's own comment markup, filled from D1 |
| `embed` | your third-party snippet is injected where the thread would be |

`native` is the one that needs explaining. When a post page is rendered, the thread is snapshotted into the HTML like everything else. At serve time, CF Press replaces that snapshot with a freshly built thread from D1. In practice: the page is still a static file, but approved comments appear without a rebuild.

`embed` exists for people who already use Disqus or a hosted service. Paste the snippet; it is output as-is.

**Accept** decides whether the site takes submissions from readers:

| accept | result |
|---|---|
| `off` | submissions are refused — the default |
| `pending` | stored, invisible until approved |
| `auto` | published immediately |

Leave it at `off` unless you want comments. `auto` on a public site with no other protection means the first spam wave is visible to everyone.

## Moderation

The list has three filters, and they answer different questions:

- **Not pulled back** — comments submitted on the static site that WordPress has not collected yet. This is your queue; approving here only sets a flag, the comment is still yours to publish.
- **Pending** — everything not approved, wherever it came from.
- **All** — including the ones WordPress already owns.

Comments that came in through WordPress are marked **managed by WP** and are read-only here. That is not an oversight: WordPress is the authority for those rows, and editing one on this side would be overwritten on the next sync. Approve or delete them in WordPress.

## Who submits, and how

On a `native` site the form posts to the Worker, which stores the comment in D1 with `approved` set according to the **accept** setting. The endpoint is public — a visitor has no login — so it is rate-limited per IP, and the fields are length-capped. That is the whole defence. If you need more, use `embed` with a service that does spam scoring, or keep `accept` at `off` and let WordPress handle comments.

Reader-submitted comments carry an origin marker, which is how you can tell them apart from comments that arrived through a WordPress pull.

## With WordPress in the loop

The plugin pulls pending comments from the Worker into WordPress, where they land in the normal moderation queue. Once pulled, WordPress owns them: approving there is what publishes them, and the next sync writes that state back. If you run both, moderate in WordPress, not here — two moderation queues that disagree is worse than one.

Comments already approved in WordPress appear on the static site on the next served request, with no rebuild.

Next: [Media](/docs/media.html).
