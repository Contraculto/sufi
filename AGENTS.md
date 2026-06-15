# SUFI — Agent Orientation Guide

> Single User Fediverse Instance. Minimal PHP ActivityPub server for one user — no framework, no Composer, no database server.

---

## 1. Quickstart

```bash
cd /mnt/Sixto/DEV/sufi

# Local dev server (PHP built-in)
php -S 127.0.0.1:8080

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
├── sys/                    # Keys, SQLite DB, logs
├── templates/              # HTML templates
├── deploy/                 # Web server configuration examples
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
| Rotate keys | `sys/` (regenerate RSA pair) |

---

## 5. Dev Commands

```bash
php -S 127.0.0.1:8080       # Local server
php -l index.php            # Syntax check
```

---

## 6. Deploy

Deploy manually: sync all files except `sys/` to the server, then generate keys and configure `sys/config.php` using the setup steps in `README.md`.

See `deploy/` for Apache and Nginx configuration examples.

---

## 7. Critical Rules

1. **Never commit `sys/private.pem`.** This is the ActivityPub signing key. Leaking it allows anyone to impersonate your actor.
2. **SQLite must be web-server-writable.** The `sys/` directory needs write permissions for the PHP process.
3. **ActivityPub is strict about Content-Type.** All JSON-LD responses must use `application/activity+json`.
4. **HTTP Signatures are mandatory.** Every incoming POST to inbox must be verified. Every outgoing activity must be signed.
5. **No Composer autoloading.** This project is dependency-free. Include files manually if needed.
