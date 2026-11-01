---
title: How the sync behaves
order: 3
---

# How the sync behaves

Two systems holding the same content need a rule for every conflict. These are the ones worth knowing before you rely on the plugin.

## One post, on save

When you save or publish a post, the plugin does not export it immediately. It **queues** the post and pushes it at the end of the request — after WordPress has written categories, tags and the featured image.

That detail matters. An earlier version pushed on `save_post`, which fires *before* terms and thumbnails are attached, so posts published through the REST API arrived with no category, no tags and no image. Deferring to shutdown fixed all three at once.

Each push also does a local check first: if the post has not been modified since its last successful sync, nothing is sent. That is why a full re-export of 300 unchanged posts finishes in about a second.

## Conflict protection

Every post is compared before writing. If the CMS copy is **newer** than the WordPress copy, the push is refused and the log says so:

```
文章 2388 未写入：CMS 侧版本更新，先「拉取文章」对齐，或接受覆盖后再推
```

That is not an error — it is the plugin refusing to silently discard an edit made in the CMS. Resolve it by pulling from the CMS first, or by deciding WordPress should win and re-saving after an overwrite pull.

## Daily incremental sync

There is a daily cron job that pushes posts which have changed since their last sync — up to **每日定时推 N 篇** (default 50), with **失败重试 N 次** per post.

Think of it as a safety net, not a scheduler: WordPress cron only fires when someone visits the site. If nobody visits, nothing runs. The per-post push on save is the primary path; this catches whatever slipped through (bulk edits, imports, a request that died mid-push).

## Pull from the CMS

**拉取文章** imports posts that were created in the CMS into WordPress, so you can edit them in the familiar editor — or hand the site to someone who only works in WordPress.

Two modes, chosen by a checkbox on the same screen:

| Mode | Behaviour |
|---|---|
| additive (default) | inserts posts that do not exist yet; leaves existing ones alone |
| overwrite | also updates posts that match an existing CMS post |

Overwrite is a one-way door for that post: whatever is in the CMS wins. Use it for a deliberate migration, not as a habit.

## Comments

Comments are the usual reason people keep a dynamic site. Here they are not a reason to.

- A comment posted on the CF Press site is written into WordPress with its approval state and origin — **同步评论到 CMS** / **拉取评论** move them on demand, and a daily pull runs on its own.
- Moderation can happen on either side. An unapproved comment never renders on the static site, whichever side it arrived from.
- Approving in WordPress and pulling is the normal loop: the reader sees the reply on the next request, with no rebuild.

## Reset markers

**重置同步标记** clears the WordPress-side bookkeeping (`_cfse_synced_d1`, content hashes, image fingerprints) so the next export treats every post as new.

It touches nothing on the CMS side — no rows deleted, no cache purged. Use it when you suspect the local state has drifted, not as a "fix everything" button.

## Several sites from one WordPress

One install can write into more than one CF Press site. Add a row per site under **托管目标**, then assign posts either:

- per post, in the **🌐 站点** box on the editor sidebar, or
- in bulk, from the post list's bulk actions.

Posts are routed by that assignment; the default target is used when none is set.

## Two caveats

**Deleting the plugin deletes its settings.** WordPress runs `uninstall.php`, which removes the CMS URL, the auth key and the target list. Updating by uploading a new zip over the old one is fine; *deleting* is not a way to "reset".

**Trash and draft do not unpublish instantly.** The CMS row updates immediately, but the rendered page stays on R2 until orphan cleanup runs. If a retraction has to be immediate, open **Settings → Content maintenance** in the CMS and press **Clear orphan files**.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `401` on every request | `AUTH_KEY_SECRET` and the plugin's auth key differ |
| Export finishes but pages 404 | The Worker's site URL is wrong, so R2 keys and public paths disagree |
| Media missing after export | The R2 binding is missing or points at another bucket |
| Lists empty but posts exist | Posts were exported as drafts |
| Nothing syncs, log says no target | The hosting target is unset or disabled |
| Push refused, log mentions "CMS 侧版本更新" | The CMS copy is newer — pull first, or accept the overwrite |

Next: [Obsidian publisher](/plugin/obsidian-plugin.html).
