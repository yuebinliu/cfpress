---
title: Releases
order: 4
---

# Releases

Every build is a plain WordPress plugin zip. Nothing is wrapped, nothing is signed — unzip it under `wp-content/plugins/` or upload it in **Plugins → Add New**.

## Two stable addresses

| What | URL | Use it when |
|---|---|---|
| **Latest** | `/releases/latest.zip` | You just want the newest build. This URL never changes, so a script or a bookmark keeps working |
| **This version** | `/releases/cf-press-sync-2.2.0.zip` | You need to reinstall the exact build a site is already running |

Both serve the same file today; the versioned one stays put after the next release, the `latest` one moves.

## Versions

| Version | Date | Download | Notes |
|---|---|---|---|
| **2.2.0** | 2026-11-02 | [latest](/releases/latest.zip) · [2.2.0](/releases/cf-press-sync-2.2.0.zip) | Renamed from CFSE Sync; attachments (zip/PDF/audio) now sync; REST-created posts keep categories, tags and featured image |
| 2.1.1 | 2026-11-02 | — | Attachment sync fix, 20 MB cap, upload timeout |
| 2.1.0 | 2026-11-01 | — | Version numbering fixed, log renamed |

Builds before 2.2.0 were never published — they were local development copies under the old name. If a site still runs one, updating to 2.2.0 is the only supported path: the settings are kept, nothing is migrated.

## GitHub

The source and the same zips live at [github.com/yuebinliu/cf-press-sync](https://github.com/yuebinliu/cf-press-sync/releases). Use GitHub if you want the release notes, the commit history, or to pin to a tag.

## What "latest" does not mean

It is not an auto-update channel. WordPress will not check it, and this plugin does not phone home. Updating is a manual step: download, upload the zip over the existing plugin, done.

Deleting the plugin is *not* an update — `uninstall.php` removes the CMS URL, the auth key and the target list. Upload over it instead.
