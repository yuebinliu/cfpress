# Plugins

CF Press runs on its own. You write in the web admin, publish, done — no plugin needed.

Two plugins exist for two habits people already have. Pick the one that matches where you write; you do not need both.

| Plugin | Use it if | What it talks to |
|---|---|---|
| **[WordPress plugin](/plugin/overview.html)** | You already run a WordPress site and want to keep its editor | The Worker's `/d1-batch`, `/upload` and `/delete` endpoints |
| ↳ [Installing it](/plugin/wordpress-plugin.html) | You are ready to connect the two | — |
| ↳ [How the sync behaves](/plugin/wordpress-plugin-sync.html) | You are about to rely on it for real content | — |
| **[Obsidian publisher](/plugin/obsidian-plugin.html)** | You write Markdown in Obsidian and want it online in one click | The WordPress-compatible write API, `/wp-json/wp/v2/posts` |

## How they differ

The WordPress plugin is a **mirror**: posts live in WordPress and are copied into the CMS, and edits can flow back. Two systems, one content set.

The Obsidian plugin is a **client**: nothing is copied. The note goes straight into the CMS database as the only copy, and the plugin writes the returned `wp_id` back into the note's frontmatter so the next publish updates instead of duplicating.

So: if WordPress is your system of record, use the first. If you want your notes to *be* the site, use the second.

## Neither is required

Both are thin add-ons over the same write API. If a plugin disappears tomorrow, the content is still in D1 and R2 and the site keeps serving — you would just go back to writing in the admin.
