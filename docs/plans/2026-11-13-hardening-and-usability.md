# 2026-11-13 — hardening, federation correctness and daily usability

Session notes for the work done on SUFI after the initial public release. The
goal throughout: keep the single-user, single-file, dependency-free shape while
making the instance safe to expose, correct on the wire, and pleasant to use
from the panel.

## Why

The first deploy was a working publisher but had three classes of problems:

- **Safety**: the inbox fetched arbitrary URLs with a 10s timeout and redirects
  enabled; junk requests could saturate PHP-FPM (`pm.max_children = 3`).
- **Federation correctness**: deliveries were synchronous and lost on failure,
  blocks only sent an activity without being enforced, collections were
  unpaginated, and newer Mastodon servers sign with RFC 9421 — which we rejected.
- **Usability**: plain-text posts, no media, no read side, no profile editing,
  and a panel that could not show who was talking.

## What was done (in order)

### Federation and delivery
- Inbox verification requires signatures for **every** activity before any
  database write; digest must be signed; keyId host must match the actor host.
- Both signature schemes supported: legacy draft-cavage and RFC 9421
  (`Signature-Input` + `Signature: sig1=:…:`), including `content-digest`.
- Delivery queue (`Delivery` table) with backoff (1m/5m/15m/1h/6h), drained
  after the response is flushed; cron worker every minute. After 10 failures the
  activity is dropped, and followers sharing an unreachable/410/5xx inbox are
  removed (a single 4xx only drops the activity).
- Remote fetches: HTTPS + public IPs only (unless `$allow_private_fetch`), 4s
  timeout, no redirects, short-lived disk cache in `sys/cache`.
- Outbox and followers/following collections paginated
  (`OrderedCollection` → `OrderedCollectionPage` with `?page=true&max_id=`).
- Follow semantics: outbound follows are `pending` and resolved by signed
  `Accept`/`Reject`; `Undo` by activity id works; `Update` refreshes an actor;
  `Move` transfers follower/following rows; `Delete` of an actor cleans up.

### Moderation and inbox
- `Block` table enforced before signature verification (no fetch, no write);
  blocking removes follower/following rows; panel can block/unblock.
- Inbox items store the sender handle and read state, are classified
  (`like`/`boost`/`reply`/`mention`/`post`), paginated, and can be marked read,
  deleted individually or cleared. Threads show your post above the reply.
- `scripts/ops/backfill.php` filled handles on historical rows (346 actors:
  325 resolved, 21 deleted accounts fell back to `@id@host`).

### Content and panel
- Posts are escaped and rendered to HTML (paragraphs, links, mentions,
  hashtags) with a 500-char limit; hashtags get `/tags/<slug>` pages.
- Visibility (public/unlisted/private) and content warnings.
- Up to four image attachments with alt text; uploads validated by content,
  capped at 5 MB / 40 MP, downscaled to 2560 px, EXIF-oriented, with 320 px
  thumbnails; files are removed with their post and orphans pruned after a day.
- Profile editing in the panel (`POST /ap/profile`).
- Login: CSRF token, per-IP rate limit, idle session expiry, bcrypt/argon
  hashes supported (production stores a hash). Panel CSP and security headers.
- NodeInfo 2.1 (`/.well-known/nodeinfo` → `/ap/nodeinfo`).

### Operations
- Schema migrations via `PRAGMA user_version` (`SCHEMA_VERSION = 4`).
- `scripts/test.sh`: throwaway instances, fake remote, 306 checks (including
  signed federation, retries, blocks, pagination, uploads, restore).
- `scripts/deploy.sh`: shipped-file manifest, `--check` mode, remote lint,
  cron installation, endpoint + health smoke checks.
- `scripts/ops/`: `deliver.php`, `health.php`, `backup.sh` (integrity-checked),
  `restore.sh` (verified, `--yes`), `prune.php`, `backfill.php`.
- `security.log` single file with two rotated generations; health failures are
  recorded there.

## Decisions worth remembering

- **No framework/composer.** Everything is plain PHP; `sys/delivery.php` holds
  the transport layer shared by the web app and the cron worker.
- **SQLite with WAL**, no server. Backups are `VACUUM INTO` dumps.
- **Config is settings only.** Code helpers live in `index.php`; the deploy
  script never uploads `sys/config.php`. Parameters added later have `??`
  fallbacks so an older config keeps working.
- **Single actor.** `ensure_actor()` creates/synchronises the Actor row from
  config; the database owns display fields after creation.
- **Enqueue everything, deliver after the response.** No follower should slow a
  post, and retries are the only way transient failures are not data loss.

## Verification

- `./scripts/test.sh` — 306 checks, no external dependencies.
- Production: actor/outbox/webfinger/nodeinfo/panel 200; login and API auth
  verified after hashing the password; a 3000×100 PNG upload was served as
  2560×85; an 8000×8000 header-only PNG was rejected with 413.

## Incidents

Two deploys briefly broke production because `deploy.sh` shipped a hand-written
list of `sys/` files and `scripts/ops/` files: `sys/delivery.php` and later
`deliver.php` were missing. Both were fixed within minutes. The deploy script
now has a manifest, a `--check` mode used by the test suite, and remote lint of
every shipped file.

## Still missing

- Content: no editing of visibility/CW after posting (endpoint supports it, the
  panel edit form only edits text); no per-post language.
- Images: no thumbnails in AP payloads (Mastodon makes its own), no EXIF tests.
- Federation: authorized fetch not implemented; no per-instance delivery view;
  thread view does not follow chains beyond the immediate parent.
- Ops: health failures only land in the log/cron output, no push alerting;
  `restore.sh` assumes php-fpm is stopped manually.
- Security: rate limiting trusts `REMOTE_ADDR` (fine behind this nginx).
- Tests: deploy ssh/rsync path not exercised end-to-end; no JS/DOM tests beyond
  syntax checking; no concurrency test.

## How to operate

```bash
./scripts/test.sh              # full suite
./scripts/deploy.sh --check    # manifest + syntax, offline
./scripts/deploy.sh            # tests → upload → cron → smoke checks
php scripts/ops/health.php     # manual health check
./scripts/ops/backup.sh        # manual backup (also daily via cron)
```
