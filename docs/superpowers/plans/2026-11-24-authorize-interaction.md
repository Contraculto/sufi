# authorize_interaction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. (Execution note: this plan is being executed inline by the controller with TDD, per user instruction to complete the work autonomously.)

**Goal:** Implement Mastodon-compatible `GET/POST /authorize_interaction?uri=...` so following someone from a remote instance lands on a SUFI confirm page instead of a 404.

**Architecture:** A new routed handler in `index.php` renders a themed confirm page (GET) and sends a signed Follow via a `deliver_follow()` helper extracted from `action_users()` (POST). Unauthenticated arrivals are stashed in the session and resumed after panel login; success lands on `{$panel_url}?tab=people`, which the panel learns to honor.

**Tech Stack:** PHP 8.1 (no framework), shared theme in `templates/_theme.php`, test suite `tests/run.php` with a fake remote fixture. Spec: `docs/superpowers/specs/2026-11-24-authorize-interaction-design.md`.

**Key facts about the codebase:**
- Router: `switch` on path in `index.php`; `case "/ap/follow": remote_follow(); break;` is where the new case goes.
- `action_users()` (index.php ~1058) resolves handles via `getUserDetails()` (sys/delivery.php:367 → webfinger) and sends via `sendMessageToSingle()`; its Follow tail is what `deliver_follow()` extracts.
- `getDataFromURl()` (sys/delivery.php:358) is the guarded JSON fetcher (HTTPS/private-IP guards; static per-request cache).
- Panel auth is session-based (`panel_auth()`, index.php:1890); login lives in `case "/sufi/"`; CSRF pattern for forms: `name="csrf_token" value="<?= ... $_SESSION['csrf_token'] ... ?>"` checked with `hash_equals` (see the login POST, index.php ~214-216).
- The test suite's fake remote (tests/remote.php) serves `/.well-known/webfinger` (any `acct:name@…` → that name) and actor docs at `/u/bob`, `/u/carol` (inbox `http://host/inbox`, logged to `$rlog`). Suite helpers: `http()`, `post()`, `json_body()`, `form_token($html)` (extracts `name="csrf_token" value="..."`), `db()`. Login test pattern with a cookie jar at tests/run.php:388-408. `$hostport = host:port` of the remote is already computed in the suite.
- The shared theme provides `.masthead`, `.kicker`, `.nameplate`, `.handle`, `.btn`, `.btn.primary`, `.btn.ghost`, `.btn.small`, `.ledger`, `.colophon` (see `tag_page()` in index.php for a page built from them, and `Templates\theme_head($title)` in templates/_theme.php).

---

### Task 1: Backend endpoint + tests

**Files:**
- Modify: `index.php` (router case after `case "/ap/follow"`, new functions after `remote_follow()`, Follow tail of `action_users()`, login redirect at ~line 218)
- Test: `tests/run.php` (new `Authorize interaction` section, inserted before the prefixed-instance section)

- [ ] **Step 1: Write the failing test**

In `tests/run.php`, insert this section immediately before the prefixed-instance section (anchor: the `expect('prefix boundary', ...)` line belongs to that section; insert above its section header):

```php
// ── authorize interaction ────────────────────────────────────────────────────

section('Authorize interaction');

$interact = "{$instance}/authorize_interaction";

expect('interaction without uri', 400, http('GET', $interact));
expect('interaction garbage uri', 400, http('GET', "{$interact}?uri=hello"));

// Unauthenticated arrivals go to the panel login and come back after it.
$ijar = '';
$r = expect('interaction login redirect', 302,
    http('GET', "{$interact}?uri=" . urlencode('@bob@' . $hostport), null, [], $ijar));
check('interaction redirects to panel', true, str_contains(implode(',', $r[1]['location'] ?? []), '/sufi/'));

$r = expect('interaction login form', 200, http('GET', "{$instance}/sufi/", null, [], $ijar));
$icsrf = form_token($r[2]);
$r = expect('interaction login', 302,
    post("{$instance}/sufi/", ['password' => 'testpass', 'csrf_token' => $icsrf], $ijar));
check('login returns to interaction', true, str_contains(implode(',', $r[1]['location'] ?? []), '/authorize_interaction?uri='));

// Authenticated arrivals get a confirm page naming the target.
$r = expect('interaction confirm by handle', 200,
    http('GET', "{$interact}?uri=" . urlencode('@bob@' . $hostport), null, [], $ijar));
check('confirm shows handle', true, str_contains($r[2], '@bob@' . $hostport));
check('confirm has follow form', true,
    str_contains($r[2], 'name="csrf_token"') && str_contains($r[2], 'name="handle"'));
$icsrf = form_token($r[2]);

$r = expect('interaction confirm by url', 200,
    http('GET', "{$interact}?uri=" . urlencode("{$remote}/u/carol"), null, [], $ijar));
check('confirm resolves url to handle', true, str_contains($r[2], '@carol@' . $hostport));

expect('interaction unresolvable url', 400,
    http('GET', "{$interact}?uri=" . urlencode("{$remote}/u/nobody"), null, [], $ijar));

// The follow itself requires the session token.
$r = http('POST', $interact, http_build_query(['handle' => '@bob@' . $hostport]),
    ['Content-Type: application/x-www-form-urlencoded'], $ijar);
check('interaction post without csrf refused', 403, $r[0]);

$log_before = (string) file_get_contents($rlog);
$r = expect('interaction follow', 302,
    post($interact, ['csrf_token' => $icsrf, 'handle' => '@bob@' . $hostport], $ijar));
check('follow lands on people tab', true, str_contains(implode(',', $r[1]['location'] ?? []), 'tab=people'));
$log_after = (string) file_get_contents($rlog);
check('follow delivered to remote', true,
    str_contains($log_after, '"type":"Follow"') && strlen($log_after) > strlen($log_before));
check('follow recorded', true,
    count(db($db_path, 'SELECT * FROM Following WHERE actor_uri = ?', ["{$remote}/u/bob"])) >= 1);
```

- [ ] **Step 2: Run the suite to verify it fails**

Run: `./scripts/test.sh`
Expected: FAIL — `interaction without uri` and friends fail with 404 (no route yet).

- [ ] **Step 3: Implement the backend in `index.php`**

1. Router case — add after `case "/ap/follow": remote_follow(); break;`:

```php
    case "/authorize_interaction":
        authorize_interaction();
        break;
```

2. Add `deliver_follow()` immediately before `function action_users() {`:

```php
// Send a signed Follow to a resolved actor and record it as pending.
function deliver_follow(string $profileUrl, string $profileInbox, array $profileData): void {
    global $base_url, $db_handler;

    $message = [
        "@context" => "https://www.w3.org/ns/activitystreams",
        "id"       => "{$base_url}" . uuid(),
        "type"     => "Follow",
        "actor"    => "{$base_url}ap/actor",
        "object"   => $profileUrl,
    ];

    sendMessageToSingle($profileInbox, $message);
    System\Database\add_following($db_handler, $profileUrl, $profileInbox, $profileData, $message['id'], 'pending');
}
```

3. Rewire `action_users()`'s Follow branch to use it. Replace this part of `action_users()` (the `$guid`/`$actor_url` build, the four-branch `$message` if-chain, the trailing `sendMessageToSingle` + four-branch DB ops — keep the resolution block above and the final `echo`):

```php
    if ($action === "Follow") {
        deliver_follow($profileUrl, $profileInbox, getDataFromURl($profileUrl) ?? []);
        echo htmlspecialchars("{$action} sent to {$user}");
        return;
    }

    $guid      = uuid();
    $actor_url = "{$base_url}ap/actor";

    if ($action === "Unfollow") {
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "{$base_url}{$guid}",
            "type"     => "Undo",
            "actor"    => $actor_url,
            "object"   => ["type" => "Follow", "actor" => $actor_url, "object" => $profileUrl],
        ];
    } elseif ($action === "Block") {
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "{$base_url}{$guid}",
            "type"     => "Block",
            "actor"    => $actor_url,
            "object"   => $profileUrl,
            "to"       => $profileUrl,
        ];
    } else {
        $message = [
            "@context" => "https://www.w3.org/ns/activitystreams",
            "id"       => "{$base_url}{$guid}",
            "type"     => "Undo",
            "actor"    => $actor_url,
            "object"   => ["type" => "Block", "actor" => $actor_url, "object" => $profileUrl],
        ];
    }

    sendMessageToSingle($profileInbox, $message);

    if ($action === "Unfollow") {
        System\Database\remove_following($db_handler, $profileUrl);
    } elseif ($action === "Block") {
        System\Database\add_block($db_handler, $profileUrl, $profileInbox, $profile_data ?? []);
        System\Database\remove_following($db_handler, $profileUrl);
        System\Database\remove_follower($db_handler, $profileUrl);
    } elseif ($action === "Unblock") {
        System\Database\remove_block($db_handler, $profileUrl);
    }

    echo htmlspecialchars("{$action} sent to {$user}");
```

(The `return` for Follow makes the `elseif ($action === "Follow")` DB branch dead — it is gone. Behavior for Unfollow/Block/Unblock is unchanged.)

4. Add the interaction handlers after `remote_follow()`:

```php
// ════════════════════════════════════════════════════════════════════════════════
// Remote follow handoff (Mastodon-compatible authorize_interaction)
// ════════════════════════════════════════════════════════════════════════════════

/**
 * Turn an authorize_interaction target into a display handle. Accepts
 * acct:user@host, @user@host, or an actor/profile URL (fetched once with
 * the guarded fetcher). Returns [ok, handle, error].
 */
function interact_handle(string $uri) {
    $uri = trim($uri);
    if (str_starts_with($uri, 'acct:')) { $uri = substr($uri, 5); }

    if (preg_match('#^https?://#i', $uri)) {
        $doc  = getDataFromURl($uri);
        $name = is_array($doc) ? ($doc['preferredUsername'] ?? null) : null;
        $host = parse_url($uri, PHP_URL_HOST);
        $port = parse_url($uri, PHP_URL_PORT);
        if (!is_string($name) || $name === '' || !is_string($host) || $host === '') {
            return [false, null, 'Could not resolve that profile.'];
        }
        return [true, "@{$name}@{$host}" . ($port ? ":{$port}" : ''), null];
    }

    if (preg_match('/^@?([^@\s]+)@([^@\s]+)$/', $uri, $m)) {
        return [true, "@{$m[1]}@{$m[2]}", null];
    }

    return [false, null, 'Invalid uri.'];
}

function authorize_interaction() {
    global $username, $server, $panel_url;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') { interact_follow(); return; }

    $uri = $_GET['uri'] ?? null;
    if (!is_string($uri) || trim($uri) === '') {
        interact_error(400, 'Missing uri.');
        return;
    }

    if (!panel_auth()) {
        $_SESSION['interact_return'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . $panel_url);
        die();
    }

    [$ok, $handle, $error] = interact_handle($uri);
    if (!$ok) { interact_error(400, $error); return; }

    require_once __DIR__ . '/templates/_theme.php';
    header('Content-Type: text/html; charset=utf-8');
    Templates\theme_head("follow {$handle} — @{$username}@{$server}");
    ?><body>
<div id="app">
  <header class="masthead">
    <div class="kicker">
      <span>a follow request</span>
      <span><?= date('j F Y') ?></span>
    </div>
    <div class="masthead-row">
      <div>
        <h1 class="nameplate">Follow <?= htmlspecialchars($handle) ?>?</h1>
        <div class="handle">they are asked to approve it</div>
      </div>
      <a class="btn ghost small" href="<?= htmlspecialchars($panel_url) ?>">not now</a>
    </div>
  </header>

  <div class="ledger">
    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? '')) ?>">
      <input type="hidden" name="handle" value="<?= htmlspecialchars($handle) ?>">
      <button class="btn primary" type="submit">send follow</button>
    </form>
  </div>

  <footer class="colophon">
    <span>Everything here is public and federated.</span>
    <span><a href="<?= htmlspecialchars($panel_url) ?>">the notebook</a></span>
  </footer>
</div>
</body>
</html><?php
}

function interact_follow() {
    global $panel_url;

    if (!panel_auth()) {
        $_SESSION['interact_return'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . $panel_url);
        die();
    }

    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        echo 'Bad token';
        return;
    }

    $handle = trim((string) ($_POST['handle'] ?? ''));
    if (!preg_match('/^@?[^@\s]+@[^@\s]+$/', $handle)) {
        interact_error(400, 'Invalid handle.');
        return;
    }

    $details      = getUserDetails($handle);
    $profileInbox = $details['inbox'] ?? null;
    $profileUrl   = $details['profile'] ?? null;
    if (!$profileUrl || !$profileInbox) {
        interact_error(400, 'Could not resolve that handle.');
        return;
    }

    deliver_follow($profileUrl, $profileInbox, getDataFromURl($profileUrl) ?? []);
    header('Location: ' . $panel_url . '?tab=people');
    die();
}

function interact_error(int $status, string $message) {
    global $panel_url;

    require_once __DIR__ . '/templates/_theme.php';
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    Templates\theme_head('follow request');
    ?><body>
<div id="app">
  <header class="masthead">
    <div class="kicker"><span>a follow request</span></div>
    <div class="masthead-row">
      <div>
        <h1 class="nameplate"><?= htmlspecialchars($message) ?></h1>
      </div>
      <a class="btn ghost small" href="<?= htmlspecialchars($panel_url) ?>">back to the notebook</a>
    </div>
  </header>
  <footer class="colophon">
    <span>Nothing was sent.</span>
  </footer>
</div>
</body>
</html><?php
}
```

5. Login resume — in `case "/sufi/"`'s login POST success branch, replace:

```php
                header('Location: ' . $panel_url);
```

(the one inside `if ($csrf && password_matches(...))`, directly after `$_SESSION['seen'] = time();`) with:

```php
                $return = $_SESSION['interact_return'] ?? null;
                unset($_SESSION['interact_return']);
                header('Location: ' . (is_string($return) && $return !== '' ? $return : $panel_url));
```

- [ ] **Step 4: Run the suite to verify it passes**

Run: `./scripts/test.sh`
Expected: PASS — the new `Authorize interaction` section is green and nothing else regressed.

- [ ] **Step 5: Commit**

```bash
git add index.php tests/run.php
git commit -m "feat: Mastodon-compatible authorize_interaction follow handoff"
```

---

### Task 2: Panel ?tab= landing + docs

**Files:**
- Modify: `templates/panel.php` (bottom init)
- Modify: `README.md` (API reference), `AGENTS.md` (navigation table)

- [ ] **Step 1: Honor ?tab= on panel load**

In `templates/panel.php`, replace the final `loadPosts();` (last line before `</script>`) with:

```js
const initialTab = new URLSearchParams(location.search).get('tab');
if (initialTab && loaders[initialTab]) {
    switchTab(initialTab, document.getElementById(`tab-${initialTab}`));
} else {
    loadPosts();
}
```

- [ ] **Step 2: Docs**

- `README.md`: in the API reference, next to the other endpoint docs, add:

```
GET /authorize_interaction?uri=<target>
```

with the text: "Mastodon-compatible follow handoff. Renders a confirm page for the target (a profile URL or `@user@host`); the POST sends a signed Follow and lands on the panel's people tab. Unauthenticated arrivals log in first and are returned here. `uri` is required."

- `AGENTS.md` navigation table, after the "Change routing" row:

```
| Remote follow handoff | `authorize_interaction()` in `index.php` |
```

- [ ] **Step 3: Verify**

Run: `php -l templates/panel.php` then `./scripts/test.sh` (the suite parses the panel JS and covers the login/redirect path; the `?tab=` behavior is JS-only and checked by the existing parse check).

- [ ] **Step 4: Commit**

```bash
git add templates/panel.php README.md AGENTS.md
git commit -m "feat: land on people tab after follow handoff; document authorize_interaction"
```
