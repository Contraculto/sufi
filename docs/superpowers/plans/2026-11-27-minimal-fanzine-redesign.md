# SUFI Minimal Fanzine Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace SUFI's brown "old-web paper" theme with the flat minimal fanzine language of contraculto.com (own blue/amber accent, Anton + Space Mono self-hosted, system serif body), across every human-facing page.

**Architecture:** Approach A from the spec — rewrite the token system inside `templates/_theme.php` while keeping the existing class-name API (`.masthead`, `.entry`, `.btn`, `.tabs`, `.sheet`, `.gate-card`, …), so markup changes are deletions only. Fonts are static woff2 files in a new `fonts/` directory, served directly by Apache/Nginx in production and via a new cli-server pass-through in `index.php` for dev/tests. Spec: `docs/superpowers/specs/2026-11-27-minimal-fanzine-redesign-design.md`.

**Tech Stack:** PHP 8.1+ (no framework, no Composer), plain CSS in PHP heredocs, SQLite, Bash test harness (`scripts/test.sh`), Playwright MCP for screenshots.

**Testing note:** this is a presentational change. The existing suite (`./scripts/test.sh`) must stay green throughout — it asserts on markup IDs (`tab-posts`, …) and flows, not on colors. New "tests" per task are lint + targeted greps for dead tokens + HTTP checks; Task 6 is full visual verification with screenshots.

**Token reference (used by every task):**
`--paper --ink --ink-soft --accent --on-accent --line --danger --display --serif --mono`
Deleted forever: `--paper-2 --paper-3 --rule --hair --stamp --gold --tape --shadow --link --link-visited --sans`

---

### Task 1: Self-hosted fonts + deploy manifest

**Files:**
- Create: `fonts/anton-latin.woff2`, `fonts/space-mono-latin-400.woff2`, `fonts/space-mono-latin-700.woff2`, `fonts/OFL-anton.txt`, `fonts/OFL-spacemono.txt`
- Modify: `scripts/deploy.sh:30` (APP_FILES)
- Modify: `AGENTS.md` (project structure tree)

Both fonts are SIL Open Font License 1.1, so committing them (with the license texts) is allowed. The woff2 URLs below are the `latin` subsets from the Google Fonts css2 API (fetched 2026-11-27); they are stable versioned URLs.

- [ ] **Step 1: Download the font files and licenses**

```bash
cd /mnt/Sixto/DEV/sufi
mkdir -p fonts
curl -fsSL -o fonts/anton-latin.woff2 \
  https://fonts.gstatic.com/s/anton/v27/1Ptgg87LROyAm3Kz-C8.woff2
curl -fsSL -o fonts/space-mono-latin-400.woff2 \
  https://fonts.gstatic.com/s/spacemono/v17/i7dPIFZifjKcF5UAWdDRYEF8RQ.woff2
curl -fsSL -o fonts/space-mono-latin-700.woff2 \
  https://fonts.gstatic.com/s/spacemono/v17/i7dMIFZifjKcF5UAWdDRaPpZUFWaHg.woff2
curl -fsSL -o fonts/OFL-anton.txt \
  https://raw.githubusercontent.com/google/fonts/main/ofl/anton/OFL.txt
curl -fsSL -o fonts/OFL-spacemono.txt \
  https://raw.githubusercontent.com/google/fonts/main/ofl/spacemono/OFL.txt
```

- [ ] **Step 2: Verify the downloads**

```bash
file fonts/*.woff2
ls -la fonts/
```

Expected: each woff2 reported as `Web Open Font Format (version 2)` (or `woff2`), sizes roughly 10–60 KB each; two OFL text files present. If `file` says "HTML document" or "ASCII text", the URL is wrong — stop and re-fetch the css2 API for fresh URLs.

- [ ] **Step 3: Add fonts to the deploy manifest**

In `scripts/deploy.sh` line 30, change:

```bash
APP_FILES=(index.php .htaccess README.md LICENSE AGENTS.md templates deploy)
```

to:

```bash
APP_FILES=(index.php .htaccess README.md LICENSE AGENTS.md templates fonts deploy)
```

- [ ] **Step 4: Update AGENTS.md project structure**

In `AGENTS.md`, change:

```
├── templates/              # HTML templates (ActivityPub JSON + panel)
│   ├── _theme.php          # Shared CSS/head for the human-facing pages
├── deploy/                 # Web server configuration examples
```

to:

```
├── templates/              # HTML templates (ActivityPub JSON + panel)
│   ├── _theme.php          # Shared CSS/head for the human-facing pages
├── fonts/                  # Self-hosted woff2 (Anton, Space Mono) + OFL licenses
├── deploy/                 # Web server configuration examples
```

- [ ] **Step 5: Verify manifest check passes and files are tracked**

```bash
./scripts/deploy.sh --check && echo MANIFEST-OK
git check-ignore fonts/anton-latin.woff2 || echo NOT-IGNORED
```

Expected: `MANIFEST-OK` and `NOT-IGNORED`.

- [ ] **Step 6: Commit**

```bash
git add fonts scripts/deploy.sh AGENTS.md
git commit -m "Add self-hosted fonts (Anton, Space Mono) to repo and deploy manifest"
```

---

### Task 2: Serve static files under the PHP dev server

Production Apache/Nginx serve `fonts/` directly (`.htaccess` `!-f` conditions, coexistence block only intercepts AP paths). But dev and the test suite run `php -S host:port index.php` with `index.php` as router for *every* request, and the router 404s unknown paths — so fonts would 404 in dev. Add a cli-server-only pass-through.

**Files:**
- Modify: `index.php:17` (insert after the requires, before the bootstrap comment)

- [ ] **Step 1: Add the pass-through**

In `index.php`, immediately after:

```php
require __DIR__ . "/templates/actor.php";
require __DIR__ . "/templates/webfinger.php";
```

insert:

```php

// PHP built-in server (dev/tests): let it serve real static files (fonts/)
// directly. sys/ and dotfiles stay unreachable, mirroring the Apache rules.
if (PHP_SAPI === 'cli-server') {
    $req_path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $static   = realpath(__DIR__ . $req_path);
    if ($static !== false && is_file($static)
        && str_starts_with($static, __DIR__ . DIRECTORY_SEPARATOR)
        && !str_starts_with($static, __DIR__ . DIRECTORY_SEPARATOR . 'sys' . DIRECTORY_SEPARATOR)
        && !str_contains($req_path, '/.')) {
        return false;
    }
}
```

- [ ] **Step 2: Lint**

```bash
php -l index.php
```

Expected: `No syntax errors detected in index.php`

- [ ] **Step 3: Verify font serving, sys/ protection and routing on a throwaway instance**

The local repo has no `sys/private.pem`, so use a sandbox copy (same recipe as `scripts/test.sh`):

```bash
cd /mnt/Sixto/DEV/sufi
tmp=$(mktemp -d)
tar -C . --exclude=.git --exclude='sys/*.sqlite' --exclude='sys/*.pem' --exclude='sys/logs' -cf - . | tar -C "$tmp" -xf -
openssl genrsa -out "$tmp/sys/private.pem" 2048 2>/dev/null
cat >> "$tmp/sys/config.php" <<'PHP'

$server        = '127.0.0.1:8290';
$username      = 'alice';
$password      = 'testpass';
$base_path     = '';
$cookie_secure = false;
$base_url      = 'http://127.0.0.1:8290/';
$actor_name    = 'Alice';
$allow_private_fetch = true;
PHP
php -S 127.0.0.1:8290 "$tmp/index.php" > "$tmp/server.log" 2>&1 &
sleep 1
curl -s -o /dev/null -w 'fonts: %{http_code}\n'  http://127.0.0.1:8290/fonts/anton-latin.woff2
curl -s -o /dev/null -w 'sys:   %{http_code}\n'  http://127.0.0.1:8290/sys/private.pem
curl -s -o /dev/null -w 'actor: %{http_code}\n'  http://127.0.0.1:8290/ap/actor
kill %1
```

Expected:

```
fonts: 200
sys:   404
actor: 200
```

- [ ] **Step 4: Run the full suite**

```bash
./scripts/test.sh
```

Expected: exit 0, no FAIL lines.

- [ ] **Step 5: Commit**

```bash
git add index.php
git commit -m "Serve static files (fonts/) under the PHP dev server, keeping sys/ blocked"
```

---

### Task 3: Rewrite the theme (`templates/_theme.php`)

The core of the redesign. Complete replacement file below: new tokens, `@font-face` emission with `$base_url`-prefixed URLs, new favicon, flat fanzine CSS. Class names are unchanged; epigraphs and `theme_date()` are untouched.

**Files:**
- Modify: `templates/_theme.php` (full rewrite)

- [ ] **Step 1: Replace the whole file**

```php
<?php
namespace SUFI\Templates;

/**
 * Shared visual language for every human-facing page.
 *
 * The look is a minimal fanzine: flat paper, one accent color, condensed
 * uppercase display type (Anton), mono labels (Space Mono), hard 2px rules.
 * Body text stays in a system serif so long entries remain easy to read.
 * Fonts are self-hosted woff2 from fonts/ — no third-party requests.
 *
 * Pages call theme_head() instead of repeating <head> boilerplate, and may pass
 * extra CSS that only applies to that page.
 */

/** Little sayings printed under the nameplate. */
function theme_epigraphs(): array
{
    return [
        'Write it down before it evaporates.',
        'One voice, no algorithm, no hurry.',
        'A quiet room with a loud door.',
        'Longhand for the network.',
        'Small, slow, and entirely yours.',
        'Every entry is a letter to someone.',
        'Handmade on the open web.',
        'The porch light is on.',
    ];
}

function theme_epigraph(): string
{
    $lines = theme_epigraphs();
    return $lines[array_rand($lines)];
}

/** "12 Nov 2025" from an ActivityStreams timestamp, or a fallback string. */
function theme_date(?string $iso, string $fallback = ''): string
{
    $ts = $iso ? strtotime($iso) : false;
    return $ts ? date('j M Y', $ts) : $fallback;
}

/**
 * Emit the doctype, <head> and shared stylesheet.
 * Callers are expected to open <body> themselves.
 */
function theme_head(string $title, string $extra_css = ''): void
{
    $icon = 'data:image/svg+xml,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
        . '<rect width="64" height="64" fill="#191817"/>'
        . '<text x="32" y="54" font-family="monospace" font-size="56"'
        . ' text-anchor="middle" fill="#d9a441">*</text>'
        . '</svg>'
    );

    echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
    echo "<meta charset=\"utf-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "<meta name=\"color-scheme\" content=\"light dark\">\n";
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES) . "</title>\n";
    echo '<link rel="icon" href="' . htmlspecialchars($icon, ENT_QUOTES) . "\">\n";
    echo "<style>\n" . theme_fonts_css() . "\n" . theme_css();
    if (trim($extra_css) !== '') {
        echo "\n" . $extra_css;
    }
    echo "\n</style>\n</head>\n";
}

/**
 * @font-face rules for the self-hosted fonts. URLs are prefixed with the
 * instance base URL so subdirectory deploys and deep pages resolve correctly.
 */
function theme_fonts_css(): string
{
    global $base_url;
    // Inside <style>, HTML entities are not decoded — escape for CSS instead.
    $base = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) ($base_url ?? ''));
    $src  = fn(string $file): string => $base . 'fonts/' . $file;

    return "@font-face {\n"
        . "    font-family: 'Anton';\n"
        . "    font-style: normal;\n"
        . "    font-weight: 400;\n"
        . "    font-display: swap;\n"
        . "    src: url('" . $src('anton-latin.woff2') . "') format('woff2');\n"
        . "}\n"
        . "@font-face {\n"
        . "    font-family: 'Space Mono';\n"
        . "    font-style: normal;\n"
        . "    font-weight: 400;\n"
        . "    font-display: swap;\n"
        . "    src: url('" . $src('space-mono-latin-400.woff2') . "') format('woff2');\n"
        . "}\n"
        . "@font-face {\n"
        . "    font-family: 'Space Mono';\n"
        . "    font-style: normal;\n"
        . "    font-weight: 700;\n"
        . "    font-display: swap;\n"
        . "    src: url('" . $src('space-mono-latin-700.woff2') . "') format('woff2');\n"
        . "}";
}

function theme_css(): string
{
    return <<<'CSS'
:root {
    --paper:     #f7f6f2;
    --ink:       #191817;
    --ink-soft:  #6b6660;
    --accent:    #1f3fbf;
    --on-accent: #f7f6f2;
    --line:      rgba(25, 24, 23, .14);
    --danger:    #b3261e;
    --display:   'Anton', 'Arial Narrow', 'Helvetica Neue', sans-serif;
    --serif:     Georgia, "Iowan Old Style", "Palatino Linotype", "Book Antiqua", Palatino, serif;
    --mono:      'Space Mono', ui-monospace, "SFMono-Regular", "Cascadia Code", Consolas, monospace;
}
@media (prefers-color-scheme: dark) {
    :root {
        --paper:     #131211;
        --ink:       #e8e5dd;
        --ink-soft:  #9a948a;
        --accent:    #d9a441;
        --on-accent: #131211;
        --line:      rgba(232, 229, 221, .16);
        --danger:    #e06c60;
    }
}

*, *::before, *::after { box-sizing: border-box; }

html { -webkit-text-size-adjust: 100%; }

body {
    margin: 0;
    min-height: 100vh;
    color: var(--ink);
    background: var(--paper);
    font: 16px/1.65 var(--serif);
}

::selection { background: var(--accent); color: var(--on-accent); }

a {
    color: inherit;
    text-decoration: underline;
    text-decoration-thickness: 1px;
    text-underline-offset: 2px;
}
a:hover { color: var(--accent); }

p { margin: 0 0 1rem; }
h1, h2, h3 { font-weight: 700; line-height: 1.15; margin: 0; }
hr { border: none; border-top: 1px solid var(--line); margin: 1.75rem 0; }
code, kbd { font: .86em/1.4 var(--mono); border: 1px solid var(--line); padding: .05em .3em; }

/* ── page frame ─────────────────────────────────────────────────────────── */

#app, .wrap { width: 100%; max-width: 44rem; margin: 0 auto; padding: 2rem 1.25rem 5rem; }

/* ── masthead ───────────────────────────────────────────────────────────── */

.masthead {
    border-bottom: 2px solid var(--ink);
    padding-bottom: .9rem;
    margin-bottom: 1.9rem;
}
.kicker {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 1rem;
    font: 700 .66rem/1.5 var(--mono);
    text-transform: uppercase;
    letter-spacing: .15em;
    color: var(--ink-soft);
}
.nameplate {
    font: 400 clamp(2.6rem, 9vw, 4.2rem)/.9 var(--display);
    text-transform: uppercase;
    margin: .6rem 0 .35rem;
}
.nameplate a { color: inherit; text-decoration: none; }
.nameplate a:hover { color: var(--accent); }
.masthead-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 1rem;
    flex-wrap: wrap;
}
.handle {
    font: .78rem/1.5 var(--mono);
    color: var(--ink-soft);
    overflow-wrap: anywhere;
}
.epigraph {
    font-style: italic;
    color: var(--ink-soft);
    font-size: .95rem;
    margin: .6rem 0 0;
}
.lede { font-size: 1.08rem; line-height: 1.7; }

/* ── small labels and section heads ─────────────────────────────────────── */

.eyebrow {
    display: flex;
    align-items: center;
    gap: .75rem;
    font: 700 .66rem/1.5 var(--mono);
    text-transform: uppercase;
    letter-spacing: .15em;
    color: var(--ink-soft);
    margin: 0 0 .8rem;
}
.eyebrow::before { content: "\25A0"; color: var(--accent); font-size: .6rem; }
.eyebrow::after { content: ""; flex: 1; height: 1px; background: var(--line); }
.eyebrow.plain::after { display: none; }
.kind {
    font: 700 .62rem/1.6 var(--mono);
    text-transform: uppercase;
    letter-spacing: .12em;
    color: var(--ink-soft);
}
.note { font-size: .85rem; color: var(--ink-soft); }

/* ── forms ──────────────────────────────────────────────────────────────── */

.sheet {
    background: var(--paper);
    border: 2px solid var(--ink);
    padding: 1.1rem 1.2rem 1.15rem;
}

.field { display: flex; flex-direction: column; gap: .3rem; min-width: 0; }
.field > span, label.cap {
    font: 700 .62rem/1.5 var(--mono);
    text-transform: uppercase;
    letter-spacing: .14em;
    color: var(--ink-soft);
}
input[type="text"], input[type="url"], input[type="password"], input[type="email"], select {
    font: .85rem/1.5 var(--mono);
    color: var(--ink);
    background: var(--paper);
    border: 1px solid var(--ink-soft);
    border-radius: 0;
    padding: .45rem .55rem;
    min-width: 0;
}
input::placeholder { color: var(--ink-soft); }
input:focus, select:focus, textarea:focus {
    outline: 2px solid var(--ink);
    outline-offset: 1px;
}
button:focus-visible, a:focus-visible {
    outline: 2px solid var(--accent);
    outline-offset: 2px;
}
.stack { display: flex; flex-direction: column; gap: .9rem; }
.row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }

/* ── buttons: flat blocks, hover goes accent ────────────────────────────── */

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    cursor: pointer;
    font: 700 .66rem/1.5 var(--mono);
    text-transform: uppercase;
    letter-spacing: .12em;
    color: var(--ink);
    background: transparent;
    border: 2px solid var(--ink);
    border-radius: 0;
    padding: .42rem .85rem;
    text-decoration: none;
    transition: background .12s ease, color .12s ease, border-color .12s ease;
}
.btn:hover { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
.btn[disabled] { opacity: .45; cursor: default; }
.btn[disabled]:hover { background: transparent; border-color: var(--ink); color: var(--ink); }
.btn.primary { background: var(--ink); color: var(--paper); }
.btn.primary:hover { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
.btn.ghost { border-color: transparent; color: var(--ink-soft); }
.btn.ghost:hover {
    background: transparent;
    border-color: transparent;
    color: var(--accent);
    text-decoration: underline;
}
.btn.danger { border-color: var(--danger); color: var(--danger); }
.btn.danger:hover { background: var(--danger); border-color: var(--danger); color: var(--paper); }
.btn.small { font-size: .6rem; padding: .22rem .5rem; letter-spacing: .1em; }

/* ── section nav: flat mono tabs, accent underline on the active one ────── */

.tabs {
    display: flex;
    gap: 1.4rem;
    margin: 2rem 0 1.6rem;
    background-image: linear-gradient(to top, var(--ink) 2px, transparent 2px);
    overflow-x: auto;
}
.tab {
    cursor: pointer;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    border-radius: 0;
    padding: .4rem .1rem .5rem;
    font: 700 .68rem/1.5 var(--mono);
    text-transform: uppercase;
    letter-spacing: .14em;
    color: var(--ink-soft);
    white-space: nowrap;
}
.tab:hover { color: var(--accent); }
.tab.active { color: var(--ink); border-bottom-color: var(--accent); }

/* ── entries (posts, tag pages, archives) ───────────────────────────────── */

.ledger { list-style: none; margin: 0; padding: 0; }
.entry {
    display: grid;
    grid-template-columns: 6rem 1fr;
    gap: 1rem;
    padding: 1.1rem 0;
    border-bottom: 1px solid var(--line);
}
.entry:last-child { border-bottom: none; }
.entry-meta {
    font: .68rem/1.6 var(--mono);
    color: var(--ink-soft);
    text-align: right;
}
.entry-meta .kind { display: block; }
.entry-body { font-size: 1.01rem; line-height: 1.7; overflow-wrap: anywhere; }
.entry-body p { margin: 0 0 .7rem; }
.entry-body p:last-child { margin-bottom: 0; }
.entry-actions {
    display: flex;
    align-items: center;
    gap: .4rem;
    flex-wrap: wrap;
    margin-top: .5rem;
    opacity: .65;
    transition: opacity .12s ease;
}
.entry:hover .entry-actions, .entry:focus-within .entry-actions { opacity: 1; }

@media (max-width: 640px) {
    .entry { grid-template-columns: 1fr; gap: .35rem; }
    .entry-meta {
        display: flex;
        align-items: baseline;
        gap: .6rem;
        text-align: left;
    }
    .entry-meta .kind { display: inline; }
}

/* ── empty states, misc ─────────────────────────────────────────────────── */

.empty { color: var(--ink-soft); font-style: italic; padding: 1.25rem 0; }
.muted { color: var(--ink-soft); }
.center { text-align: center; }

.colophon {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 3.5rem;
    padding-top: 1rem;
    border-top: 1px solid var(--line);
    font: .66rem/1.7 var(--mono);
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--ink-soft);
}
.colophon::before { content: "\25A0"; flex: none; color: var(--accent); }
.colophon > * { flex: 1; }

/* ── login gate ─────────────────────────────────────────────────────────── */

body.gate {
    display: grid;
    place-content: center;
    padding: 2rem 1.25rem;
}
.gate-card {
    width: min(24rem, 100%);
    background: var(--paper);
    border: 2px solid var(--ink);
    padding: 1.6rem 1.5rem 1.4rem;
}
.gate .nameplate { text-align: center; margin: .2rem 0 .4rem; }
.gate .kicker { border-bottom: 1px solid var(--line); padding-bottom: .5rem; }
.gate .epigraph { text-align: center; margin-bottom: 1.2rem; }
.gate form { display: flex; flex-direction: column; gap: .8rem; }
.gate .err {
    font: .78rem/1.5 var(--mono);
    color: var(--danger);
    border: 1px dashed var(--danger);
    padding: .35rem .5rem;
    margin: 0;
}
CSS;
}
```

- [ ] **Step 2: Lint and confirm no dead tokens remain in the theme**

```bash
php -l templates/_theme.php
grep -nE 'var\(--(paper-2|paper-3|rule|hair|stamp|gold|tape|shadow|link|link-visited|sans)\)' templates/_theme.php || echo CLEAN
```

Expected: `No syntax errors detected` and `CLEAN`.

- [ ] **Step 3: Run the full suite**

```bash
./scripts/test.sh
```

Expected: exit 0, no FAIL lines (the suite asserts on IDs and flows, which are unchanged).

- [ ] **Step 4: Commit**

```bash
git add templates/_theme.php
git commit -m "Rewrite theme as flat minimal fanzine (tokens, Anton/Space Mono, no skeuomorphism)"
```

---

### Task 4: Sweep `home.php` page CSS

The public profile page has its own `$home_css` block referencing deleted tokens (`--rule`, `--paper-3`, `--shadow`). Replace it with the flat version: Anton stat numerals, unframed images, flat visit box.

**Files:**
- Modify: `templates/home.php:20-51` (`$home_css` heredoc)

- [ ] **Step 1: Replace the `$home_css` heredoc**

Replace the entire block from `$home_css = <<<'CSS'` through the closing `CSS;` with:

```php
$home_css = <<<'CSS'
.stats {
    display: flex;
    gap: 1.8rem;
    flex-wrap: wrap;
    margin: 1.4rem 0 2.2rem;
    padding: .9rem 0;
    border-top: 1px solid var(--line);
    border-bottom: 1px solid var(--line);
}
.stats b {
    display: block;
    font: 400 1.9rem/1.1 var(--display);
    text-transform: uppercase;
    letter-spacing: normal;
    color: var(--ink);
}
.stats span {
    font: 700 .64rem/1.6 var(--mono);
    text-transform: uppercase;
    letter-spacing: .12em;
    color: var(--ink-soft);
}
.entry-body figure { margin: .6rem 0; }
.entry-body figure img {
    max-width: 100%;
    display: block;
    border: 1px solid var(--line);
}
.entry-body figcaption { font: .7rem/1.5 var(--mono); color: var(--ink-soft); margin-top: .3rem; }
.visit {
    margin-top: 2.6rem;
    border: 2px solid var(--ink);
    padding: 1.1rem 1.2rem;
}
.visit code { font-size: .9rem; overflow-wrap: anywhere; }
.links {
    display: flex;
    gap: 1.1rem;
    flex-wrap: wrap;
    font: .7rem/1.7 var(--mono);
    text-transform: uppercase;
    letter-spacing: .08em;
}
CSS;
```

- [ ] **Step 2: Lint and grep**

```bash
php -l templates/home.php
grep -nE 'var\(--(paper-2|paper-3|rule|hair|stamp|gold|tape|shadow|link|link-visited|sans)\)' templates/home.php || echo CLEAN
```

Expected: `No syntax errors detected` and `CLEAN`.

- [ ] **Step 3: Run the full suite**

```bash
./scripts/test.sh
```

Expected: exit 0, no FAIL lines.

- [ ] **Step 4: Commit**

```bash
git add templates/home.php
git commit -m "Sweep home page CSS to the flat fanzine tokens"
```

---

### Task 5: Sweep `panel.php` (style block + CSP font-src)

Two changes: (1) the panel's `Content-Security-Policy` has `default-src 'none'` and no `font-src`, so the self-hosted fonts would be blocked on the gate and panel — add `font-src 'self'`; (2) the panel's `<style>` block references deleted tokens and skeuomorphic details (ruled composer lines, rotated stamp, dotted borders, shadows). Element IDs and JS hooks stay exactly as they are — `tests/run.php` asserts on them.

**Files:**
- Modify: `templates/panel.php:4-6` (CSP header)
- Modify: `templates/panel.php:143-320` (`<style>` block)

- [ ] **Step 1: Allow self-hosted fonts in the CSP**

Change:

```php
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; "
    . "style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; "
```

to:

```php
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; font-src 'self'; "
    . "style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; "
```

- [ ] **Step 1b: Fix `--stamp` references in the panel JS**

`--stamp` is deleted from the theme, but two inline-style assignments in the panel JavaScript still reference it. With the token undefined, `var(--stamp)` is invalid at computed-value time and the color silently falls back to the inherited value — the status error red and the over-limit counter red would stop rendering. Point both at `--danger`:

- `templates/panel.php` (`setStatus`): `el.style.color = ok ? '' : 'var(--stamp)';` → `el.style.color = ok ? '' : 'var(--danger)';`
- `templates/panel.php` (char counter): `el.style.color = n > MAX_CHARS ? 'var(--stamp)' : '';` → `el.style.color = n > MAX_CHARS ? 'var(--danger)' : '';`

- [ ] **Step 2: Replace the whole `<style>` block**

Replace everything from `<style>` (line 143) through `</style>` (line 320) with:

```html
<style>
#compose { margin-bottom: 2rem; }

#compose textarea {
    display: block;
    width: 100%;
    min-height: 8.4rem;
    resize: vertical;
    padding: .2rem 0;
    border: none;
    border-bottom: 1px solid var(--line);
    outline: none;
    background: transparent;
    color: var(--ink);
    font: 1.02rem/1.75rem var(--serif);
}
#compose textarea:focus { outline: none; border-bottom-color: var(--ink); box-shadow: 0 1px 0 var(--ink); }
#compose textarea::placeholder { color: var(--ink-soft); }

.compose-foot {
    display: flex;
    align-items: center;
    gap: .6rem;
    flex-wrap: wrap;
    margin-top: .7rem;
    padding-top: .7rem;
    border-top: 1px solid var(--line);
}
.compose-foot #reply { flex: 1; min-width: 11rem; }
#post-btn { margin-left: auto; }
.meter { display: inline-flex; gap: 2px; }
.meter i { display: block; width: 4px; height: 11px; background: var(--line); }
.meter i.on { background: var(--ink); }
.meter.over i.on { background: var(--danger); }
#charcount { font: .68rem/1.5 var(--mono); color: var(--ink-soft); }

.attach-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .7rem;
    margin-top: .8rem;
}
@media (max-width: 560px) { .attach-grid { grid-template-columns: 1fr; } }

.attach-row {
    display: flex;
    align-items: center;
    gap: .6rem;
    flex-wrap: wrap;
    margin-top: .7rem;
    font-size: .8rem;
}
.attach-row input[type=file] {
    font: .68rem/1.5 var(--mono);
    color: var(--ink-soft);
    max-width: 100%;
}
.attach-row input[type=file]::file-selector-button {
    font: 700 .62rem/1.5 var(--mono);
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--ink);
    background: transparent;
    border: 1px solid var(--ink);
    border-radius: 0;
    padding: .3rem .55rem;
    margin-right: .55rem;
    cursor: pointer;
}
.attach-row input[type=file]::file-selector-button:hover {
    background: var(--accent);
    border-color: var(--accent);
    color: var(--on-accent);
}

#attach-preview { margin-top: .5rem; }
.attach-item {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-top: .5rem;
}
.attach-item img {
    width: 3.2rem;
    height: 3.2rem;
    object-fit: cover;
    border: 1px solid var(--line);
    display: block;
}
.attach-item input[type=text] { flex: 1; }

#status { font: .72rem/1.6 var(--mono); color: var(--ink-soft); min-height: 1.3rem; margin-top: .55rem; }

#panel-posts .entry-body .post-text { white-space: pre-wrap; }
.entry-edit { margin-top: .6rem; }
.entry-edit textarea {
    width: 100%;
    padding: .5rem;
    border: 1px solid var(--line);
    background: var(--paper);
    color: var(--ink);
    font: .95rem/1.6 var(--serif);
    resize: vertical;
}

.inbox-toolbar { display: flex; justify-content: flex-end; gap: .4rem; margin-bottom: .8rem; }
.letter {
    background: var(--paper);
    border: 1px solid var(--line);
    padding: .85rem 1rem .75rem;
    margin-bottom: .85rem;
}
.letter.unread { border-left: 3px solid var(--accent); }
.letter-head {
    display: flex;
    align-items: center;
    gap: .6rem;
    flex-wrap: wrap;
    font: .68rem/1.5 var(--mono);
    color: var(--ink-soft);
}
.stamp {
    font: 700 .56rem/1.6 var(--mono);
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--accent);
    border: 1px solid var(--accent);
    padding: 0 .35rem;
}
.who { font-family: var(--mono); color: var(--ink); text-decoration: none; }
.who:hover { text-decoration: underline; color: var(--accent); }
.letter-body {
    font-size: .95rem;
    line-height: 1.6;
    margin-top: .45rem;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.letter-body.muted { font-style: italic; }
.letter-actions { display: flex; gap: .35rem; flex-wrap: wrap; margin-top: .55rem; }
.dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--accent); }
.thread { margin-top: .7rem; padding-left: .8rem; border-left: 2px solid var(--line); }
.letter.group { border-left: 3px solid var(--line); }
.letter.group.unread { border-left-color: var(--accent); }
.group-parent { color: var(--ink-soft); font-size: .88rem; }
.group-thread { margin-top: .55rem; }
.letter.reply {
    background: transparent;
    border: none;
    border-top: 1px dotted var(--line);
    padding: .5rem 0 .4rem;
    margin-bottom: 0;
}
.letter.reply .letter-actions { margin-top: .3rem; }

.follow-form { margin-bottom: .4rem; }
.directory { margin-bottom: 1.5rem; }
.person {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    flex-wrap: wrap;
    padding: .5rem 0;
    border-bottom: 1px solid var(--line);
    font: .82rem/1.6 var(--mono);
}
.person .pname { font-family: var(--serif); font-size: .9rem; color: var(--ink-soft); margin-left: .5rem; }
.person .actions { display: flex; gap: .3rem; flex-wrap: wrap; }

#panel-profile textarea {
    padding: .5rem .55rem;
    border: 1px solid var(--line);
    background: var(--paper);
    color: var(--ink);
    font: .95rem/1.6 var(--serif);
    resize: vertical;
}
</style>
```

- [ ] **Step 3: Lint and grep**

```bash
php -l templates/panel.php
grep -nE 'var\(--(paper-2|paper-3|rule|hair|stamp|gold|tape|shadow|link|link-visited|sans)\)' templates/panel.php || echo CLEAN
grep -c 'font-src' templates/panel.php
```

Expected: `No syntax errors detected`, `CLEAN`, and `1`.

- [ ] **Step 4: Run the full suite**

```bash
./scripts/test.sh
```

Expected: exit 0, no FAIL lines (tab IDs and all JS hooks unchanged).

- [ ] **Step 5: Commit**

```bash
git add templates/panel.php
git commit -m "Sweep panel CSS to flat fanzine tokens, allow self-hosted fonts in CSP"
```

---

### Task 6: Visual verification + final gate

The small federation templates (`actor.php`, `followers.php`, `following.php`, `likes.php`, `outbox.php`, `webfinger.php`) emit JSON only, and the HTML pages inside `index.php` (tag page, authorize_interaction, follow request) use only theme classes — they pick up the new theme automatically. This task proves the result visually and re-runs every gate.

**Files:**
- None modified (verification only; fix-forward commits if something looks wrong)

- [ ] **Step 1: Build a throwaway instance with seeded content**

```bash
cd /mnt/Sixto/DEV/sufi
rm -rf /tmp/sufi-visual && mkdir -p /tmp/sufi-visual
tar -C . --exclude=.git --exclude='sys/*.sqlite' --exclude='sys/*.pem' --exclude='sys/logs' -cf - . | tar -C /tmp/sufi-visual -xf -
openssl genrsa -out /tmp/sufi-visual/sys/private.pem 2048 2>/dev/null
cat >> /tmp/sufi-visual/sys/config.php <<'PHP'

$server        = '127.0.0.1:8291';
$username      = 'alice';
$password      = 'testpass';
$base_path     = '';
$cookie_secure = false;
$base_url      = 'http://127.0.0.1:8291/';
$actor_name    = 'Alice';
$actor_summary = 'A quiet corner of the fediverse. One person writes here; anyone is welcome to read.';
$allow_private_fetch = true;
PHP
nohup php -S 127.0.0.1:8291 /tmp/sufi-visual/index.php > /tmp/sufi-visual/server.log 2>&1 &
echo $! > /tmp/sufi-visual.pid
sleep 1
curl -s -X POST http://127.0.0.1:8291/ap/post \
  --data-urlencode 'content=First entry in the new look. **Flat**, quiet, no tape.' \
  --data-urlencode 'password=testpass' -o /dev/null -w 'seed post: %{http_code}\n'
```

The fixed path and PID file are deliberate: the screenshot steps run in separate shell invocations, so job control (`%1`) and shell variables would not survive.

Expected: `seed post: 200`.

- [ ] **Step 2: Screenshot the public home page, light and dark**

Using the Playwright MCP tools:

1. `browser_navigate` to `http://127.0.0.1:8291/`
2. `browser_emulate_media` with `colorScheme: "light"`, then `browser_take_screenshot` (full page) — save as `home-light.png`
3. `browser_emulate_media` with `colorScheme: "dark"`, then `browser_take_screenshot` — save as `home-dark.png`

Check in the screenshots:
- No brown anywhere; paper is neutral off-white / near-black.
- Nameplate is Anton (visibly condensed, uppercase).
- Kickers, stats labels, colophon are Space Mono; section labels have the accent `■`.
- Stats numerals are Anton; entry body is serif; links turn accent on hover.
- No tape strips, no ruled-paper background, no drop cap, no offset shadows.
- Dark theme uses the amber accent.

If the Playwright browser fails to launch (it exited immediately once in the planning session), retry once; if it still fails, fall back to `curl -s http://127.0.0.1:8291/ | grep -o "fonts/[a-z0-9-]*\.woff2"` (expect the three font paths in the CSS) and ask the user to eyeball the pages, noting the skip in the final report.

- [ ] **Step 3: Screenshot the login gate and the logged-in panel**

1. `browser_navigate` to `http://127.0.0.1:8291/sufi/`
2. Light scheme screenshot (`gate-light.png`): flat card, 2px ink border, Anton "SUFI", no tape.
3. Fill the password field (`input[name=password]`) with `testpass`, click the `knock` button.
4. After the redirect, screenshot the panel (`panel-light.png`): composer sheet is a flat 2px-ink block with no ruled lines, tabs are a flat mono row with accent underline on `entries`, buttons are flat mono.
5. `browser_emulate_media` with `colorScheme: "dark"`, screenshot (`panel-dark.png`): amber accents, unread markers and stamps use the accent, no brown.
6. Click the `people` tab and screenshot: person rows are flat with hairline separators.

Also confirm the fonts actually loaded: `browser_console_messages` shows no `font-src` CSP violations and no 404s for `/fonts/*.woff2` in `browser_network_requests`.

- [ ] **Step 4: Dead-token and font-reference greps across all templates**

```bash
cd /mnt/Sixto/DEV/sufi
grep -rnE 'var\(--(paper-2|paper-3|rule|hair|stamp|gold|tape|shadow|link|link-visited|sans)\)' templates/ index.php || echo TOKENS-CLEAN
grep -c '@font-face' templates/_theme.php
```

Expected: `TOKENS-CLEAN` and `4` (three `@font-face` rules + one docblock mention of `@font-face` in `theme_fonts_css()`'s comment).

- [ ] **Step 5: Shut down the sandbox and run the final gates**

```bash
kill "$(cat /tmp/sufi-visual.pid)" 2>/dev/null; rm -rf /tmp/sufi-visual /tmp/sufi-visual.pid
cd /mnt/Sixto/DEV/sufi
./scripts/test.sh && echo SUITE-GREEN
./scripts/deploy.sh --check && echo MANIFEST-OK
git status --short
```

Expected: `SUITE-GREEN`, `MANIFEST-OK`, and a clean `git status` (screenshots, if saved into the repo, should be deleted or moved out of the repo first — they are working artifacts, not deliverables).

- [ ] **Step 6: Final commit (only if fixes were needed)**

If Steps 2–5 surfaced visual bugs that you fixed, commit them:

```bash
git add -A
git commit -m "Polish fanzine redesign after visual verification"
```

Otherwise nothing to commit — the redesign is complete and verified.
