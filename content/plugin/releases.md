---
title: Releases
order: 3
---

# Releases

Everything here is a stable URL. `latest` moves with each release; the versioned file stays put.

| Product | Format | Latest | Pinned |
|---|---|---|---|
| CF Press Sync (WordPress) | `.zip` | [`/releases/plugin/latest.zip`](/releases/plugin/latest.zip) | [`cf-press-sync-2.2.1.zip`](/releases/plugin/cf-press-sync-2.2.1.zip) |
| CF Press Publisher (Obsidian) | `.zip` | [`/releases/obsidian/latest.zip`](/releases/obsidian/latest.zip) | [`cf-press-publisher-1.3.0.zip`](/releases/obsidian/cf-press-publisher-1.3.0.zip) |
| CF Press CMS (Worker) | `.tar.gz` | [`/releases/cms/latest.tar.gz`](/releases/cms/latest.tar.gz) | [`cfpress-cms-2.3.0.tar.gz`](/releases/cms/cfpress-cms-2.3.0.tar.gz) |

The CMS is a `.tar.gz` because the plugin unpacks it with `PharData`, which ships with PHP. `ZipArchive` often is not installed, so a zip would fail on ordinary hosting.

## Versions

**CF Press Sync**

| Version | Date | Notes |
|---|---|---|
| **2.2.1** | 2026-11-02 | Download sources point at `/releases/`; the default GitHub repo was a placeholder |
| 2.2.0 | 2026-11-02 | Renamed from CFSE Sync; attachments sync; REST-created posts keep terms and featured image |

**CF Press Publisher**

| Version | Date | Notes |
|---|---|---|
| **1.3.0** | 2026-11-03 | Renamed from MD Publisher; id `cfpress-publisher` |

**CF Press CMS**

| Version | Date | Notes |
|---|---|---|
| **2.3.0** | 2026-11-02 | Document search, doc-library switch, scheduled orphan cleanup |

## Two channels

The same files are on GitHub: [cf-press-sync](https://github.com/yuebinliu/cf-press-sync/releases) · [cfpress](https://github.com/yuebinliu/cfpress/releases). Use GitHub when you want release notes or to pin a tag; use this site when GitHub is slow.

## Old paths

`/downloads/cf-cms-latest.tar.gz` and `/releases/latest.zip` still answer with a 301, so older copies keep updating.

## No auto-update

`latest` is not an update channel. WordPress does not poll it, and neither plugin phones home. Updating is manual — download, upload over the existing plugin. Deleting is not an update: `uninstall.php` clears the CMS URL, the auth key and the targets.
