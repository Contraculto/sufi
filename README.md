# SUFI — Single User Fediverse Instance

A minimal, no-framework PHP ActivityPub server for one user. Drop it on any shared PHP host alongside an existing website. No Composer, no build step, no database server.

---

## How the Fediverse works

The fediverse is a network of independent servers that talk to each other using the **ActivityPub** protocol. When you post on Mastodon, your server sends your post as a JSON document to every server that has a follower of yours. Those servers store it and show it to their users. No central platform is involved.

To join the fediverse you need three things:

1. **An identity** — a URL that identifies you as an actor: `https://example.com/ap/actor`
2. **WebFinger** — a discovery endpoint at `/.well-known/webfinger` that lets other servers find your actor from a handle like `@alice@example.com`
3. **An inbox** — a URL that accepts `POST` requests carrying signed JSON activities (Follow, Like, Create, Announce…)

Everything else — your profile, your posts, your follower list — is just JSON served from URLs.

### How HTTP Signatures work

Every message the fediverse sends is signed with the sender's RSA private key. The signature covers the request path, host, date, and a SHA-256 digest of the body. The receiving server verifies the signature against the sender's public key (fetched from their actor profile). This prevents spoofing.

SUFI signs every outgoing request and verifies every incoming one.

---

## How SUFI works

```
Incoming follow from mastodon.social:
  POST /ap/inbox  ← signed JSON: {"type":"Follow","actor":"https://mastodon.social/users/bob"}
  → verify signature
  → save bob to Follower table
  → POST Accept back to bob's inbox (signed)

You post via API:
  POST /ap/post  password=xxx&content=Hello
  → create Note + Create activity
  → save to Post table
  → POST activity to every follower's inbox (signed)

Remote server looks you up:
  GET /.well-known/webfinger?resource=acct:alice@example.com
  → {"subject":"acct:alice@example.com","links":[{"rel":"self","href":"https://example.com/ap/actor"}]}
  GET /ap/actor
  → full actor JSON with public key
```

### Delivery queue

Outgoing activities (posts, Accepts, Blocks, Undos) are written to a `Delivery`
table and sent *after* the response is flushed, so a slow follower never delays
your request. Failures are retried with backoff (1m, 5m, 15m, 1h, then every 6h)
by `scripts/ops/deliver.php`, which should run every minute from cron — the
deploy script installs that entry. After 10 failed attempts the activity is
dropped and written to `sys/logs/security.log`.

### File structure

```
index.php          router + all ActivityPub logic (one file)
sys/
  config.php       your instance config — edit this
  database.php     SQLite CRUD helpers + delivery/block tables
  delivery.php     signed requests, fetch guards, delivery queue
  sufi.sql         schema (auto-applied on first connection)
  sufi.sqlite      the database (created automatically)
  private.pem      RSA private key  ← keep secret, never commit
  public.pem       RSA public key
  logs/            security.log (rotated at 1MB)
  cache/           short-lived cache of remote actor documents
scripts/
  ops/deliver.php  delivery worker, run from cron every minute
  ops/backup.sh    daily SQLite backup (keeps the last 14)
  ops/restore.sh   restore a backup (verified, --yes required)
  ops/prune.php    drops inbox items older than $inbox_retention_days
templates/
  actor.php        builds Actor JSON
  webfinger.php    builds WebFinger JSON
  outbox.php       builds Outbox JSON (your posts)
  followers.php    builds Followers collection
  following.php    builds Following collection
  likes.php        builds Likes collection
  home.php         HTML profile page
  panel.php        Admin dashboard (post, edit, follow/unfollow)
.htaccess          Apache routing
deploy/
  apache-coexist.htaccess   rules to add to an existing site's .htaccess
  apache-vhost.conf         full VirtualHost example
  nginx.conf                Nginx server block example
```

### Database tables

| Table | What it stores |
|-------|----------------|
| `Actor` | Your profile: name, bio, domain, public key (created automatically) |
| `Follower` | Everyone following you (actor URI + their inbox URL) |
| `Following` | Everyone you follow |
| `Post` | Your notes/posts |
| `Like` | Objects you have liked |
| `Block` | Actors you blocked (enforced in the inbox) |
| `Delivery` | Queued outgoing activities and retry state |

---

## Setup

### 1. Prerequisites

- PHP 8.1+ with `pdo_sqlite` and `openssl` extensions
- Apache with `mod_rewrite` enabled, or Nginx with PHP-FPM
- HTTPS (required by ActivityPub — other servers will reject unsigned HTTP requests)

Check extensions:
```bash
php -m | grep -E 'pdo_sqlite|openssl'
```

Install on Debian/Ubuntu if missing:
```bash
sudo apt install php8.4-sqlite3 php8.4-openssl
```

### 2. Place the files

**Option A — alongside an existing site (recommended):**

Copy the `sufi/` directory inside your document root:
```
/var/www/html/          ← existing site
/var/www/html/sufi/     ← SUFI files go here
```

**Option B — at the domain root (SUFI is the whole site):**

Copy files directly into the document root:
```
/var/www/html/index.php
/var/www/html/sys/
/var/www/html/templates/
```

### 3. Generate RSA keys

```bash
cd /path/to/sufi/sys

openssl genrsa -out private.pem 2048
openssl rsa -in private.pem -pubout -out public.pem
chmod 600 private.pem
```

The private key must stay on your server. Never commit it to version control.

### 4. Configure `sys/config.php`

```php
$server    = 'example.com';      // your domain, no https://
$username  = 'alice';            // your fediverse handle
$password  = 'change-this-to-a-long-random-string';

// Leave this empty for the normal setup, where /ap/* and /.well-known/* are
// routed to SUFI at the domain root. Only set a prefix if *all* SUFI URLs live
// under one (e.g. '/instance' for https://example.com/instance/ap/actor).
$base_path = '';
```

`$base_path` is not about where the files sit on disk. In the recommended
setup the files live in `/var/www/html/sufi/` but the activity URLs are still
`https://example.com/ap/actor`, so `$base_path` stays empty.

Two more settings have safe defaults and rarely need touching:

```php
$allow_private_fetch = false;  // keep false: only https + public IPs are fetched
$outbox_page_size    = 20;     // posts per /ap/outbox page
$max_post_chars      = 500;
$max_upload_bytes    = 5242880;
$inbox_retention_days = 90;
$session_lifetime     = 43200;  // panel idle logout, seconds
$login_max_attempts   = 5;      // per 15 minutes, per IP
```

The deploy script installs three cron jobs on the server:

```
* * * * *   delivery worker (retries with backoff)
17 4 * * *  backup into sys/backups/, keeping the last 14
27 4 * * *  prune inbox items older than the retention window
```

### 5. Initialize the database

Nothing to do. The schema is applied and your Actor row is created on the first
request, from `sys/config.php` and `sys/private.pem`.

Display name, bio and avatar come from `$actor_name`, `$actor_summary` and
`$actor_icon` in the config — but only on that first creation. After that the
database owns those three fields:

```bash
sqlite3 sys/sufi.sqlite "UPDATE Actor SET summary='New bio' WHERE id=1;"
```

Identity (`$username`, `$server`) and the public key are always kept in sync
with the config, so changing your handle or rotating your key needs no SQL.

Test it without a browser:
```bash
curl -s http://127.0.0.1:8080/ap/actor | python3 -m json.tool
```

### 6. Configure your web server

#### Apache — shared hosting (`.htaccess`)

**Option A — SUFI alongside existing site:**

Add the following to the top of your existing site's `.htaccess` (the one in the document root), before any existing routing rules:

```apache
RewriteEngine On

# SUFI: block sys/
RewriteRule ^sufi/sys/ - [F,L]

# SUFI: route ActivityPub paths
RewriteRule ^\.well-known/(webfinger|host-meta)$ /sufi/index.php [QSA,L]
RewriteRule ^ap(/.*)?$ /sufi/index.php [QSA,L]
RewriteRule ^@([^/]+)/?$ /sufi/index.php [QSA,L]

# ... your existing rules follow here ...
```

**Option B — SUFI at domain root:**

SUFI's own `.htaccess` is already configured. Switch it to standalone mode by editing `.htaccess` and uncommenting the three standalone lines.

#### Apache — VirtualHost (VPS)

See `deploy/apache-vhost.conf` for a full example.

#### Nginx

See `deploy/nginx.conf` for a complete server block.

The key directive: route specific paths to SUFI's `index.php` while keeping the rest for your existing site.

```nginx
location ~ ^/(\.well-known/(webfinger|host-meta)|ap(/|$)|@[^/]+/?$) {
    fastcgi_pass   unix:/run/php/php8.4-fpm.sock;
    fastcgi_param  SCRIPT_FILENAME /var/www/html/sufi/index.php;
    fastcgi_param  REQUEST_URI     $request_uri;
    include        fastcgi_params;
}
```

### 7. Verify it works

```bash
# WebFinger lookup
curl -s "https://example.com/.well-known/webfinger?resource=acct:alice@example.com" | python3 -m json.tool

# Actor profile
curl -s -H "Accept: application/activity+json" https://example.com/ap/actor | python3 -m json.tool
```

Then search for `@alice@example.com` in Mastodon or any other fediverse client. You should be able to follow yourself.

### 8. Updating an existing install

`scripts/deploy.sh` runs the test suite, uploads the application files and
checks the federation endpoints afterwards. It never touches `sys/` runtime
files on the server.

```bash
./scripts/deploy.sh --dry-run   # preview, including files it would delete
./scripts/deploy.sh             # tests + upload + smoke check
./scripts/deploy.sh --quick     # skip tests (hotfix)
```

It defaults to `contraculto.com:/var/www/contraculto/sufi`; override with
`CONTRACULTO_DEPLOY_HOST` and `SUFI_REMOTE_DIR`. On the server the panel lives
at `/sufi/`, while the activity URLs stay at the domain root.

---

## API

SUFI has a simple HTTP API for creating content and managing social connections. You can use it from a script, curl, or any HTTP client.

### Authentication

Two methods are supported:

1. **Session cookie** — log in through the panel at `/sufi/` and use the returned cookie for all requests. This is what the web panel uses.
2. **Password in request body** — include `password=your-secret` in POST form data or JSON for write endpoints.

Read-only endpoints (`GET /ap/post`, `GET /ap/inbox`) require a session cookie. Write endpoints accept either method.

### Create a post

```
POST /ap/post
Content-Type: application/x-www-form-urlencoded

password=your-secret&content=Hello+fediverse!
```

Optional reply: add `in_reply_to=https://mastodon.social/@user/123456`

Mentions (`@user@server`) and hashtags (`#tag`) are recognised automatically:
they are linkified in the Note's HTML and added to its `tag` array (mentions are
resolved through webfinger so they get a real `href`; unresolvable ones stay as
plain text).

Returns the ActivityPub `Create` activity JSON.

```bash
curl -X POST https://example.com/ap/post \
  -d "password=your-secret" \
  -d "content=Hello fediverse!"
```

Or with JSON body:
```bash
curl -X POST https://example.com/ap/post \
  -H "Content-Type: application/json" \
  -d '{"password":"your-secret","content":"Hello fediverse!"}'
```

### Follow / Unfollow / Block / Unblock

```
POST /ap/action
Content-Type: application/x-www-form-urlencoded

password=your-secret&user=@bob@mastodon.social&action=Follow
```

`action` values: `Follow` `Unfollow` `Block` `Unblock`

Blocking is real: the actor is stored in the `Block` table, removed from your
followers/following, and anything they send afterwards is discarded before
signature verification (no fetch, no write). `user` may be a handle or an actor
URI — the panel uses URIs so it never has to re-run webfinger.

```bash
curl -X POST https://example.com/ap/action \
  -d "password=your-secret" \
  -d "user=@bob@mastodon.social" \
  -d "action=Follow"
```

### List your posts

```
GET /ap/post
Cookie: PHPSESSID=...
```

Returns a JSON array of all posts from the database. Use a cookie jar to preserve the session:

```bash
curl -c sufi.cookies -b sufi.cookies https://example.com/ap/post
```

(First log in to obtain the cookie, see example below.)

### View inbox items

```
GET /ap/inbox
Cookie: PHPSESSID=...
```

Returns the 50 most recent items received by the inbox.

### Profile

```
POST /ap/profile
Cookie: PHPSESSID=...    (panel session, or password in the body)
name=Rodrigo&summary=mostly+humano&icon=https://example.com/me.jpg
```

Name, bio and avatar are stored in the `Actor` row. Config only seeds them on
first creation, so the panel (or this endpoint) is the way to change them later.

### Uploading images

```
POST /ap/upload
Cookie: PHPSESSID=...
Content-Type: multipart/form-data

file=@photo.jpg
```

Returns `{"url": "https://example.com/ap/media/<hash>.jpg", "mediaType": ..., "name": ...}`.
Attach up to four images by sending an `attachments` field on `POST /ap/post`
with a JSON list; `description` becomes the alt text:

```bash
curl -X POST https://example.com/ap/post \
  -d "password=your-secret" \
  -d "content=two pictures" \
  --data-urlencode 'attachments=[{"url":"https://example.com/ap/media/<hash>.jpg","description":"a pixel"}]'
```

The older single `attachment` + `attachment_description` fields still work.
JPEG/PNG/GIF/WebP only, limited by `$max_upload_bytes`. Uploaded files are
deleted when the post that used them is deleted; files that never made it into
a post are removed by the daily prune once they are a day old.

### Inbox

```
GET /ap/inbox?limit=50&before_id=<id>
Cookie: PHPSESSID=...
```

Returns a page of inbox items plus the cursor and unread count:

```json
{
  "items": [{
    "id": 42,
    "type": "Create",
    "kind": "reply",
    "actor_uri": "https://mastodon.social/users/alice",
    "actor_handle": "@alice@mastodon.social",
    "object_uri": "https://mastodon.social/@alice/123",
    "reply_to": "https://example.com/ap/post/<guid>",
    "preview": "nice post",
    "is_read": 0,
    "data": { "...": "raw activity" }
  }],
  "next_before_id": 41,
  "unread": 3
}
```

`kind` is one of `like`, `boost`, `reply`, `mention`, `post`, `other`.
`limit` defaults to 50 (max 100), `next_before_id` is null on the last page.
`?unread=1` filters to unread items, `?mark_read=1` marks the returned items
read (the response still shows them as unread, so the panel can render a badge).

Mark single items read:

```
POST /ap/inbox/read
id=42            # or id=all
```

Delete items (they are local copies; this does not touch the remote activity):

```
POST /ap/inbox/delete
id=42            # or id=read to drop everything already read
```

Older rows may have an empty `actor_handle`. Fill them in once after upgrading:

```bash
php scripts/ops/backfill.php        # 200 actors per run, safe to re-run
```

### Followers and following

```
GET /ap/people
Cookie: PHPSESSID=...
```

Panel-oriented version of the two collections: readable handles resolved from
the stored actor documents instead of raw actor URIs. The matching `uri` is
what `POST /ap/action` accepts for `Unfollow`/`Block`, so no webfinger lookup is
needed.

```json
{
  "followers": [
    {
      "uri": "https://mastodon.social/ap/users/116530059523052419",
      "handle": "@ahmedhijazi@mastodon.social",
      "name": "Ahmed hijazi",
      "url": "https://mastodon.social/@ahmedhijazi"
    }
  ],
  "following": []
}
```

### Scripting example (cookie jar)

```bash
# Log in and save the session cookie
curl -c sufi.cookies -b sufi.cookies \
  -d "password=your-secret" \
  https://example.com/sufi/

# Create a post using the session
curl -c sufi.cookies -b sufi.cookies \
  -d "content=Hello from a script" \
  https://example.com/ap/post
```

---

## ActivityPub endpoints (for remote servers)

These are called automatically by other fediverse servers. You don't call them directly.

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/.well-known/webfinger` | Discovery: maps `@alice@example.com` to actor URL |
| `GET` | `/.well-known/host-meta` | Discovery: XML pointer to WebFinger |
| `GET` | `/ap/actor` | Your ActivityPub profile + public key |
| `POST` | `/ap/inbox` | Receives Follow, Undo, and other activities |
| `GET` | `/ap/outbox` | Paginated `OrderedCollection`; pages via `?page=true&max_id=` |
| `GET` | `/ap/followers` | List of actor URIs following you |
| `GET` | `/ap/following` | List of actor URIs you follow |
| `GET` | `/ap/likes` | Objects you have liked |
| `GET` | `/ap/post/{guid}` | A single Note object |
| `GET` | `/ap/media/{file}` | Uploaded images referenced by posts |
| `GET` | `/ap/nodeinfo` | NodeInfo 2.1 document (software, post count) |
| `GET` | `/.well-known/nodeinfo` | Discovery link for the NodeInfo document |
| `GET` | `/tags/{tag}` | Simple HTML page listing your posts that use a hashtag |
| `GET` | `/ap/follow?uri={uri}` | Remote follow redirect for Mastodon's subscribe flow |
| `GET` | `/@alice` | HTML profile page |

---

## Troubleshooting

**WebFinger returns 404**

The rewrite rules aren't reaching SUFI. Check that `mod_rewrite` is enabled and that the rules in `.htaccess` or the VirtualHost are in effect. On Apache, `AllowOverride All` must be set for the document root directory.

**"Actor not found" in Mastodon when searching `@alice@example.com`**

1. Confirm WebFinger returns valid JSON:
   ```bash
   curl "https://example.com/.well-known/webfinger?resource=acct:alice@example.com"
   ```
2. Confirm the `self` link in WebFinger matches the actor's `id`:
   - WebFinger `links[rel=self].href` must equal Actor `id`
   - Both should be `https://example.com/ap/actor`

**Follow requests arrive but SUFI doesn't Accept**

Check `sys/logs/` for signature verification failures. Common causes:
- Clock skew > 1 hour between servers (fix with NTP)
- Wrong private key (public key in DB doesn't match private key in `sys/private.pem`)
- SUFI can't reach the remote server (outbound HTTP blocked)

**Verify key pair matches:**
```bash
# These two fingerprints must match
openssl rsa -in sys/private.pem -pubout | openssl pkey -pubin -outform DER | openssl dgst -sha256
sqlite3 sys/sufi.sqlite "SELECT public_key FROM Actor LIMIT 1" | openssl pkey -pubin -outform DER | openssl dgst -sha256
```

**Posts aren't appearing on Mastodon**

1. Check you have followers: `sqlite3 sys/sufi.sqlite "SELECT actor_uri FROM Follower;"`
2. Outbound HTTP must work from your server — some shared hosts block it. Test:
   ```bash
   php -r "echo file_get_contents('https://mastodon.social/.well-known/nodeinfo');"
   ```

**Reset the database**

```bash
rm sys/sufi.sqlite
# Re-insert Actor row (step 5 above)
```

---

## Security notes

- `sys/` is blocked from direct web access via `.htaccess` and the Nginx/Apache configs
- The private key (`sys/private.pem`) and database (`sys/sufi.sqlite`) are inside `sys/` and therefore protected
- Never commit `sys/private.pem` or `sys/sufi.sqlite` to version control — add them to `.gitignore`
- The `password` in `config.php` is compared with `===` (plain string). Use a long random value. There is no rate limiting, so consider IP-restricting `/ap/post` and `/ap/action` at the web server level if needed
- Browser-based requests use a session cookie protected by a CSRF token; script/API clients authenticate with the password in the request body
- Every activity posted to the inbox is signature-checked before it is stored or acted on: unsigned, tampered, or wrong-key deliveries get HTTP 401
- Incoming `Create` and `Announce` activities are stored as inbox items, `Like`s also land in the likes collection, `Follow`/`Undo` update followers. Everything else is accepted and ignored — SUFI is a publishing server, not a reader
- The panel logs in by posting back to `/sufi/` itself, so it works without extra rewrite rules for `/sufi/login` or `/sufi/logout`
- Remote fetches are limited to HTTPS and public IPs, with a 4s timeout, no redirects, and a short-lived cache — the inbox is public, so this matters
- Failed signature checks and delivery failures append to a single rotated `sys/logs/security.log`, never one file per request
- Inbound `Update` of an actor refreshes its stored profile; `Move` transfers
  the follower/following entries to the new account
- After 10 failed deliveries the activity is dropped; if the inbox was
  unreachable or returned 410/5xx, the follower is removed as well (4xx on one
  activity is not treated as a dead server)
- Blocked actors are dropped before signature verification
- Panel login requires a CSRF token and is rate-limited per IP; sessions expire
  after `$session_lifetime` of inactivity; the panel sends CSP / nosniff /
  frame-deny headers
- Uploads are validated with `getimagesize()`, stored under `sys/uploads/` and
  served through `/ap/media/` — the directory itself is not web-accessible
- Posts are stored as plain text and rendered to safe HTML (escaped, URL
  linkified, mentions/hashtags tagged, paragraphs preserved) when they go out
- The panel CSP allows only same-origin images and no framing; uploads are
  removed with their post and orphans are pruned after a day
- `$password` accepts a plain string or a bcrypt/argon hash; a hash is
  recommended (it survives a config leak)

---

## Updating your public key

Replace `sys/private.pem` with the new key and leave `sys/public.pem` next to it.
The Actor row picks the new public key up on the next request; no SQL needed.
Followers get it the next time their server fetches your actor profile.

---

## Backups and restore

`scripts/ops/backup.sh` uses SQLite's `VACUUM INTO`, so it is safe to run while
the instance is live. Backups land in `sys/backups/` and the newest 14 are kept.

```bash
./scripts/ops/backup.sh                      # manual backup
SUFI_BACKUP_KEEP=30 ./scripts/ops/backup.sh  # keep more
```

Every backup is verified with `PRAGMA integrity_check` before it is kept.

Restore (refuses to run without `--yes`, verifies the backup first, keeps the
current database as `sys/sufi.sqlite.pre-restore-<stamp>`):

```bash
sudo systemctl stop php8.4-fpm
./scripts/ops/restore.sh sys/backups/sufi-<stamp>.sqlite --yes
sudo systemctl start php8.4-fpm
```

---

## Testing

The whole suite runs locally with nothing but PHP and OpenSSL:

```bash
./scripts/test.sh
```

It builds two throwaway instances in a temp directory (one at the domain root,
one under `/sufi`), starts a fake remote server, exercises discovery, posting,
panel login/CSRF, inbox signature checks and federation delivery, then deletes
everything. Your real `sys/` directory is never touched.

To watch the sandbox instead of deleting it: `SUFI_TEST_KEEP=1 ./scripts/test.sh`.
It prints the directory path; start the instances with `php -S` if you want to
poke at them by hand.

---

## License

MIT
