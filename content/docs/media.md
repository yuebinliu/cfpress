---
title: Media
order: 8
---

# Media

Images live in R2 and are referenced by URL. There is no attachment record in the database — no "media post" like WordPress creates for every file. The file is the record.

## Uploading

Three places upload to the same library:

- **Media** — the library itself, with a picker and drag-free file input.
- **Settings → logo / banner** — uploads, then writes the URL into the field.
- **The editor** — inserting an image does the same thing.

Limits are enforced server-side:

| | |
|---|---|
| size | 10MB per file |
| types | jpg, jpeg, png, gif, webp |
| validation | the first bytes are checked, not the extension |

That last row means renaming a `.zip` to `.png` gets rejected. It also means a slightly corrupt image fails with "not a valid image" rather than uploading silently.

## One file, one key

The storage path is built from the file's content:

```
wp-content/uploads/<first 12 hex of SHA-256>/<name>.<ext>
```

Upload the same file twice — same name, same bytes — and the second upload finds the object already there and reuses it. No duplicate copy, no duplicate grid tile. This is why the library stays clean after years of re-uploading the same logo.

A caveat worth knowing: the file name is part of the key. Rename `logo.png` to `logo-2026.png` and upload it again, and you get a second object with the same bytes. The content hash dedupes identical uploads, not identical pictures.

## Replacing an image

Because the URL is stable, you can overwrite in place: upload a new file with the same key name and every page that references it shows the new picture, with no edit and no rebuild.

The catch is the edge cache. An image served from cache keeps serving the old bytes until its cache entry expires, so expect a short delay, or purge the cache after replacing something important.

## Deleting

Select files in the grid and delete them in one go. Two things to know before you do:

**There is no reference check.** Deleting an image does not look at posts to see who uses it, and it does not ask. A deleted image leaves broken `<img>` tags behind, in the static pages as well as in the editor — the pages will not be rewritten for you.

**It is immediate.** The object is gone from R2 and from the list. There is no trash and no undo. If an image matters, keep a copy outside; media is not included in the settings backup export either.

## Finding things

The filter box matches file names in the list that is currently loaded. The list is paginated 50 at a time, ordered by storage key — which means entries are grouped by content hash, not by upload date. If you cannot find a file by name, page through rather than assuming it is missing.

## Backups

Media is not part of the content export. That export covers posts, taxonomies, document metadata and site settings — everything that is small and structured. R2 objects are not included, by design: an export that contains gigabytes of images is not something you want to email yourself.

For a real backup, use `rclone` or the Cloudflare dashboard to copy `wp-content/uploads/` out of the bucket. Do it before a big cleanup, not after.

Next: [Users and roles](/docs/users.html).
