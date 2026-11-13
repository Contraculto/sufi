# SUFI — Agent Orientation Guide

> Single User Fediverse Instance. Minimal PHP ActivityPub server for one user — no framework, no Composer, no database server.

---

## 1. Quickstart

```bash
cd /mnt/Sixto/DEV/sufi

# Local dev server (PHP built-in)
php -S 127.0.0.1:8080

# Tests (spins throwaway instances in a temp dir)
./scripts/test.sh

# Production deploy
# Sync files to your server, then follow README.md setup steps.
```

---

## 2. Stack

| Layer | Choice |
|-------|--------|
| Language | PHP 8.1+ (no framework) |
| Protocol | ActivityPub (ActivityStreams 2.0) |
| Crypto | RSA keypair for HTTP Signatures |
| Storage | SQLite (`sys/sufi.sqlite`) |
| Templating | Plain PHP (`templates/`) |

---

## 3. Project Structure

```
sufi/
├── index.php               # Entry point / router
├── sys/                    # Keys, SQLite DB, logs, delivery queue
│   └── delivery.php        # signing, fetch guards, delivery worker core
├── templates/              # HTML templates (ActivityPub JSON + panel)
├── deploy/                 # Web server configuration examples
├── scripts/test.sh         # Test runner (lint + sandbox + suite)
├── scripts/ops/deliver.php # Delivery worker (cron, every minute)
├── scripts/ops/backup.sh   # Daily SQLite backup
├── scripts/ops/prune.php   # Inbox retention
├── tests/                  # tests/run.php + fake remote server fixture
└── .htaccess               # Apache rewrite rules
```

---

## 4. How to Navigate

| Task | File(s) |
|------|---------|
| Change routing | `index.php` |
| Change actor/profile output | `index.php` (ActivityPub JSON endpoints) |
| Verify HTTP Signatures | `index.php` (signature verification logic) |
| Change HTML views | `templates/` |
| Rotate keys | Replace `sys/private.pem`; the Actor row syncs on next request |
| Actor creation | `ensure_actor()` in `sys/database.php` (runs on bootstrap) |
| Add or fix a test | `tests/run.php` (assertions), `scripts/test.sh` (setup) |
| Outbound delivery / retries | `sys/delivery.php` + `scripts/ops/deliver.php` |
| Post formatting / attachments | `format_content()`, `upload_media()` in `index.php` |
| Schema changes | bump `SCHEMA_VERSION` + add a migration in `init_schema()` |
| Blocking or moderation | `inbox()` + `action_users()` in `index.php`, `Block` table |

---

## 5. Dev Commands

```bash
php -S 127.0.0.1:8080       # Local server (needs only sys/private.pem)
php -l index.php            # Syntax check
./scripts/test.sh           # Full suite, no dependencies beyond PHP + OpenSSL
```

---

## 6. Deploy

```bash
./scripts/deploy.sh --dry-run    # preview (shows deletions too)
./scripts/deploy.sh              # tests + upload + federation smoke check
./scripts/deploy.sh --quick      # skip tests
```

Targets `contraculto.com:/var/www/contraculto/sufi` by default. Only application
files are uploaded; `sys/config.php`, keys, `sufi.sqlite` and `logs/` stay on the
server. First-time installs still need keys + an `Actor` row (see `README.md`).

See `deploy/` for Apache and Nginx configuration examples.

---

## 7. Critical Rules

1. **Never commit `sys/private.pem`.** This is the ActivityPub signing key. Leaking it allows anyone to impersonate your actor.
2. **SQLite must be web-server-writable.** The `sys/` directory needs write permissions for the PHP process.
3. **ActivityPub is strict about Content-Type.** All JSON-LD responses must use `application/activity+json`.
4. **HTTP Signatures are mandatory.** Every activity posted to the inbox is verified *before* it is stored or acted on — do not move `verifyHTTPSignature()` below database writes. Every outgoing activity must be signed.
5. **No Composer autoloading.** This project is dependency-free. Include files manually if needed.
6. **Never enable `$allow_private_fetch` in production.** It disables the HTTPS/private-IP guards on outbound fetches and exists only for local tests.
7. **Schema changes need a migration.** Bump `SCHEMA_VERSION` in `sys/database.php` and add the step inside `init_schema()`; never edit old migration steps.
8. **Run `./scripts/test.sh` before touching production.** It covers discovery, posting, panel auth, inbox signature rejection and delivery. Not shipping untested federation changes matters more here than in a normal app: a broken signature check can silently defederate you.
