---
title: Users
order: 9
---

# Users and roles

Accounts live in D1, not in WordPress. If you connect the plugin, the two systems keep separate logins: the plugin talks to the Worker with an auth key, not with a WordPress user.

**Users** is the only screen where you can create, disable or delete an account, and only an `admin` can open it.

## The four roles

| role | can |
|---|---|
| `admin` | everything, including users, settings, backups |
| `editor` | content: posts, taxonomies, media, comments |
| `author` | same as editor, in this version |
| `subscriber` | only their own profile and password |

Being honest about `editor` vs `author`: there is no ownership check on posts yet, so the two roles have identical reach. They exist as separate values because a future version will scope authors to their own posts; until then, do not treat the distinction as a security boundary.

`subscriber` is genuinely limited — the session is checked on every request, and every path except the profile endpoints is refused.

## Passwords

Passwords are stored as PBKDF2-SHA256 with a random per-user salt. The plaintext never leaves the request. Minimum length is 8 characters, enforced on both create and change.

Changing a password affects **web login only**. It does not sign out anyone else's device and it does not invalidate app passwords — that separation is deliberate, see below.

## App passwords

The **App password** button on a user row generates a credential for a third-party client: an editor like Obsidian or iA Writer, or a script that pushes Markdown over the API. These are separate from the login password:

- they have their own name (one per client, so you can see what is what),
- they are stored hashed — the value is shown once, at creation,
- they are revoked individually, and revoking one does not touch the login password,
- the list shows when each was last used, which is how you find the client you forgot about.

If a laptop is lost, revoke that app password. If you changed the login password because it leaked, the app passwords keep working — revoke those separately.

## Disabling and deleting

**Disable** sets `user_status = 0`. The account cannot log in, but it keeps its posts and its name in the byline. This is the reversible option and the right one for a departed colleague.

**Delete** removes the row. There is no reference check: posts authored by that user stay, their author field points at an id that no longer exists, and the byline renders empty. Two guardrails exist — you cannot disable or delete the account you are currently signed in as.

## Locked out

There is no "forgot password" email, because there is no mail server. If you still have a second admin account, use it to reset the first. If you do not, reset the hash directly:

```bash
# generate a hash for the new password
node -e "const c=require('crypto');const s=c.randomBytes(16);
const h=c.pbkdf2Sync('NEW-PASSWORD',s,100000,32,'sha256');
console.log('pbkdf2_sha256\$100000\$'+s.toString('hex')+'\$'+h.toString('hex'))"

# write it into D1
npx wrangler d1 execute cfpress --remote --config wrangler.prod.toml \
  --command "UPDATE users SET user_pass='pbkdf2_sha256\$100000\$…\$…' WHERE user_login='admin'"
```

Quote the dollar signs or the shell will eat them. Sign in afterwards and change the password from the UI so the hash is regenerated with a fresh salt.

Next: [Troubleshooting](/docs/troubleshooting.html).
