# authorize_interaction — design

Date: 2026-11-24
Status: approved (confirm-page option chosen); implemented same day

## Problem

Following someone from a remote Mastodon instance redirects the browser to
the follower's home instance: `GET /authorize_interaction?uri=<target actor>`.
SUFI had no route for it (root cause of the reported 404: the router's
`default:` arm answers 404) — it only *generated* such URLs outbound in
`remote_follow()` (index.php).

## Decisions

1. **Confirm page, one click.** GET renders a small themed page naming the
   target ("Follow @user@server?") with a Follow button; the button POSTs.
   GET never mutates state (no crawler-triggered follows), matching
   Mastodon's own semantics.
2. **Accept both shapes of `uri`:** a profile/actor URL
   (`https://mastodon.art/@Chloeg` — what Mastodon sends) and
   `acct:user@host` / bare `@user@host`.
3. **Unauthenticated arrivals** are stashed in `$_SESSION['interact_return']`
   and sent to the panel login; the login success redirect resumes the
   interaction instead of landing on the panel.
4. **Follows go out by handle, reusing the panel's resolution path**
   (`getUserDetails` → webfinger → signed Follow → `add_following` pending).
   URL targets are converted to a handle at confirm-render time by fetching
   the actor document once with the guarded `getDataFromURl` (HTTPS/private-IP
   guards apply). The POST never fetches an arbitrary URL itself, so the
   endpoint adds no new SSRF surface beyond what handle-following already
   has. `action_users()`' URL branch (known actors only) is unchanged.
5. **Shared send path:** the Follow build/send/store tail of `action_users()`
   is extracted into `deliver_follow()` so the panel and the endpoint cannot
   drift. `action_users` behavior stays byte-identical for all four actions.
6. **Landing:** after a successful POST the user is redirected to
   `{$panel_url}?tab=people`; the panel learns to honor a `?tab=` parameter
   on load (invalid/missing values fall back to the posts tab).

## Flow

- `GET /authorize_interaction?uri=X`
  - missing/empty/invalid `X` → 400 themed error page.
  - not panel-authed → stash `REQUEST_URI` in the session, redirect to the
    panel (login).
  - authed → resolve display handle (`acct:` stripped; URL → one guarded
    fetch for `preferredUsername` + URI host[:port], 400 if unresolvable or
    not an actor; handle → format check) → themed confirm page with a CSRF
    token form (hidden `handle` field carrying the resolved handle, form
    action `""`, method post) and a "not now" link to the panel.
- `POST /authorize_interaction`
  - not authed → same login-stash redirect; bad/missing CSRF token → 403;
    bad handle format → 400.
  - resolve handle (`getUserDetails`), deliver signed Follow via
    `deliver_follow()`, redirect to `{$panel_url}?tab=people`.
  - resolution or delivery failure → 400 themed error page with the reason.

## Errors

400 for missing/invalid uri, unresolvable profile, unresolvable handle, or
failed delivery; 403 for bad CSRF; everything else redirects. All error pages
use the shared theme and link back to the panel.

## Testing

New `Authorize interaction` section in `tests/run.php` (suite has a fake
remote with webfinger + `/u/bob` + `/u/carol` actors and an authed-session
jar): missing uri → 400; garbage uri → 400; unauthenticated GET → 302 to the
panel and post-login redirect returns to the interaction; authed GET by
handle → confirm page shows the handle and a CSRF form; authed GET by URL →
page shows the derived `@user@host`; unresolvable URL → 400; POST without
CSRF → 403; POST with CSRF → 302 to `?tab=people`, signed Follow recorded in
the remote delivery log, `Following` row pending. `./scripts/test.sh` green.

## Docs

README API reference gains the endpoint; AGENTS.md navigation table gains a
row.
