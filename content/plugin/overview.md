---
title: Already on WordPress?
order: 1
---

# Already on WordPress?

You have posts, media, categories, comments and a workflow that works. What you may not want is the hosting bill, the plugin-update treadmill, and a database that has to wake up for every visitor.

The WordPress plugin does not ask you to migrate. **WordPress keeps the content; Cloudflare takes over delivery.**

## What stays, what changes

| Stays exactly as it is | Changes |
|---|---|
| Your editor, media library, categories, tags | Where readers are served from |
| Your comments and their moderation queue | Page speed and hosting cost |
| Your permalinks and slugs | Nothing about how you write |

The plugin copies content out; it never takes over your install. Uninstall it and WordPress is exactly as it was.

## What moves, and in which direction

| Direction | What moves | Started from |
|---|---|---|
| WordPress → CF Press | posts, media, categories, tags | **同步文章到 CMS** in the plugin |
| CF Press → WordPress | posts written in the CMS | **拉取文章** |
| Comments | both ways, keeping approval state | on demand, plus a daily pull |

Most export plugins are one-way: WordPress is the source and the static site is a read-only copy. Here either side can be edited and the other catches up — so you can write in WordPress on Monday and in the CMS on Tuesday without thinking about it.

## The catch worth knowing first

**Your WordPress theme does not come along.** The static site is rendered by a CF Press theme — five ship in the box, all editable online, but none of them is your theme. If the current look matters, plan on restyling in the admin, or keep the old site live while you tune it and switch DNS last.

**Publishing has one owner.** A post's publish state lives in the CMS database. Draft it there, then run a full export, and WordPress's status wins. Decide which side owns publishing; do not draft in one and bulk-export from the other.

**These are the two surprises people hit.** Everything else behaves the way you would expect a sync to behave.

## Is it worth it

It is, if the reason you are reading this is one of these:

- the WordPress host costs more than the content is worth;
- pages are slow because every visitor wakes PHP and MySQL;
- you want the site to survive a traffic spike without a caching plugin ritual;
- you want to keep WordPress as the editing surface while the delivery layer moves.

It is not worth it if you need on-page SEO plugins, a page builder, a shop, or a membership system inside WordPress. Those need the runtime you are trying to remove.

## Next

- **[Install it](/plugin/wordpress-plugin.html)** — deploy the CMS from the plugin, or connect to a Worker you already run
- **[How the sync behaves](/plugin/wordpress-plugin-sync.html)** — conflicts, drafts, pull modes, comments, multi-site
- **[Obsidian publisher](/plugin/obsidian-plugin.html)** — if you would rather write in Markdown than in either admin
