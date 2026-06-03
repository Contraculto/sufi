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

### File structure

```
index.php          router + all ActivityPub logic (one file)
sys/
  config.php       your instance config — edit this
  database.php     SQLite CRUD helpers
  sufi.sql         schema (auto-applied on first connection)
  sufi.sqlite      the database (created automatically)
  private.pem      RSA private key  ← keep secret, never commit
  public.pem       RSA public key
  logs/            debug logs from incoming requests
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
| `Actor` | Your profile: name, bio, domain, public key |
| `Follower` | Everyone following you (actor URI + their inbox URL) |
| `Following` | Everyone you follow |
| `Post` | Your notes/posts |
| `Like` | Objects you have liked |

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

// Leave empty if SUFI is at the domain root.
// Set to '/sufi' if the files are in a /sufi/ subdirectory.
$base_path = '/sufi';
```

### 5. Initialize the database

The schema applies automatically on the first request, but you need to insert your Actor row once:

```bash
PUBLIC_KEY=$(cat sys/public.pem)

sqlite3 sys/sufi.sqlite "
INSERT INTO Actor (type, name, username, summary, domain, base_url, icon, public_key)
VALUES (
  'Person',
  'Alice',
  'alice',
  'My bio here',
  'example.com',
  'https://example.com/',
  'https://example.com/avatar.jpg',
  '$PUBLIC_KEY'
);"
```

To update your profile later:
```bash
sqlite3 sys/sufi.sqlite "UPDATE Actor SET summary='New bio' WHERE id=1;"
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

---

## API

SUFI has a simple password-protected HTTP API for creating content and managing social connections. Use it from a script, curl, or any HTTP client.

### Create a post

```
POST /ap/post
Content-Type: application/x-www-form-urlencoded

password=your-secret&content=Hello+fediverse!
```

Optional reply: add `in_reply_to=https://mastodon.social/@user/123456`

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

```bash
curl -X POST https://example.com/ap/action \
  -d "password=your-secret" \
  -d "user=@bob@mastodon.social" \
  -d "action=Follow"
```

### List your posts

```
GET /ap/post?password=your-secret
```

Returns a JSON array of all posts from the database.

### View log entries

```
GET /ap/inbox?password=your-secret
```

Returns filenames of the 50 most recent signature verification logs.

---

## ActivityPub endpoints (for remote servers)

These are called automatically by other fediverse servers. You don't call them directly.

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/.well-known/webfinger` | Discovery: maps `@alice@example.com` to actor URL |
| `GET` | `/.well-known/host-meta` | Discovery: XML pointer to WebFinger |
| `GET` | `/ap/actor` | Your ActivityPub profile + public key |
| `POST` | `/ap/inbox` | Receives Follow, Undo, and other activities |
| `GET` | `/ap/outbox` | Your posts as an ordered collection |
| `GET` | `/ap/followers` | List of actor URIs following you |
| `GET` | `/ap/following` | List of actor URIs you follow |
| `GET` | `/ap/likes` | Objects you have liked |
| `GET` | `/ap/post/{guid}` | A single Note object |
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
- Signature verification is performed for all Follow and Undo activities
- Incoming `Create`, `Announce`, `Like`, `Update`, and `Delete` activities are accepted with HTTP 202 but not stored — SUFI is a publishing server, not a reader

---

## Updating your public key

If you regenerate your RSA keys, update the public key in the database:

```bash
NEW_KEY=$(cat sys/public.pem)
sqlite3 sys/sufi.sqlite "UPDATE Actor SET public_key='$NEW_KEY' WHERE id=1;"
```

Followers will get your new key the next time their server fetches your actor profile.

---

## License

MIT
