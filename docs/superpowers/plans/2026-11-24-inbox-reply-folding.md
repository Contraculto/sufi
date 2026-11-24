# Inbox Reply Folding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fold incoming replies into expandable per-post groups in the panel inbox instead of showing each reply as its own letter.

**Architecture:** `GET /ap/inbox` (in `index.php`) groups classified `reply` rows by their `inReplyTo` URI server-side and returns a mixed `entries` array (plain items + reply groups with nested child groups, local parent posts resolved from the `Post` table). The panel (`templates/panel.php`) becomes a dumb renderer: one toggle per group, show/hide only, no network fetches.

**Tech Stack:** PHP 8.1 (no framework), SQLite, vanilla JS. Spec: `docs/superpowers/specs/2026-11-24-inbox-reply-folding-design.md`.

**Key facts about the codebase:**
- `$base_url` (global in `index.php`) ends WITH a trailing slash: local posts live at `$base_url . 'ap/post/<guid>'`.
- Inbox rows already carry `object_uri` = the activity object's own id (for a reply: the reply's URI).
- Test auth for GET endpoints: `http('GET', $url, json_encode(['password' => 'testpass']), ['Content-Type: application/json'])`.
- The suite runs sandbox instances: `./scripts/test.sh` (also lints `index.php`).

---

### Task 1: Server-side grouping in `GET /ap/inbox`

**Files:**
- Modify: `index.php:433-478` (`inbox_items()`), add helpers after `inbox_preview()` (`index.php:605-612`)
- Test: `tests/run.php:634-725` (update existing inbox assertions), insert a new folding block after `tests/run.php:725`

- [x] **Step 1: Rewrite the inbox test block (failing test)**

In `tests/run.php`, replace lines 634–641 with:

```php
$r = expect('inbox listing', 200, http('GET', "{$instance}/ap/inbox", $auth_body, $auth_header));
$inbox_page = json_body($r);
check('inbox response has entries and unread', true, isset($inbox_page['entries'], $inbox_page['unread']));
$first = $inbox_page['entries'][0]['item'] ?? [];
check('inbox item has readable handle', '@bob@' . $hostport, $first['actor_handle'] ?? null);
check('inbox item kind is post', 'post', $first['kind'] ?? null);
check('inbox item has preview', true, str_contains((string) ($first['preview'] ?? ''), 'hello from bob'));
check('inbox item starts unread', 0, (int) ($first['is_read'] ?? 1));
```

Replace lines 681–692 with:

```php
$r = expect('inbox after interactions', 200, http('GET', "{$instance}/ap/inbox?limit=10", $auth_body, $auth_header));
$kinds = [];
$folded = null;
foreach (json_body($r)['entries'] as $entry) {
    if ($entry['type'] === 'item') {
        $kinds[$entry['item']['kind']] = true;
    } elseif (($entry['parent_uri'] ?? '') === $second['object']['id']) {
        $folded = $entry;
    }
}
check('mention classified', true, isset($kinds['mention']));
check('boost classified', true, isset($kinds['boost']));
check('like classified', true, isset($kinds['like']));
check('no top-level reply items', true, !isset($kinds['reply']));
check('reply folded into group', true, $folded !== null);
check('group exposes parent url', $second['object']['id'], $folded['parent_uri'] ?? null);
check('group parent resolved locally', true, (bool) ($folded['parent']['local'] ?? false));
check('group parent guid', basename((string) $second['object']['id']), $folded['parent']['guid'] ?? null);
check('group parent has post content', true, str_contains((string) ($folded['parent']['content'] ?? ''), 'from panel'));
check('group count', 1, (int) ($folded['count'] ?? 0));
```

Replace lines 697–710 with:

```php
// Pagination: walk one entry at a time.
$r = expect('inbox page 1', 200, http('GET', "{$instance}/ap/inbox?limit=1", $auth_body, $auth_header));
$p1 = json_body($r);
check('page holds one entry', 1, count($p1['entries']));
check('page exposes cursor', true, !empty($p1['next_before_id']));
$r = http('GET', "{$instance}/ap/inbox?limit=1&before_id=" . $p1['next_before_id'], $auth_body, $auth_header);
$p2 = json_body($r);
$entry_key = fn(array $e) => $e['type'] === 'item'
    ? 'i' . $e['item']['id']
    : 'g' . $e['replies'][count($e['replies']) - 1]['id'];
check('cursor returns next entry', true, $entry_key($p2['entries'][0]) !== $entry_key($p1['entries'][0]));

// Per-item read, then mark all.
$target_id = (int) $p1['entries'][0]['item']['id'];
$r = post("{$instance}/ap/inbox/read", ['id' => $target_id, 'password' => 'testpass']);
$after_one = json_body($r)['unread'] ?? null;
check('per-item read lowers unread', true, $after_one === $unread_before - 1);
```

Replace lines 712–718 with:

```php
$r = expect('inbox mark all read', 200, http('GET', "{$instance}/ap/inbox?mark_read=1", $auth_body, $auth_header));
$pre_mark = [];
foreach (json_body($r)['entries'] as $e) {
    $pre_mark[] = (int) ($e['type'] === 'item' ? $e['item']['is_read'] : $e['is_read']);
}
check('mark_read returns pre-mark state', true, in_array(0, $pre_mark, true));
check('mark_read response keeps unread count', true, (int) json_body($r)['unread'] >= 0);

$r = http('GET', "{$instance}/ap/inbox", $auth_body, $auth_header);
check('inbox entries read afterwards', 1, (int) (json_body($r)['entries'][0]['item']['is_read'] ?? 0));
check('unread is zero', 0, (int) json_body($r)['unread']);
```

Then insert this new block immediately after line 725 (`check('read inbox cleared', ...)`):

```php
// ── inbox: reply folding ─────────────────────────────────────────────────────

section('Inbox: reply folding');

// Fresh parent post, then four replies: two to the post, one to the first
// reply, one to a remote note that was never in this inbox.
$r = expect('folding parent post', 200, post("{$instance}/ap/post", ['content' => 'parent letter', 'password' => 'testpass']));
$parent_uri = (string) (json_body($r)['object']['id'] ?? '');
$parent_guid = basename($parent_uri);

$mk_reply = function (string $note, string $text, string $in_reply_to) use ($actorU, $remote) {
    return [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => "{$actorU}#creates/{$note}",
        'type'     => 'Create',
        'actor'    => $actorU,
        'object'   => [
            'id'        => "{$remote}/notes/{$note}",
            'type'      => 'Note',
            'content'   => "<p>{$text}</p>",
            'inReplyTo' => $in_reply_to,
        ],
    ];
};

expect('reply fold one', 202, sign_post($inbox, $mk_reply('fold-1', 'first fold', $parent_uri), $key, $keyId));
expect('reply fold two', 202, sign_post($inbox, $mk_reply('fold-2', 'second fold', $parent_uri), $key, $keyId));
expect('reply fold nested', 202, sign_post($inbox, $mk_reply('fold-3', 'nested fold', "{$remote}/notes/fold-1"), $key, $keyId));
expect('reply fold orphan', 202, sign_post($inbox, $mk_reply('fold-4', 'orphan fold', "{$remote}/notes/never-seen"), $key, $keyId));

$r = expect('folded inbox', 200, http('GET', "{$instance}/ap/inbox?limit=20", $auth_body, $auth_header));
$entries = json_body($r)['entries'];
check('a page of only replies folds to two groups', 2, count($entries));
check('no standalone items on reply page', 0, count(array_filter($entries, fn($e) => $e['type'] === 'item')));

$thread = null;
$orphan = null;
foreach ($entries as $e) {
    if ($e['type'] !== 'group') { continue; }
    if ($e['parent_uri'] === $parent_uri) { $thread = $e; }
    if ($e['parent_uri'] === "{$remote}/notes/never-seen") { $orphan = $e; }
}
check('thread group found', true, $thread !== null);
check('thread group count', 2, (int) ($thread['count'] ?? 0));
check('thread replies oldest first', true, str_contains((string) ($thread['replies'][0]['preview'] ?? ''), 'first fold'));
check('thread replies newest last', true, str_contains((string) ($thread['replies'][1]['preview'] ?? ''), 'second fold'));
check('thread parent guid', $parent_guid, $thread['parent']['guid'] ?? null);
check('thread parent content', true, str_contains((string) ($thread['parent']['content'] ?? ''), 'parent letter'));
check('thread group unread', 0, (int) ($thread['is_read'] ?? 1));
check('first reply has children', true, (bool) ($thread['replies'][0]['has_children'] ?? false));
check('nested group reply count', 1, (int) ($thread['replies'][0]['children']['count'] ?? 0));
check('nested reply preview', true, str_contains((string) ($thread['replies'][0]['children']['replies'][0]['preview'] ?? ''), 'nested fold'));
check('second reply has no children', true, empty($thread['replies'][1]['has_children']));
check('orphan group found', true, $orphan !== null);
check('orphan parent is remote', false, (bool) ($orphan['parent']['local'] ?? true));

// Pagination consumes folded rows: limit=2 over four replies gives one page
// of two groups, and the cursor lands on the oldest consumed reply.
$r = expect('fold page one', 200, http('GET', "{$instance}/ap/inbox?unread=1&limit=2", $auth_body, $auth_header));
$page1 = json_body($r);
check('fold page one has two groups', 2, count($page1['entries']));
check('fold page one continues', true, !empty($page1['next_before_id']));
check('cursor is oldest consumed reply', (int) $page1['next_before_id'],
    (int) $page1['entries'][1]['replies'][0]['id']);

$r = expect('fold page two', 200, http('GET', "{$instance}/ap/inbox?unread=1&limit=2&before_id=" . $page1['next_before_id'], $auth_body, $auth_header));
$page2 = json_body($r);
check('fold page two has one group', 1, count($page2['entries']));
check('page two group count', 2, (int) ($page2['entries'][0]['count'] ?? 0));
check('page two carries nested reply', 1, (int) ($page2['entries'][0]['replies'][0]['children']['count'] ?? 0));
check('fold pages exhausted', true, empty($page2['next_before_id']));
```

- [x] **Step 2: Run the suite to verify the inbox tests fail**

Run: `./scripts/test.sh`
Expected: FAIL — the new/updated inbox assertions fail because the response still has `items`, not `entries` (e.g. `inbox response has entries and unread`). Sections before the inbox section must stay green.

- [x] **Step 3: Implement the grouping in `index.php`**

In `inbox_items()` (`index.php:433`), change the global import and the response assembly. Replace this block (lines 433–477):

```php
function inbox_items() {
    global $db_handler;

    if (!panel_auth()) {
        http_response_code(401);
        echo "Unauthorized";
        return;
    }

    $limit  = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $before = isset($_GET['before_id']) ? (int) $_GET['before_id'] : null;
    $unread = System\Database\count_unread_inbox($db_handler);

    $rows = System\Database\get_inbox_items(
        $db_handler, $limit + 1, $before, !empty($_GET['unread'])
    );
    $more = count($rows) > $limit;
    if ($more) { array_pop($rows); }

    $items = [];
    foreach ($rows as $row) {
        $data = json_decode((string) $row['data'], true) ?: [];
        $row['data']     = $data;
        $row['actor_handle'] = ($row['actor_handle'] ?? '') !== ''
            ? $row['actor_handle']
            : actor_handle((string) $row['actor_uri'], []);
        $row['kind']     = inbox_kind((string) $row['type'], $data);
        $row['reply_to'] = inbox_reply_to($data);
        $row['preview']  = inbox_preview($data);
        $items[] = $row;
    }

    $next = ($more && $items) ? (int) $items[count($items) - 1]['id'] : null;

    // Mark after reading, so the panel still sees what was unread.
    if (!empty($_GET['mark_read'])) {
        System\Database\mark_inbox_read($db_handler);
    }

    header('Content-Type: application/json');
    echo json_encode([
        'items'          => $items,
        'next_before_id' => $next,
        'unread'         => $unread,
    ]);
}
```

with:

```php
function inbox_items() {
    global $db_handler, $base_url;

    if (!panel_auth()) {
        http_response_code(401);
        echo "Unauthorized";
        return;
    }

    $limit  = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
    $before = isset($_GET['before_id']) ? (int) $_GET['before_id'] : null;
    $unread = System\Database\count_unread_inbox($db_handler);

    $rows = System\Database\get_inbox_items(
        $db_handler, $limit + 1, $before, !empty($_GET['unread'])
    );
    $more = count($rows) > $limit;
    if ($more) { array_pop($rows); }

    $items = [];
    foreach ($rows as $row) {
        $data = json_decode((string) $row['data'], true) ?: [];
        $row['data']     = $data;
        $row['actor_handle'] = ($row['actor_handle'] ?? '') !== ''
            ? $row['actor_handle']
            : actor_handle((string) $row['actor_uri'], []);
        $row['kind']     = inbox_kind((string) $row['type'], $data);
        $row['reply_to'] = inbox_reply_to($data);
        $row['preview']  = inbox_preview($data);
        $items[] = $row;
    }

    $next = ($more && $items) ? (int) $items[count($items) - 1]['id'] : null;

    // Mark after reading, so the panel still sees what was unread.
    if (!empty($_GET['mark_read'])) {
        System\Database\mark_inbox_read($db_handler);
    }

    header('Content-Type: application/json');
    echo json_encode([
        'entries'        => inbox_group_entries($items, (string) $base_url),
        'next_before_id' => $next,
        'unread'         => $unread,
    ]);
}

/**
 * Fold reply items into groups keyed by the URI they reply to. Everything
 * else stays a top-level item entry. Replies inside a group are oldest
 * first; a group whose parent is itself a reply in this payload nests
 * inside that reply instead of appearing at the top level.
 */
function inbox_group_entries(array $items, string $base) {
    global $db_handler;

    $local_prefix = $base . 'ap/post/';

    $by_parent = [];
    $entries   = [];
    foreach ($items as $item) {
        if (($item['kind'] ?? '') === 'reply' && !empty($item['reply_to'])) {
            $by_parent[$item['reply_to']][] = $item;
        } else {
            $entries[] = ['type' => 'item', 'item' => $item];
        }
    }

    $make_group = function (string $uri, array $list) use ($db_handler, $local_prefix) {
        $list = array_slice(array_reverse($list), 0, 100);  // oldest first, capped
        $read = 1;
        foreach ($list as $r) { $read = $read && (int) $r['is_read']; }
        return [
            'type'       => 'group',
            'parent_uri' => $uri,
            'parent'     => inbox_parent_summary($db_handler, $uri, $local_prefix),
            'count'      => count($list),
            'replies'    => $list,
            'is_read'    => $read ? 1 : 0,
        ];
    };

    // Attach each group whose parent is a reply in this payload underneath
    // that reply; those groups never surface at the top level.
    $nested = [];
    foreach ($by_parent as $uri => $list) {
        foreach ($list as $i => $reply) {
            $child_uri = (string) ($reply['object_uri'] ?? '');
            if ($child_uri !== '' && isset($by_parent[$child_uri])) {
                $by_parent[$uri][$i]['has_children'] = true;
                $by_parent[$uri][$i]['children']     = $make_group($child_uri, $by_parent[$child_uri]);
                $nested[$child_uri] = true;
            }
        }
    }

    foreach ($by_parent as $uri => $list) {
        if (isset($nested[$uri])) { continue; }
        $entries[] = $make_group($uri, $list);
    }

    // Newest activity first, same as the flat list it replaces.
    $newest = function (array $entry) {
        if ($entry['type'] === 'item') { return (int) $entry['item']['id']; }
        $replies = $entry['replies'];
        return (int) $replies[count($replies) - 1]['id'];
    };
    usort($entries, fn($a, $b) => $newest($b) <=> $newest($a));

    return $entries;
}

/**
 * Best-effort summary of what a group of replies is replying to. Local
 * posts resolve from the database; anything remote (or deleted) is just
 * a link — no outbound fetch.
 */
function inbox_parent_summary($db_handler, string $uri, string $local_prefix) {
    if (str_starts_with($uri, $local_prefix)) {
        $guid = substr($uri, strlen($local_prefix));
        $post = System\Database\get_post($db_handler, $guid);
        if ($post) {
            return ['local' => true, 'guid' => $guid, 'content' => (string) $post['content']];
        }
    }
    return ['local' => false];
}
```

- [x] **Step 4: Run the suite to verify it passes**

Run: `./scripts/test.sh`
Expected: PASS — entire suite green, including the new `Inbox: reply folding` section.

- [x] **Step 5: Commit**

```bash
git add index.php tests/run.php
git commit -m "feat: fold inbox replies into expandable per-post groups"
```

---

### Task 2: Panel rendering of groups

**Files:**
- Modify: `templates/panel.php:549-649` (renderers + `loadInbox`), `templates/panel.php:702-735` (delete `toggleThread`), `templates/panel.php:242-282` (CSS)

Note: the panel JS has no automated test coverage in this project (status quo). Verification is `php -l`, a `node --check` syntax pass, and the PHP suite still green.

- [x] **Step 1: Replace the inbox renderers and `loadInbox` mapping**

In `templates/panel.php`:

1. In `renderInboxItem`, delete the thread button line and the now-unused thread div. Replace lines 556–565:

```js
    const buttons = [];
    if (item.reply_to) buttons.push(`<button class="btn ghost small" data-thread="${item.id}">thread</button>`);
    if (item.kind === 'reply' || item.kind === 'mention') {
        buttons.push(`<button class="btn ghost small" data-reply="${esc(item.object_uri || '')}">reply</button>`);
    }
```

with:

```js
    const buttons = [];
    if (item.kind === 'mention') {
        buttons.push(`<button class="btn ghost small" data-reply="${esc(item.object_uri || '')}">reply</button>`);
    }
```

and remove the trailing `<div class="thread" id="thread-${item.id}" style="display:none"></div>` from the returned article (last line of `renderInboxItem`).

2. Add the group renderers right after `renderInboxItem`:

```js
function renderInboxEntry(entry) {
    return entry.type === 'group'
        ? renderInboxGroup(entry)
        : renderInboxItem(entry.item);
}

// A folded set of replies to one parent. All nesting ships inside the
// payload: expanding is pure show/hide, no fetching.
function renderInboxGroup(group, depth = 0) {
    const newest = group.replies[group.replies.length - 1];
    const unread = group.is_read ? '' : `<span class="dot" title="unread"></span>`;
    const boxId  = `replies-${depth}-${newest.id}`;
    const host   = esc(String(group.parent_uri).replace(/^https?:\/\//, '').split('/')[0]);

    const parent = group.parent && group.parent.local
        ? `<div class="letter-body group-parent">${esc(threadBody(group.parent.content)).slice(0, 300)}</div>`
        : `<div class="letter-body muted">replying to ${host}</div>`;

    return `<article class="letter group${group.is_read ? '' : ' unread'}" id="inbox-g${newest.id}">
        <div class="letter-head">
            <span class="stamp" aria-hidden="true">✎ ${group.count} ${group.count === 1 ? 'reply' : 'replies'}</span>
            <time datetime="${esc(newest.created_at || '')}" title="${esc(fullDate(newest.created_at))}">${esc(ago(newest.created_at))}</time>
            ${unread}
        </div>
        ${parent}
        <div class="letter-actions"><button class="btn ghost small" data-group-toggle="${boxId}">replies</button></div>
        <div class="thread group-thread" id="${boxId}" style="display:none">`
            + group.replies.map(r => renderInboxReply(r, depth)).join('')
        + `</div>
    </article>`;
}

function renderInboxReply(reply, depth) {
    const handle = esc(reply.actor_handle || (reply.actor_uri || '').replace(/^https?:\/\//, ''));
    const unread = reply.is_read ? '' : `<span class="dot" data-dot="${reply.id}" title="unread"></span>`;
    const canNest = reply.has_children && depth < 3;
    const boxId    = `replies-${depth + 1}-${reply.id}`;

    const buttons = [
        `<button class="btn ghost small" data-reply="${esc(reply.object_uri || '')}">reply</button>`,
        `<button class="btn ghost small" data-delete="${reply.id}">delete</button>`,
    ];
    if (reply.object_uri) {
        buttons.push(`<a class="btn ghost small" href="${esc(reply.object_uri)}" target="_blank" rel="noopener noreferrer">open</a>`);
    }
    if (canNest) {
        buttons.push(`<button class="btn ghost small" data-group-toggle="${boxId}">replies</button>`);
    }

    const nested = canNest
        ? `<div class="thread group-thread" id="${boxId}" style="display:none">`
            + reply.children.replies.map(r => renderInboxReply(r, depth + 1)).join('')
        + `</div>`
        : '';

    return `<div class="letter reply${reply.is_read ? '' : ' unread'}" id="inbox-${reply.id}">
        <div class="letter-head">
            <a class="who" href="${esc(reply.actor_uri || '')}" target="_blank" rel="noopener noreferrer">${handle}</a>
            <time datetime="${esc(reply.created_at || '')}" title="${esc(fullDate(reply.created_at))}">${esc(ago(reply.created_at))}</time>
            ${unread}
        </div>
        ${reply.preview ? `<div class="letter-body">${rich(reply.preview)}</div>` : ''}
        <div class="letter-actions">${buttons.join(' ')}</div>
        ${nested}
    </div>`;
}
```

3. In `loadInbox`, replace the mapping and empty-check lines:

```js
    for (const item of page.items) inboxById.set(item.id, item);

    const html = page.items.map(renderInboxItem).join('');
```

with:

```js
    for (const entry of page.entries) {
        if (entry.type === 'item') { inboxById.set(entry.item.id, entry.item); continue; }
        for (const reply of entry.replies) indexReplyTree(reply);
    }

    const html = page.entries.map(renderInboxEntry).join('');
```

and in the `reset` branch replace `(page.items.length ? '' : '<div class="empty">No letters. Quiet is fine.</div>')` with `(page.entries.length ? '' : '<div class="empty">No letters. Quiet is fine.</div>')`.

4. Replace the `[data-thread]` wiring block (the `panel.querySelectorAll('[data-thread]')` forEach) with group-toggle wiring:

```js
    panel.querySelectorAll('[data-group-toggle]').forEach(b => {
        if (b.dataset.wired) return;
        b.dataset.wired = '1';
        b.addEventListener('click', () => {
            const box = document.getElementById(b.dataset.groupToggle);
            if (box) box.style.display = box.style.display === 'none' ? '' : 'none';
        });
    });
```

5. Add the tree indexer and empty-group sweeper. Put `indexReplyTree` next to the other inbox helpers (before `loadInbox`):

```js
function indexReplyTree(reply) {
    inboxById.set(reply.id, reply);
    if (reply.children) {
        for (const child of reply.children.replies) indexReplyTree(child);
    }
}

function dropEmptyGroups() {
    document.querySelectorAll('.letter.group').forEach(g => {
        if (!g.querySelector('.letter.reply')) g.remove();
    });
}
```

Call `dropEmptyGroups()` at the end of `deleteInboxItem` (after the element remove) and at the end of `clearReadInbox` (after the `inboxById.forEach` sweep).

6. Delete `toggleThread` (lines 707–735) entirely — the parent content now ships in the payload.

- [x] **Step 2: Add the CSS**

Append to the style block after `.thread-post .kind { ... }` (line 282):

```css
.letter.group { border-left: 3px solid var(--rule); }
.letter.group.unread { border-left-color: var(--stamp); }
.group-parent { color: var(--ink-soft); font-size: .88rem; }
.group-thread { margin-top: .55rem; }
.letter.reply {
    background: transparent;
    border: none;
    border-top: 1px dotted var(--rule);
    box-shadow: none;
    padding: .5rem 0 .4rem;
    margin-bottom: 0;
}
.letter.reply .letter-actions { margin-top: .3rem; }
```

- [x] **Step 3: Syntax checks**

Run: `php -l templates/panel.php && php -l index.php`
Expected: `No syntax errors detected` for both.

Run: `awk '/<script>/{flag=1;next}/<\/script>/{flag=0}flag' templates/panel.php > /tmp/panel-check.js && node --check /tmp/panel-check.js && rm /tmp/panel-check.js`
Expected: no output (syntax OK). If `awk` picks up multiple script blocks, checking their concatenation is still valid here because the template has exactly one.

- [x] **Step 4: Run the full suite**

Run: `./scripts/test.sh`
Expected: PASS (backend unchanged since Task 1; guards against accidental PHP edits).

- [x] **Step 5: Commit**

```bash
git add templates/panel.php
git commit -m "feat: render folded reply groups in the panel inbox"
```

---

### Task 3: End-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Full suite**

Run: `./scripts/test.sh`
Expected: PASS, 0 failures.

- [ ] **Step 2: Manual smoke against real data**

The suite's sandbox instances are throwaway, so the last check is against a live inbox. If a development copy of `sys/sufi.sqlite` with inbox rows is available locally:

```bash
php -S 127.0.0.1:8080
```

Open `http://127.0.0.1:8080/sufi/`, log in, open the inbox tab, and confirm: replies appear as `✎ N replies` cards; local parents show the post text; expanding shows replies oldest-first; nested replies indent; deleting a reply updates the badge. If no dev database exists locally, defer this check to the production deploy (`./scripts/deploy.sh` runs the suite plus a federation smoke check) and verify on the live panel.
