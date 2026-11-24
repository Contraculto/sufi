<?php
namespace SUFI\Templates;

/**
 * Shared visual language for every human-facing page.
 *
 * The look is deliberately old-web: warm paper, a ruled page, serif body type,
 * small-caps labels, hard-edged buttons and folder tabs. No framework, no
 * external fonts, no build step — just a stylesheet you can read.
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
        . '<rect width="64" height="64" rx="10" fill="#fbf7ee"/>'
        . '<text x="32" y="47" font-family="Georgia,serif" font-size="46"'
        . ' text-anchor="middle" fill="#241f19">&#10087;</text>'
        . '</svg>'
    );

    echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
    echo "<meta charset=\"utf-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    echo "<meta name=\"color-scheme\" content=\"light dark\">\n";
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES) . "</title>\n";
    echo '<link rel="icon" href="' . htmlspecialchars($icon, ENT_QUOTES) . "\">\n";
    echo "<style>\n" . theme_css();
    if (trim($extra_css) !== '') {
        echo "\n" . $extra_css;
    }
    echo "\n</style>\n</head>\n";
}

function theme_css(): string
{
    return <<<'CSS'
:root {
    --paper:      #fbf7ee;
    --paper-2:    #f4ecdd;
    --paper-3:    #fffdf7;
    --ink:        #241f19;
    --ink-soft:   #6f6253;
    --rule:       #ded2bb;
    --hair:       rgba(36, 31, 25, .14);
    --link:       #1b4c77;
    --link-visited: #6a4079;
    --stamp:      #a83228;
    --gold:       #a97e22;
    --tape:       rgba(169, 126, 34, .26);
    --shadow:     2px 2px 0 rgba(36, 31, 25, .16);
    --serif:      Georgia, "Iowan Old Style", "Palatino Linotype", "Book Antiqua", Palatino, serif;
    --sans:       "Helvetica Neue", Helvetica, Arial, sans-serif;
    --mono:       ui-monospace, "SFMono-Regular", "Cascadia Code", "Fira Mono", Consolas, monospace;
}
@media (prefers-color-scheme: dark) {
    :root {
        --paper:      #16130f;
        --paper-2:    #211c15;
        --paper-3:    #1c1812;
        --ink:        #ece3d3;
        --ink-soft:   #a89b88;
        --rule:       #3b3329;
        --hair:       rgba(236, 227, 211, .16);
        --link:       #93bdde;
        --link-visited: #c8a6d8;
        --stamp:      #d8756a;
        --gold:       #d3ab5c;
        --tape:       rgba(211, 171, 92, .20);
        --shadow:     2px 2px 0 rgba(0, 0, 0, .55);
    }
}

*, *::before, *::after { box-sizing: border-box; }

html { -webkit-text-size-adjust: 100%; }

body {
    margin: 0;
    min-height: 100vh;
    color: var(--ink);
    background-color: var(--paper);
    /* faint ruled paper, fixed so the page reads like a sheet on a desk */
    background-image: repeating-linear-gradient(
        0deg, transparent 0 27px, rgba(36, 31, 25, .035) 27px 28px);
    background-attachment: fixed;
    font: 16px/1.65 var(--serif);
}
@media (prefers-color-scheme: dark) {
    body {
        background-image: repeating-linear-gradient(
            0deg, transparent 0 27px, rgba(255, 255, 255, .032) 27px 28px);
    }
}

::selection { background: var(--ink); color: var(--paper); }

a {
    color: var(--link);
    text-decoration: underline;
    text-decoration-thickness: 1px;
    text-underline-offset: 2px;
}
a:visited { color: var(--link-visited); }
a:hover { text-decoration-thickness: 2px; }

p { margin: 0 0 1rem; }
h1, h2, h3 { font-weight: 700; line-height: 1.15; margin: 0; }
hr { border: none; border-top: 1px solid var(--rule); margin: 1.75rem 0; }
code, kbd { font: .86em/1.4 var(--mono); background: var(--paper-2); padding: .05em .3em; }

/* ── page frame ─────────────────────────────────────────────────────────── */

#app, .wrap { width: 100%; max-width: 44rem; margin: 0 auto; padding: 2rem 1.25rem 5rem; }

/* ── masthead ───────────────────────────────────────────────────────────── */

.masthead {
    border-bottom: 3px double var(--ink);
    padding-bottom: .8rem;
    margin-bottom: 1.9rem;
}
.kicker {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 1rem;
    font: 600 .64rem/1.5 var(--sans);
    text-transform: uppercase;
    letter-spacing: .18em;
    color: var(--ink-soft);
}
.nameplate {
    font: 700 clamp(2.2rem, 9vw, 3.3rem)/1 var(--serif);
    letter-spacing: -.02em;
    margin: .55rem 0 .3rem;
}
.nameplate a { color: inherit; text-decoration: none; }
.masthead-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 1rem;
    flex-wrap: wrap;
}
.handle {
    font: .82rem/1.5 var(--mono);
    color: var(--ink-soft);
    overflow-wrap: anywhere;
}
.epigraph {
    font-style: italic;
    color: var(--ink-soft);
    font-size: .95rem;
    margin: .65rem 0 0;
}
.epigraph::before { content: "\2767  "; color: var(--gold); font-style: normal; }
.lede { font-size: 1.05rem; line-height: 1.7; }
.lede::first-letter {
    float: left;
    font-size: 3.3rem;
    line-height: .82;
    padding: .18rem .45rem 0 0;
    color: var(--stamp);
    font-weight: 700;
}

/* ── small labels and section heads ─────────────────────────────────────── */

.eyebrow {
    display: flex;
    align-items: center;
    gap: .75rem;
    font: 600 .66rem/1.5 var(--sans);
    text-transform: uppercase;
    letter-spacing: .18em;
    color: var(--ink-soft);
    margin: 0 0 .7rem;
}
.eyebrow::after { content: ""; flex: 1; height: 1px; background: var(--rule); }
.eyebrow.plain::after { display: none; }
.kind {
    font: 600 .62rem/1.6 var(--sans);
    text-transform: uppercase;
    letter-spacing: .12em;
    color: var(--ink-soft);
}
.note { font-size: .85rem; color: var(--ink-soft); }

/* ── forms ──────────────────────────────────────────────────────────────── */

.sheet {
    position: relative;
    background: var(--paper-3);
    border: 1px solid var(--rule);
    box-shadow: var(--shadow);
    padding: 1.1rem 1.2rem 1.15rem;
}
.sheet::before {
    /* a strip of tape holding the sheet to the desk */
    content: "";
    position: absolute;
    top: -.55rem;
    left: 1.4rem;
    width: 4.4rem;
    height: 1.05rem;
    background: var(--tape);
    transform: rotate(-2.5deg);
}
@media (max-width: 480px) { .sheet::before { display: none; } }

.field { display: flex; flex-direction: column; gap: .3rem; min-width: 0; }
.field > span, label.cap {
    font: 600 .62rem/1.5 var(--sans);
    text-transform: uppercase;
    letter-spacing: .16em;
    color: var(--ink-soft);
}
input[type="text"], input[type="url"], input[type="password"], input[type="email"], select {
    font: .88rem/1.5 var(--mono);
    color: var(--ink);
    background: var(--paper-3);
    border: 1px solid var(--rule);
    border-radius: 2px;
    padding: .42rem .55rem;
    min-width: 0;
}
input::placeholder { color: var(--ink-soft); opacity: .75; font-style: italic; }
input:focus, select:focus, textarea:focus, button:focus-visible {
    outline: 1px solid var(--ink);
    outline-offset: 1px;
}
.stack { display: flex; flex-direction: column; gap: .9rem; }
.row { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }

/* ── buttons: chunky, hard shadow, lift on hover ────────────────────────── */

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    cursor: pointer;
    font: 600 .68rem/1.5 var(--sans);
    text-transform: uppercase;
    letter-spacing: .14em;
    color: var(--ink);
    background: var(--paper-3);
    border: 1px solid var(--ink);
    border-radius: 0;
    padding: .45rem .85rem;
    box-shadow: var(--shadow);
    transition: transform .06s ease, box-shadow .06s ease, background .12s ease;
    text-decoration: none;
}
.btn:hover { transform: translate(-1px, -1px); box-shadow: 3px 3px 0 var(--hair); }
.btn:active { transform: translate(1px, 1px); box-shadow: none; }
.btn[disabled] { opacity: .45; cursor: default; transform: none; box-shadow: none; }
.btn.primary { background: var(--ink); color: var(--paper); }
.btn.primary:visited { color: var(--paper); }
.btn.ghost {
    border-color: var(--rule);
    background: transparent;
    color: var(--ink-soft);
    box-shadow: none;
}
.btn.ghost:hover { border-color: var(--ink); color: var(--ink); transform: none; box-shadow: none; }
.btn.danger { border-color: var(--stamp); background: transparent; color: var(--stamp); box-shadow: none; }
.btn.danger:hover { background: var(--stamp); color: var(--paper); transform: none; box-shadow: none; }
.btn.small { font-size: .6rem; padding: .22rem .5rem; letter-spacing: .1em; }

/* ── folder tabs ────────────────────────────────────────────────────────── */

.tabs {
    display: flex;
    align-items: flex-end;
    gap: .2rem;
    margin: 2rem 0 1.6rem;
    padding-left: .4rem;
    border-bottom: 1px solid var(--ink);
    overflow-x: auto;
}
.tab {
    cursor: pointer;
    border: 1px solid var(--rule);
    border-bottom: none;
    border-radius: 6px 6px 0 0;
    background: var(--paper-2);
    color: var(--ink-soft);
    padding: .5rem .95rem .55rem;
    margin-bottom: -1px;
    font: 600 .66rem/1.5 var(--sans);
    text-transform: uppercase;
    letter-spacing: .14em;
    white-space: nowrap;
}
.tab:hover { color: var(--ink); }
.tab.active {
    background: var(--paper);
    color: var(--ink);
    border-color: var(--ink);
    padding-bottom: .6rem;
}

/* ── entries (posts, tag pages, archives) ───────────────────────────────── */

.ledger { list-style: none; margin: 0; padding: 0; }
.entry {
    display: grid;
    grid-template-columns: 6rem 1fr;
    gap: 1rem;
    padding: 1.1rem 0;
    border-bottom: 1px solid var(--rule);
}
.entry:last-child { border-bottom: none; }
.entry-meta {
    font: .68rem/1.6 var(--mono);
    color: var(--ink-soft);
    text-align: right;
    padding-right: .9rem;
    border-right: 1px solid var(--rule);
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
        padding-right: 0;
        border-right: none;
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
    border-top: 1px solid var(--rule);
    font: .66rem/1.7 var(--mono);
    color: var(--ink-soft);
}
.colophon::before { content: "\2042"; flex: none; }
.colophon > * { flex: 1; }

/* ── login gate ─────────────────────────────────────────────────────────── */

body.gate {
    display: grid;
    place-content: center;
    padding: 2rem 1.25rem;
}
.gate-card {
    width: min(24rem, 100%);
    background: var(--paper-3);
    border: 1px solid var(--ink);
    box-shadow: 4px 4px 0 var(--hair);
    padding: 1.6rem 1.5rem 1.4rem;
    position: relative;
}
.gate-card::before {
    content: "";
    position: absolute;
    top: -.6rem;
    left: 50%;
    width: 4.6rem;
    height: 1.1rem;
    background: var(--tape);
    transform: translateX(-50%) rotate(1.5deg);
}
.gate .nameplate { text-align: center; margin: .2rem 0 .4rem; }
.gate .kicker { border-bottom: 1px solid var(--rule); padding-bottom: .5rem; }
.gate .epigraph { text-align: center; margin-bottom: 1.2rem; }
.gate form { display: flex; flex-direction: column; gap: .8rem; }
.gate .err {
    font: .78rem/1.5 var(--mono);
    color: var(--stamp);
    border: 1px dashed var(--stamp);
    padding: .35rem .5rem;
    margin: 0;
}
CSS;
}
