---
title: Releases
order: 4
---

# Releases

Two different things ship from here, and they are packed differently on purpose.

| What | Format | Why |
|---|---|---|
| **CF Press Sync** — the WordPress plugin | `.zip` | WordPress only accepts plugin zips |
| **CF Press CMS** — the Worker | `.tar.gz` | The plugin unpacks it with `PharData`, which ships with PHP. `ZipArchive` often does not, so a zip would fail on ordinary hosting |

## Stable addresses

These never change. Point a script or a bookmark at them and forget it.

| Product | Latest | Pinned (example) |
|---|---|---|
| Plugin | [`/releases/plugin/latest.zip`](/releases/plugin/latest.zip) | [`/releases/plugin/cf-press-sync-2.2.1.zip`](/releases/plugin/cf-press-sync-2.2.1.zip) |
| CMS | [`/releases/cms/latest.tar.gz`](/releases/cms/latest.tar.gz) | [`/releases/cms/cfpress-cms-2.3.0.tar.gz`](/releases/cms/cfpress-cms-2.3.0.tar.gz) |

`latest` moves with each release; the versioned file stays put. If a site already runs 2.2.1 and you need to reinstall exactly that build, use the versioned file — not `latest`, which may have moved on.

## Versions

**Plugin — CF Press Sync**

| Version | Date | Download | Notes |
|---|---|---|---|
| **2.2.1** | 2026-11-02 | [zip](/releases/plugin/cf-press-sync-2.2.1.zip) | Download sources point at `/releases/`; the default GitHub repo was a placeholder that did not exist |
| 2.2.0 | 2026-11-02 | — | Renamed from CFSE Sync; attachments (zip, PDF, audio) sync; REST-created posts keep categories, tags and featured image |

**CMS — the Worker**

| Version | Date | Download | Notes |
|---|---|---|---|
| **2.3.0** | 2026-11-02 | [tar.gz](/releases/cms/cfpress-cms-2.3.0.tar.gz) | Document search, feature switch for the doc library, scheduled orphan cleanup |

## Two channels

Both carry the same files:

- **Here** — `/releases/…` on this site. Fast from mainland China, no GitHub API rate limits.
- **GitHub** — [cf-press-sync releases](https://github.com/yuebinliu/cf-press-sync/releases) for the plugin, [cfpress releases](https://github.com/yuebinliu/cfpress/releases) for the CMS. Use it when you want release notes, the diff, or to pin to a tag.

The plugin's **⬇️ 下载发行包** button tries GitHub first, then this site, then the `workers.dev` address — so a blocked GitHub API costs a few seconds, not a failure.

## Old paths still work

`/downloads/cf-cms-latest.tar.gz` and `/releases/latest.zip` were used by earlier builds. Both answer with a 301 to the addresses above, so an old copy of the plugin keeps updating.

## What "latest" does not mean

It is not an auto-update channel. WordPress does not check it, and this plugin does not phone home. Updating the plugin is manual: download, upload the zip over the existing plugin.

Deleting it is *not* an update — `uninstall.php` removes the CMS URL, the auth key and the target list.
