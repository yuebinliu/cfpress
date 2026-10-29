# The WordPress plugin

CF Press does not need WordPress. The plugin exists for one situation: you already run a WordPress site and want it served from Cloudflare without giving up the editor you know.

It is a companion, not a dependency. If you are starting fresh, skip this section — the CMS alone is the whole product.

## What it does

The plugin connects a WordPress installation to a CF Press Worker. It can:

- push posts, categories, tags and media from WordPress into the CMS;
- render each post as a static page in R2, so pages load from the edge;
- pull posts written in the CMS back into WordPress;
- turn your existing WordPress theme into a CF Press style pack;
- carry comments in both directions.

## Both directions, not one

| Direction | What moves | Started from |
|---|---|---|
| WordPress → CF Press | posts, media, categories, tags | **Export** in the plugin |
| CF Press → WordPress | posts written in the CMS | **Pull from Worker** in the plugin |
| Comments | both ways | automatic on arrival, plus a manual sync |

Most export plugins are one-way: WordPress is the source of truth and the static site is a read-only copy. Here either side can be edited and the other catches up.

## What you need

- a running WordPress site you can install plugins on;
- a deployed CF Press Worker;
- the Worker's URL and its auth key.

Both are covered in [Installing the plugin](/plugin/install.html).

## When not to use it

If there is no WordPress site in the picture, there is nothing to connect. Adding the plugin to a WordPress install you are about to retire only doubles the work.

Next: [Installing the plugin](/plugin/install.html).
