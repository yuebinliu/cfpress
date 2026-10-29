# Sync rules

Two systems holding the same content need a rule for every conflict. These are the ones worth knowing before you rely on the plugin.

## Post state lives in D1

A post's publish state is stored in the CMS database, not in WordPress. That has one consequence people trip over:

> Set a post to draft inside the CMS, then run an export from WordPress — the post becomes published again, because the export writes WordPress's status over it.

So pick one side to own publishing. If you edit mostly in WordPress, leave drafts in WordPress. If you draft in the CMS, do not run a full export afterwards without checking the list.

## Posts written in the CMS are identifiable

Posts created inside CF Press are written with a high ID range. That marker is how the plugin tells "posts it has never seen" apart from "its own posts coming back".

It also means two systems can write into the same table without needing a shared sequence, and no post is silently renumbered.

## What Pull does

**Pull from Worker** imports CMS-created posts into WordPress, so you can keep editing them in the WordPress admin — or hand the site to someone who only works in WordPress.

Two modes:

| Mode | Behaviour |
|---|---|
| additive (default) | inserts posts that do not exist yet; leaves existing ones alone |
| overwrite | updates posts with a matching ID from the Cloudflare copy |

Overwrite is a one-way door for that post: whatever is in the CMS wins. Turn it on for a deliberate migration, not as a habit.

## Comments go both ways

Comments are the usual reason people keep a dynamic site. Here they are not a reason to.

- a comment left on the CF Press site is written into WordPress, keeping its approval state and origin;
- comments moderated in WordPress can be pushed back into D1.

So moderation can happen wherever you happen to be, and readers see one thread either way. An unapproved comment never renders on the static site, no matter which side it arrived from.

## Media

Media is uploaded to R2 and referenced by URL. Re-exporting does not duplicate it: the same file resolves to the same object.

Replacing an image keeps its URL, so posts that reference it pick up the new file without edits. That is also why deleting media is destructive — the page will show a missing image until the reference is fixed.

## A workable discipline

If both sides are live, keep it boring:

1. one side owns writing, the other mirrors it;
2. run a full export after bulk changes, not after single edits;
3. check the draft list before any export that follows CMS-side drafting;
4. take a backup before an overwrite pull.

Next: [Write API reference](/docs/reference/api.html).
