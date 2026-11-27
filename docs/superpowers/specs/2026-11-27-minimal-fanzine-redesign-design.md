# SUFI minimal fanzine redesign — design spec

Date: 2026-11-27
Status: approved (design), pending implementation plan

## Context

SUFI's human-facing pages use an "old-web paper" theme: warm brown paper,
ruled-line background, serif everything, tape strips, drop caps, folder tabs,
hard offset shadows. The owner wants it replaced with the minimal fanzine
language used on contraculto.com (`~/DEV/contraculto_corp/contraculto.css`):
flat ink/paper, one accent color, condensed uppercase display type, mono
labels, hard 2px rules.

Decisions taken during brainstorming:

- **Direction:** fanzine structure, calmer voice — full flat/fanzine layout
  logic, but body text stays in a readable system serif and the accent is
  quieter than contraculto's pink/acid.
- **Fonts:** self-host Anton + Space Mono as woff2 in the repo. No Google
  Fonts CDN, no third-party requests.
- **Accent:** SUFI's own pair (blue/amber), not contraculto's.
- **Scope:** everything in one pass — `_theme.php`, `home.php`, `panel.php`,
  and the small federation pages (`actor`, `followers`, `following`, `likes`,
  `outbox`, `webfinger`).
- **Approach A:** rewrite the token system in `theme_css()` but keep the
  existing class-name API (`.masthead`, `.entry`, `.btn`, `.tabs`, `.sheet`,
  `.gate-card`, …) so markup changes are mostly deletions. No token aliasing —
  old token names disappear entirely.

## 1. Design tokens (`templates/_theme.php`, `theme_css()`)

Light theme ("paper"):

```
--paper:     #f7f6f2   /* neutral off-white, no brown/pink tint */
--ink:       #191817
--ink-soft:  #6b6660   /* muted gray for meta text */
--accent:    #1f3fbf   /* deep signal blue */
--on-accent: #f7f6f2
--line:      rgba(25, 24, 23, .14)
--danger:    #b3261e   /* destructive actions only */
```

Dark theme ("midnight"), still driven by `prefers-color-scheme` (no JS toggle):

```
--paper:     #131211
--ink:       #e8e5dd
--ink-soft:  #9a948a
--accent:    #d9a441   /* warm amber */
--on-accent: #131211
--line:      rgba(232, 229, 221, .16)
--danger:    #e06c60
```

Deleted tokens (must not survive anywhere in `templates/`):
`--paper-2`, `--paper-3`, `--rule`, `--hair`, `--stamp`, `--gold`, `--tape`,
`--shadow`, `--link`, `--link-visited`.

Links: `color: inherit` with underline; hover → `var(--accent)`.
`::selection`: accent background, on-accent text.
Font stacks: `--display: 'Anton', 'Arial Narrow', sans-serif`;
`--mono: 'Space Mono', ui-monospace, …`; `--serif` Georgia stack unchanged;
`--sans` kept for form controls if needed.

## 2. Typography

Three voices:

- **Anton** (self-hosted, uppercase, tight leading, `clamp()` sizing) —
  `.nameplate` (~`clamp(2.5rem, 8vw, 4.5rem)`), section heads, gate title,
  big stat numerals.
- **Space Mono** 400/700 (self-hosted) — kickers, eyebrows/section labels,
  entry meta, tabs, buttons, stats labels, colophon, inputs. Small
  (~.68–.78rem), uppercase, letter-spacing .12–.15em. Section labels get the
  contraculto `■ ` accent-square `::before`.
- **System serif** (Georgia stack) — entry bodies, `.lede`, `.epigraph`.
  The calm reading voice.

## 3. De-skeuomorph deletions

- Ruled-paper `repeating-linear-gradient` body background → flat `--paper`.
- Tape strips (`.sheet::before`, `.gate-card::before`) → removed.
- `.sheet` / `.gate-card`: flat blocks, 2px solid `--ink` border, no shadow,
  no border-radius.
- `.lede::first-letter` drop cap → removed; `.lede` is a plain larger serif
  paragraph.
- Masthead: `3px double` border → `2px solid var(--ink)`.
- `.tabs`/`.tab`: folder-tab styling (rounded tops, layered paper) → flat
  mono nav row; active tab gets a 2px `--accent` bottom border. Element IDs
  (`tab-posts`, …) unchanged.
- All offset-shadow/translate hover physics → hover = accent background with
  on-accent text, or accent border, per component.
- `.epigraph::before` gold ❧ → removed; epigraph stays as quiet italic serif
  in `--ink-soft`. Epigraph copy unchanged.
- `.entry-body figure img`: remove photo-frame padding/background → plain
  1px `--line` border.
- `.colophon::before` `⁂` → `■ ` in accent (matches section labels).
- Favicon: ink square with accent asterisk glyph (replaces paper ❧).

## 4. Component restyles (public pages)

- **Masthead**: same markup (kicker row, nameplate, handle, epigraph,
  owner-login ghost button) over the new 2px rule; nameplate in Anton
  uppercase; kicker and handle in Space Mono.
- **Stats row** (`home.php`): big Anton numerals with mono labels, between
  hairline top/bottom borders.
- **`.entry`**: keeps date|body grid; meta in mono; hairline `--line`
  separators; drop the meta column's right-border divider; entry body serif.
- **`.visit`** (read-along box): flat 2px ink border, no shadow; link row in
  mono.
- **Buttons**: flat, mono, uppercase, 2px `--ink` border, square corners.
  `.primary` = ink block / paper text. Hover → accent bg + on-accent text.
  `.ghost` = borderless mono, accent on hover. `.danger` = `--danger` outline,
  hover fills `--danger`. `.small` scales down as today.
- **Inputs**: square corners, 1px `--line` border, mono; focus = 2px `--ink`
  outline. Placeholders no longer italic.
- **Login gate**: flat card (2px ink border, no tape, no shadow), Anton
  title, mono labels; error box keeps a `--danger` border, flat.

## 5. Panel (`templates/panel.php`)

- All element IDs, JS hooks and markup structure stay exactly as they are —
  `tests/run.php` asserts on `tab-posts`/`tab-timeline`/`tab-inbox`/
  `tab-people`/`tab-profile`.
- Theme classes (`.tabs`, `.sheet`, `.btn`, `.entry`) pick up the new look
  automatically.
- Panel's own `<style>` block (~line 143 onward) is swept: every reference to
  a deleted token is replaced with the new system; composer, attachment rows,
  timeline/inbox items, people cards and the profile form go flat (no
  shadows, no rounded corners, no tape).

## 6. Fonts pipeline + deploy

- Add to repo: `fonts/anton-latin.woff2`, `fonts/space-mono-latin-400.woff2`,
  `fonts/space-mono-latin-700.woff2` (latin subsets, ~52KB total, downloaded
  once from Google Fonts and committed).
- `theme_head()` reads `global $base_url` (set by `index.php`) and emits
  `@font-face` rules with base-prefixed URLs (`$base_url . 'fonts/…'`), so
  subdirectory deploys and deep pages (`/ap/actor`, `/tags/x`) resolve
  correctly. `font-display: swap`.
- `.htaccess` already serves existing files directly (`!-f` conditions /
  coexistence block only intercepts AP paths) — no router change.
- `scripts/deploy.sh`: add `fonts` to `APP_FILES` so `--check` validates it
  and rsync ships it.
- Tests spin throwaway instances without fonts on disk; `@font-face` URLs are
  just strings in CSS, so nothing breaks.

## 7. Out of scope

- No copy changes (epigraphs, labels, buttons text all stay).
- No PHP logic changes; no new JS; no dark-mode toggle.
- No changes to ActivityPub JSON output.
- No extraction of CSS to a static file (considered, rejected as scope creep).

## 8. Verification

1. `./scripts/test.sh` green (tab IDs, gate flow, posting untouched).
2. Visual check: `php -S 127.0.0.1:8080`, screenshot home, login gate and
   logged-in panel in light + dark (Playwright). No leftover brown, contrast
   sane, fonts actually rendering (Anton visibly condensed).
3. `grep -rn "var(--tape\|--gold\|--stamp\|--paper-2\|--paper-3\|--shadow\|--hair\|--rule\|--link" templates/`
   returns nothing.
4. `./scripts/deploy.sh --check` passes with `fonts` in the manifest.
