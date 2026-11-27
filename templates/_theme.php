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
    $base = htmlspecialchars((string) ($base_url ?? ''), ENT_QUOTES);
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
    border: 1px solid var(--line);
    border-radius: 0;
    padding: .45rem .55rem;
    min-width: 0;
}
input::placeholder { color: var(--ink-soft); opacity: .8; }
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
    border-bottom: 2px solid var(--ink);
    overflow-x: auto;
}
.tab {
    cursor: pointer;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    border-radius: 0;
    margin-bottom: -2px;
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
