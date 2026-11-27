<?php
global $server, $username, $user_data, $panel_url, $base_url, $max_post_chars, $db_handler;
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; font-src 'self'; "
    . "style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; "
    . "form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'");

require_once __DIR__ . '/_theme.php';

if (empty($_SESSION['auth'])):
    \SUFI\Templates\theme_head('sufi — the door');
?><body class="gate">
<div class="gate-card">
  <div class="kicker">
    <span>sufi</span>
    <span><?= date('j M Y') ?></span>
  </div>
  <h1 class="nameplate">sufi</h1>
  <p class="epigraph"><?= htmlspecialchars(\SUFI\Templates\theme_epigraph()) ?></p>
  <form method="post" action="">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <label class="field">
      <span>password</span>
      <input type="password" name="password" autofocus autocomplete="current-password">
    </label>
    <?php if (isset($_GET['error'])): ?>
    <p class="err">that is not the word. try again.</p>
    <?php endif; ?>
    <button class="btn primary" type="submit">knock</button>
  </form>
  <p class="note center" style="margin-top:1.1rem">One person runs this instance.<br>
    If that person is you, you know the word.</p>
</div>
</body>
</html>
<?php
return;
endif;

$post_count      = \SUFI\System\Database\count_posts($db_handler);
$follower_count  = \SUFI\System\Database\count_followers($db_handler);
$following_count = \SUFI\System\Database\count_following($db_handler);

\SUFI\Templates\theme_head('@' . $username . '@' . $server);
?><body>
<div id="app">
  <header class="masthead">
    <div class="kicker">
      <span>single-user fediverse</span>
      <span><?= date('l · j F Y') ?></span>
    </div>
    <div class="masthead-row">
      <div>
        <div class="nameplate"><a href="<?= htmlspecialchars($base_url) ?>">sufi</a></div>
        <div class="handle">@<?= htmlspecialchars($username) ?>@<?= htmlspecialchars($server) ?>
          · <?= $post_count ?> <?= $post_count === 1 ? 'entry' : 'entries' ?>
          · <?= $following_count ?> following
          · <?= $follower_count ?> <?= $follower_count === 1 ? 'follower' : 'followers' ?>
        </div>
      </div>
      <a class="btn ghost small" href="?logout=1">logout</a>
    </div>
    <p class="epigraph"><?= htmlspecialchars(\SUFI\Templates\theme_epigraph()) ?></p>
  </header>

  <section id="compose">
    <div class="eyebrow">new entry</div>
    <div class="sheet">
      <textarea id="content" placeholder="What happened, what are you thinking…"
                oninput="countChars()" aria-label="entry text"></textarea>
      <div class="compose-foot">
        <input id="reply" type="url" placeholder="replying to a URL (optional)" aria-label="reply to">
        <span class="meter" id="meter" aria-hidden="true"></span>
        <span id="charcount"></span>
        <button class="btn primary" id="post-btn" onclick="post()">post</button>
      </div>
      <div class="attach-grid">
        <label class="field">
          <span>visibility</span>
          <select id="visibility">
            <option value="public">public — anyone</option>
            <option value="unlisted">unlisted — quiet public</option>
            <option value="private">followers only</option>
          </select>
        </label>
        <label class="field">
          <span>content warning</span>
          <input type="text" id="cw" placeholder="spoilers, politics, food…">
        </label>
      </div>
      <div class="attach-row">
        <input type="file" id="image" multiple accept="image/jpeg,image/png,image/gif,image/webp"
               onchange="attachImages()" aria-label="attach images">
        <span class="kind">up to four images</span>
        <span class="note" style="margin-left:auto">⌘ / ctrl + enter</span>
      </div>
      <div id="attach-preview"></div>
    </div>
    <div id="status" role="status" aria-live="polite"></div>
  </section>

  <nav class="tabs" aria-label="panel sections">
    <button class="tab active" id="tab-timeline" onclick="switchTab('timeline', this)">timeline</button>
    <button class="tab" id="tab-posts" onclick="switchTab('posts', this)">entries</button>
    <button class="tab" id="tab-inbox" onclick="switchTab('inbox', this)">inbox</button>
    <button class="tab" id="tab-people" onclick="switchTab('people', this)">people</button>
    <button class="tab" id="tab-profile" onclick="switchTab('profile', this)">profile</button>
  </nav>

  <div id="panel-posts" style="display:none">
    <div class="empty">opening the notebook…</div>
  </div>
  <div id="panel-timeline"><div class="empty">opening the timeline…</div></div>
  <div id="panel-inbox" style="display:none"><div class="empty">opening the notebook…</div></div>
  <div id="panel-people" style="display:none"><div class="empty">opening the notebook…</div></div>
  <div id="panel-profile" style="display:none">
    <div class="eyebrow">the name on the door</div>
    <div class="sheet">
      <div class="stack" style="max-width:26rem">
        <label class="field">
          <span>display name</span>
          <input id="profile-name" value="<?= htmlspecialchars($user_data['name'] ?? $username) ?>">
        </label>
        <label class="field">
          <span>bio</span>
          <textarea id="profile-summary" rows="4"><?= htmlspecialchars($user_data['summary'] ?? '') ?></textarea>
        </label>
        <label class="field">
          <span>avatar</span>
          <div id="avatar-row">
            <img id="avatar-preview" src="<?= htmlspecialchars($user_data['icon'] ?? '') ?>" alt="avatar preview"<?= ($user_data['icon'] ?? '') === '' ? ' hidden' : '' ?>>
            <input id="profile-icon" type="url" value="<?= htmlspecialchars($user_data['icon'] ?? '') ?>" placeholder="https://…" oninput="avatarPreview()">
          </div>
        </label>
        <label class="field">
          <span>or upload one</span>
          <input type="file" id="avatar-file" accept="image/jpeg,image/png,image/gif,image/webp" onchange="uploadAvatar()">
        </label>
        <div><button class="btn primary" onclick="saveProfile()">save profile</button></div>
      </div>
    </div>
  </div>

  <footer class="colophon">
    <span>SUFI — single user fediverse</span>
    <span>no trackers · no build step · no dependencies</span>
  </footer>
</div>

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
.attach-row input[type=file],
#avatar-file {
    font: .68rem/1.5 var(--mono);
    color: var(--ink-soft);
    max-width: 100%;
}
.attach-row input[type=file]::file-selector-button,
#avatar-file::file-selector-button {
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
.attach-row input[type=file]::file-selector-button:hover,
#avatar-file::file-selector-button:hover {
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
#avatar-row { display: flex; align-items: center; gap: .6rem; }
#avatar-row input[type=url] { flex: 1; }
#avatar-preview {
    width: 3.2rem;
    height: 3.2rem;
    object-fit: cover;
    border: 1px solid var(--line);
    flex: none;
}
</style>

<script>
const BASE = <?= json_encode(rtrim($base_url, '/')) ?>;
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
const MAX_CHARS = <?= (int) ($max_post_chars ?? 500) ?>;
let attachments = [];

// Remote actors control their own names and URLs: always escape before injecting.
const esc = s => String(s ?? '').replace(/[&<>"']/g,
    c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// Escape first, then decorate the resulting safe text.
function rich(text) {
    let out = esc(text);
    out = out.replace(/(https?:\/\/[^\s<]+)/g,
        url => `<a href="${url}" target="_blank" rel="noopener noreferrer nofollow">${url}</a>`);
    out = out.replace(/(^|[\s(])(#[\p{L}\p{N}_]{1,64})/gu, (m, pre, tag) =>
        `${pre}<a class="hashtag" href="${BASE}/tags/${encodeURIComponent(tag.slice(1).toLowerCase())}">${tag}</a>`);
    return out;
}

function ago(value) {
    const then = parseWhen(value);
    if (Number.isNaN(then)) return String(value || '').slice(0, 10);
    const secs = Math.max(0, (Date.now() - then) / 1000);
    if (secs < 90) return 'just now';
    const mins = Math.round(secs / 60);
    if (mins < 60) return `${mins} min ago`;
    const hours = Math.round(mins / 60);
    if (hours < 24) return `${hours} ${hours === 1 ? 'hour' : 'hours'} ago`;
    const days = Math.round(hours / 24);
    if (days < 30) return `${days} ${days === 1 ? 'day' : 'days'} ago`;
    return new Date(then).toLocaleDateString(undefined,
        { day: 'numeric', month: 'short', year: 'numeric' });
}

// SQLite datetimes are UTC but carry no suffix; ActivityStreams ones do.
function parseWhen(value) {
    let text = String(value || '').trim();
    if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(text) && !/(Z|[+-]\d{2}:?\d{2})$/.test(text)) {
        text = text.replace(' ', 'T') + 'Z';
    }
    return Date.parse(text);
}

function fullDate(value) {
    const then = parseWhen(value);
    return Number.isNaN(then) ? '' : new Date(then).toLocaleString();
}

function setStatus(msg, ok = true) {
    const el = document.getElementById('status');
    el.textContent = msg;
    el.style.color = ok ? '' : 'var(--danger)';
    if (msg) setTimeout(() => el.textContent = '', 3000);
}

async function get(path) {
    const r = await fetch(`${BASE}${path}`, { credentials: 'same-origin' });
    if (r.status === 401) { location.href = <?= json_encode($panel_url) ?>; return null; }
    return r.json();
}

async function api(path, data) {
    const r = await fetch(`${BASE}${path}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': CSRF_TOKEN
        },
        body: new URLSearchParams(data)
    });
    if (r.status === 401) { location.href = <?= json_encode($panel_url) ?>; }
    return r;
}

const METER_BLOCKS = 25;

function countChars() {
    const n = document.getElementById('content').value.length;
    const meter = document.getElementById('meter');
    const filled = Math.min(METER_BLOCKS, Math.ceil(n / MAX_CHARS * METER_BLOCKS));
    meter.innerHTML = Array.from({ length: METER_BLOCKS },
        (_, i) => `<i class="${i < filled ? 'on' : ''}"></i>`).join('');
    meter.classList.toggle('over', n > MAX_CHARS);
    const el = document.getElementById('charcount');
    el.textContent = n > 0 ? `${n}/${MAX_CHARS}` : '';
    el.style.color = n > MAX_CHARS ? 'var(--danger)' : '';
}

async function attachImages() {
    const input = document.getElementById('image');

    for (const file of input.files) {
        if (attachments.length >= 4) { setStatus('four images is the limit', false); break; }

        const form = new FormData();
        form.append('file', file);
        setStatus('uploading…');

        const r = await fetch(`${BASE}/ap/upload`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
            body: form
        });
        if (!r.ok) { setStatus('upload failed: ' + await r.text(), false); continue; }

        const media = await r.json();
        attachments.push({ url: media.url, thumb: media.thumb || media.url, description: '' });
    }

    input.value = '';
    renderAttachments();
    setStatus('');
}

function renderAttachments() {
    const box = document.getElementById('attach-preview');
    box.innerHTML = attachments.map((a, i) => `
        <div class="attach-item">
            <img src="${esc(a.thumb)}" alt="">
            <input type="text" placeholder="alt text" value="${esc(a.description)}"
                   aria-label="alt text" oninput="attachments[${i}].description=this.value">
            <button class="btn ghost small" onclick="removeAttachment(${i})">remove</button>
        </div>`).join('');
}

function removeAttachment(index) {
    attachments.splice(index, 1);
    renderAttachments();
}

function clearAttachments() {
    attachments = [];
    document.getElementById('image').value = '';
    document.getElementById('attach-preview').innerHTML = '';
}

async function post() {
    const content  = document.getElementById('content').value.trim();
    const in_reply = document.getElementById('reply').value.trim();
    if (!content && !attachments.length) return;
    if (content.length > MAX_CHARS) { setStatus('too long — trim it down', false); return; }
    const btn = document.getElementById('post-btn');
    btn.disabled = true;
    setStatus('posting…');
    const visibility = document.getElementById('visibility').value;
    const spoiler    = document.getElementById('cw').value.trim();
    const data = { content, visibility };
    if (in_reply) data.in_reply_to = in_reply;
    if (spoiler) data.spoiler_text = spoiler;
    if (attachments.length) {
        data.attachments = JSON.stringify(attachments.map(a => ({
            url: a.url, description: a.description
        })));
    }
    const r = await api('/ap/post', data);
    btn.disabled = false;
    if (r.ok) {
        document.getElementById('content').value = '';
        document.getElementById('reply').value = '';
        document.getElementById('cw').value = '';
        document.getElementById('visibility').value = 'public';
        clearAttachments();
        countChars();
        setStatus('posted');
        loadPosts();
    } else {
        setStatus('could not post that', false);
    }
}

async function loadPosts() {
    const panel = document.getElementById('panel-posts');
    const posts = await get('/ap/post');
    if (!posts || !posts.length) {
        panel.innerHTML = '<div class="empty">The notebook is empty. Write the first entry.</div>';
        return;
    }
    panel.innerHTML = posts.map(p => {
        const guid  = p.guid;
        const vis   = p.visibility && p.visibility !== 'public'
            ? `<span class="kind">${esc(p.visibility)}</span>` : '';
        const cw    = p.summary ? `<span class="kind">cw · ${esc(p.summary)}</span>` : '';
        const reply = p.in_reply ? '<span class="kind">↳ reply</span>' : '';
        return `<article class="entry" id="post-${guid}">
            <div class="entry-meta">
                <time datetime="${esc(p.published || '')}" title="${esc(fullDate(p.published))}">${esc(ago(p.published))}</time>
                ${vis}${cw}${reply}
            </div>
            <div class="entry-body">
                <div class="post-text">${rich(p.content)}</div>
                <div class="entry-actions">
                    <button class="btn ghost small" onclick="startEdit('${guid}')">edit</button>
                    <button class="btn danger small" onclick="deletePost('${guid}')">delete</button>
                </div>
                <div class="entry-edit" id="edit-${guid}" style="display:none">
                    <textarea rows="3">${esc(p.content)}</textarea>
                    <div class="entry-actions">
                        <button class="btn primary small" onclick="saveEdit('${guid}')">save</button>
                        <button class="btn ghost small" onclick="cancelEdit('${guid}')">cancel</button>
                    </div>
                </div>
            </div>
        </article>`;
    }).join('');
}

function startEdit(guid) {
    document.getElementById(`edit-${guid}`).style.display = '';
}
function cancelEdit(guid) {
    document.getElementById(`edit-${guid}`).style.display = 'none';
}
async function saveEdit(guid) {
    const content = document.querySelector(`#edit-${guid} textarea`).value.trim();
    if (!content) return;
    const r = await api(`/ap/post/${guid}`, { action: 'update', content });
    if (r.ok) { setStatus('updated'); loadPosts(); }
    else { setStatus('could not save that', false); }
}
async function deletePost(guid) {
    if (!confirm('Delete this entry? It disappears from other servers too.')) return;
    const r = await api(`/ap/post/${guid}`, { action: 'delete' });
    if (r.ok) { document.getElementById(`post-${guid}`)?.remove(); setStatus('deleted'); }
    else { setStatus('could not delete that', false); }
}

const inboxById = new Map();
let inboxCursor = null;
let inboxUnread = 0;
let timelineCursor = null;

const inboxLabels = { like: 'like', boost: 'boost', reply: 'reply', mention: 'mention', post: 'post', other: 'activity' };
const inboxIcons  = { like: '♥', boost: '↻', reply: '✎', mention: '@', post: '•', other: '•' };

function updateInboxBadge() {
    document.getElementById('tab-inbox').textContent = inboxUnread ? `inbox (${inboxUnread})` : 'inbox';
}

function renderInboxItem(item) {
    const icon   = inboxIcons[item.kind] || '•';
    const label  = inboxLabels[item.kind] || 'activity';
    const actor  = item.actor_uri || '';
    const handle = esc(item.actor_handle || actor.replace(/^https?:\/\//, ''));
    const unread = item.is_read ? '' : `<span class="dot" data-dot="${item.id}" title="unread"></span>`;

    // A post in the inbox can carry its own thread of replies.
    const canNest = item.has_children && item.children;
    const boxId   = `replies-0-${item.id}`;

    const buttons = [];
    if (item.kind === 'mention' || item.kind === 'reply') {
        buttons.push(`<button class="btn ghost small" data-reply="${esc(item.object_uri || '')}">reply</button>`);
    }
    if (!item.is_read) buttons.push(`<button class="btn ghost small" data-read="${item.id}">mark read</button>`);
    buttons.push(`<button class="btn ghost small" data-delete="${item.id}">delete</button>`);
    if (item.object_uri) {
        buttons.push(`<a class="btn ghost small" href="${esc(item.object_uri)}" target="_blank" rel="noopener noreferrer">open</a>`);
    }
    if (canNest) {
        buttons.push(`<button class="btn ghost small" data-group-toggle="${boxId}">replies (${item.children.count})</button>`);
    }

    // Likes and boosts carry no text of their own; say what happened.
    const quiet = { like: 'liked one of your entries.', boost: 'boosted one of your entries.' };
    const body = item.preview
        ? `<div class="letter-body">${rich(item.preview)}</div>`
        : (quiet[item.kind] ? `<div class="letter-body muted">${esc(quiet[item.kind])}</div>` : '');

    const nested = canNest
        ? `<div class="thread group-thread" id="${boxId}" style="display:none">`
            + item.children.replies.map(r => renderInboxReply(r, 0)).join('')
        + `</div>`
        : '';
    const threadUnread = canNest && !item.children.is_read
        ? `<span class="dot" title="unread replies"></span>`
        : '';

    return `<article class="letter${item.is_read ? '' : ' unread'}" id="inbox-${item.id}">
        <div class="letter-head">
            <span class="stamp" aria-hidden="true">${icon} ${esc(label)}</span>
            <a class="who" href="${esc(actor)}" target="_blank" rel="noopener noreferrer">${handle}</a>
            <time datetime="${esc(item.created_at || '')}" title="${esc(fullDate(item.created_at))}">${esc(ago(item.created_at))}</time>
            ${unread}${threadUnread}
        </div>
        ${body}
        <div class="letter-actions">${buttons.join(' ')}</div>
        ${nested}
    </article>`;
}

function renderInboxEntry(entry) {
    return entry.type === 'group'
        ? renderInboxGroup(entry)
        : renderInboxItem(entry.item);
}

// One line of context for a timeline entry: what it answers or boosts.
function timelineContext(item) {
    const host = uri => esc(String(uri || '').replace(/^https?:\/\//, '').split('/')[0]);
    const parent = item.parent;

    if (item.kind === 'reply' && item.reply_to) {
        const excerpt = parent && parent.local ? parent.content : (parent && parent.preview);
        const target = excerpt
            ? `<span class="group-parent">${groupParentExcerpt(excerpt)}</span>`
            : `<a href="${esc(item.reply_to)}" target="_blank" rel="noopener noreferrer">${host(item.reply_to)}</a>`;
        return `<div class="letter-body muted">↳ replying to ${target}</div>`;
    }
    if (item.kind === 'boost' && item.object_uri) {
        return `<div class="letter-body muted">↻ boosted a post from `
            + `<a href="${esc(item.object_uri)}" target="_blank" rel="noopener noreferrer">${host(item.object_uri)}</a></div>`;
    }
    return '';
}

// Timeline cards are flat: every post and reply keeps its own text.
function renderTimelineItem(item) {
    const actor   = item.actor_uri || '';
    const handle  = esc(item.actor_handle || actor.replace(/^https?:\/\//, ''));
    const icon    = inboxIcons[item.kind] || '•';
    const label   = inboxLabels[item.kind] || 'post';

    const buttons = [];
    if (item.kind === 'reply' || item.kind === 'mention') {
        buttons.push(`<button class="btn ghost small" data-reply="${esc(item.object_uri || '')}">reply</button>`);
    }
    buttons.push(`<button class="btn ghost small" data-delete="${item.id}">hide</button>`);
    if (item.object_uri) {
        buttons.push(`<a class="btn ghost small" href="${esc(item.object_uri)}" target="_blank" rel="noopener noreferrer">open</a>`);
    }

    return `<article class="letter timeline" id="inbox-${item.id}">
        <div class="letter-head">
            <span class="stamp" aria-hidden="true">${icon} ${esc(label)}</span>
            <a class="who" href="${esc(actor)}" target="_blank" rel="noopener noreferrer">${handle}</a>
            <time datetime="${esc(item.created_at || '')}" title="${esc(fullDate(item.created_at))}">${esc(ago(item.created_at))}</time>
        </div>
        ${timelineContext(item)}
        ${item.preview ? `<div class="letter-body">${rich(item.preview)}</div>` : ''}
        <div class="letter-actions">${buttons.join(' ')}</div>
    </article>`;
}

// A folded set of replies to one parent. All nesting ships inside the
// payload: expanding is pure show/hide, no fetching.
function renderInboxGroup(group, depth = 0) {
    const newest = group.replies[group.replies.length - 1];
    // Skip replies already rendered on a previous page of this view.
    if (document.getElementById('inbox-g' + newest.id)) return '';
    const unread = group.is_read ? '' : `<span class="dot" title="unread"></span>`;
    const boxId  = `replies-${depth}-${newest.id}`;
    const host   = esc(String(group.parent_uri).replace(/^https?:\/\//, '').split('/')[0]);

    const parent = group.parent && group.parent.local
        ? `<div class="letter-body group-parent">${groupParentExcerpt(group.parent.content)}</div>`
        : `<div class="letter-body muted">replying to <a href="${esc(group.parent_uri)}" target="_blank" rel="noopener noreferrer">${host}</a></div>`;

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
    // Skip replies already rendered on a previous page of this view.
    if (document.getElementById('inbox-' + reply.id)) return '';
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
    // A thread attached to an item card leaves only its toggle behind.
    document.querySelectorAll('.letter:not(.group) > .group-thread').forEach(t => {
        if (t.querySelector('.letter.reply')) return;
        document.querySelector(`[data-group-toggle="${t.id}"]`)?.remove();
        t.remove();
    });
}

async function loadInbox(reset = true) {
    const panel = document.getElementById('panel-inbox');

    if (reset) {
        inboxById.clear();
        inboxCursor = null;
        panel.innerHTML = '<div class="empty">opening the notebook…</div>';
    }

    let url = '/ap/inbox?limit=20';
    if (reset) url += '&mark_read=1';
    if (inboxCursor) url += '&before_id=' + inboxCursor;

    const page = await get(url);
    if (!page) return;

    inboxUnread = page.unread;
    updateInboxBadge();

    for (const entry of page.entries) {
        if (entry.type === 'item') {
            inboxById.set(entry.item.id, entry.item);
            if (entry.item.children) {
                for (const reply of entry.item.children.replies) indexReplyTree(reply);
            }
            continue;
        }
        for (const reply of entry.replies) indexReplyTree(reply);
    }

    const html = page.entries.map(renderInboxEntry).join('');

    if (reset) {
        panel.innerHTML = '<div class="inbox-toolbar">'
            + '<button class="btn ghost small" onclick="clearReadInbox()">clear read</button>'
            + (inboxUnread ? '<button class="btn ghost small" onclick="markAllRead()">mark all read</button>' : '')
            + '</div>'
            + (page.entries.length ? '' : '<div class="empty">Nothing addressed to you yet. Mentions and replies to your posts land here.</div>')
            + html;
    } else {
        document.getElementById('inbox-more')?.remove();
        panel.insertAdjacentHTML('beforeend', html);
        dropEmptyGroups();
    }

    inboxCursor = page.next_before_id;
    if (inboxCursor) {
        panel.insertAdjacentHTML('beforeend',
            '<button id="inbox-more" class="btn ghost small" style="margin-top:.75rem">load more</button>');
    }

    panel.querySelectorAll('[data-reply]').forEach(b => {
        if (b.dataset.wired) return;
        b.dataset.wired = '1';
        b.addEventListener('click', () => replyTo(b.dataset.reply));
    });
    panel.querySelectorAll('[data-group-toggle]').forEach(b => {
        if (b.dataset.wired) return;
        b.dataset.wired = '1';
        b.addEventListener('click', () => {
            const box = document.getElementById(b.dataset.groupToggle);
            if (box) box.style.display = box.style.display === 'none' ? '' : 'none';
        });
    });
    panel.querySelectorAll('[data-read]').forEach(b => {
        if (b.dataset.wired) return;
        b.dataset.wired = '1';
        b.addEventListener('click', () => markRead(Number(b.dataset.read)));
    });
    panel.querySelectorAll('[data-delete]').forEach(b => {
        if (b.dataset.wired) return;
        b.dataset.wired = '1';
        b.addEventListener('click', () => deleteInboxItem(Number(b.dataset.delete)));
    });

    document.getElementById('inbox-more')?.addEventListener('click', () => loadInbox(false));
}

async function loadTimeline(reset = true) {
    const panel = document.getElementById('panel-timeline');

    if (reset) {
        timelineCursor = null;
        panel.innerHTML = '<div class="empty">opening the timeline…</div>';
    }

    let url = '/ap/timeline?limit=40';
    if (timelineCursor) url += '&before_id=' + timelineCursor;

    const page = await get(url);
    if (!page) return;

    const html = page.entries.map(renderTimelineItem).join('');

    if (reset) {
        panel.innerHTML = (page.entries.length ? '' : '<div class="empty">Nothing here yet. Follow people in the people tab and what they post will collect here.</div>') + html;
    } else {
        document.getElementById('timeline-more')?.remove();
        panel.insertAdjacentHTML('beforeend', html);
    }

    timelineCursor = page.next_before_id;
    if (timelineCursor) {
        panel.insertAdjacentHTML('beforeend',
            '<button id="timeline-more" class="btn ghost small" style="margin-top:.75rem">load more</button>');
    }

    panel.querySelectorAll('[data-reply]').forEach(b => {
        if (b.dataset.wired) return;
        b.dataset.wired = '1';
        b.addEventListener('click', () => replyTo(b.dataset.reply));
    });
    panel.querySelectorAll('[data-delete]').forEach(b => {
        if (b.dataset.wired) return;
        b.dataset.wired = '1';
        b.addEventListener('click', () => deleteInboxItem(Number(b.dataset.delete)));
    });

    document.getElementById('timeline-more')?.addEventListener('click', () => loadTimeline(false));
}

async function markRead(id) {
    const r = await api('/ap/inbox/read', { id });
    if (!r.ok) return;
    const body = await r.json();
    inboxUnread = body.unread;
    updateInboxBadge();
    inboxById.get(id).is_read = 1;
    document.querySelector(`[data-dot="${id}"]`)?.remove();
    document.querySelector(`[data-read="${id}"]`)?.remove();
    document.getElementById(`inbox-${id}`)?.classList.remove('unread');
}

async function deleteInboxItem(id) {
    const r = await api('/ap/inbox/delete', { id });
    if (!r.ok) return;
    const body = await r.json();
    inboxUnread = body.unread;
    updateInboxBadge();
    inboxById.delete(id);
    const node  = document.getElementById(`inbox-${id}`);
    const group = node?.closest('.letter.group');
    node?.remove();
    if (group && !group.querySelector('.unread')) {
        group.classList.remove('unread');
        group.querySelector('.dot')?.remove();
    }
    dropEmptyGroups();
    setStatus('deleted');
}

async function clearReadInbox() {
    if (!confirm('Delete every inbox item you have already read?')) return;
    const r = await api('/ap/inbox/delete', { id: 'read' });
    if (!r.ok) return;
    const body = await r.json();
    inboxUnread = body.unread;
    updateInboxBadge();
    inboxById.forEach((item, id) => {
        if (item.is_read) {
            inboxById.delete(id);
            document.getElementById(`inbox-${id}`)?.remove();
        }
    });
    dropEmptyGroups();
    setStatus('cleared read items');
}

async function markAllRead() {
    const r = await api('/ap/inbox/read', { id: 'all' });
    if (!r.ok) return;
    inboxUnread = 0;
    updateInboxBadge();
    inboxById.forEach(i => { i.is_read = 1; });
    document.querySelectorAll('.dot, [data-read]').forEach(el => el.remove());
    document.querySelectorAll('.inbox-toolbar').forEach(el => el.remove());
    document.querySelectorAll('.letter.unread').forEach(el => el.classList.remove('unread'));
    setStatus('inbox marked read');
}

// Plain-text excerpt of a local parent post: strip tags, squeeze space,
// cut, then escape — order matters so entities are never split.
function groupParentExcerpt(html) {
    const text = String(html || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    return esc(text.slice(0, 300));
}

function replyTo(uri) {
    document.getElementById('reply').value = uri;
    switchTab('posts', document.getElementById('tab-posts'));
    document.getElementById('content').focus();
    setStatus('replying — write your reply');
}

function avatarPreview() {
    const url = document.getElementById('profile-icon').value.trim();
    const img = document.getElementById('avatar-preview');
    img.hidden = url === '';
    if (url !== '') { img.src = url; }
}

async function uploadAvatar() {
    const input = document.getElementById('avatar-file');
    const file = input.files[0];
    input.value = '';
    if (!file) { return; }

    const form = new FormData();
    form.append('file', file);
    setStatus('uploading avatar…');

    const r = await fetch(`${BASE}/ap/upload`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': CSRF_TOKEN },
        body: form
    });
    if (!r.ok) { setStatus('avatar upload failed: ' + await r.text(), false); return; }

    const media = await r.json();
    document.getElementById('profile-icon').value = media.thumb || media.url;
    avatarPreview();
    setStatus('avatar uploaded — save the profile to apply it');
}

async function saveProfile() {
    const r = await api('/ap/profile', {
        name: document.getElementById('profile-name').value,
        summary: document.getElementById('profile-summary').value,
        icon: document.getElementById('profile-icon').value
    });
    setStatus(r.ok ? 'profile saved' : 'could not save the profile', r.ok);
}

async function loadPeople() {
    const panel = document.getElementById('panel-people');
    const people = await get('/ap/people');
    if (!people) return;

    const renderList = (items, actions) => {
        if (!items.length) return '<div class="empty">Nobody here yet.</div>';
        return items.map(p => {
            const name = p.name ? `<span class="pname">${esc(p.name)}</span>` : '';
            const btns = (actions || []).map(([label, attr, cls]) =>
                `<button class="btn ${cls} small" data-${attr}="${esc(p.uri)}">${label}</button>`).join('');
            return `<div class="person">
                <span class="person-id"><a class="who" href="${esc(p.url)}" target="_blank" rel="noopener noreferrer">${esc(p.handle)}</a>${name}</span>
                <span class="actions">${btns}</span>
            </div>`;
        }).join('');
    };

    panel.innerHTML = `
        <div class="eyebrow">follow someone</div>
        <div class="row follow-form">
            <input id="follow-input" type="text" placeholder="@user@server.social"
                   style="flex:1;min-width:12rem" aria-label="handle to follow">
            <button class="btn primary" onclick="followUser()">follow</button>
        </div>
        <div class="eyebrow" style="margin-top:1.8rem">following · ${people.following.length}</div>
        <div class="directory">
        ${renderList(people.following, [['unfollow', 'unfollow', 'ghost'], ['block', 'block', 'danger']])}
        </div>
        <div class="eyebrow">followers · ${people.followers.length}</div>
        <div class="directory">
        ${renderList(people.followers, [['block', 'block', 'danger']])}
        </div>
        <div class="eyebrow">blocked · ${people.blocked.length}</div>
        <div class="directory">
        ${renderList(people.blocked, [['unblock', 'unblock', 'ghost']])}
        </div>
    `;

    // URIs live in data attributes, not inline JS, so remote values cannot break out.
    const wire = (attr, fn) => panel.querySelectorAll(`[data-${attr}]`).forEach(btn =>
        btn.addEventListener('click', () => fn(btn.dataset[attr])));
    wire('unfollow', unfollowUser);
    wire('block', blockUser);
    wire('unblock', unblockUser);
}

async function followUser() {
    const user = document.getElementById('follow-input').value.trim();
    if (!user) return;
    const r = await api('/ap/action', { user, action: 'Follow' });
    const msg = await r.text();
    setStatus(msg, r.ok);
    if (r.ok) { document.getElementById('follow-input').value = ''; loadPeople(); }
}

async function unfollowUser(uri) {
    const r = await api('/ap/action', { user: uri, action: 'Unfollow' });
    const msg = await r.text();
    setStatus(msg, r.ok);
    if (r.ok) loadPeople();
}

async function blockUser(uri) {
    if (!confirm('Block this actor? Their posts stop arriving and they are removed from your followers.')) return;
    const r = await api('/ap/action', { user: uri, action: 'Block' });
    const msg = await r.text();
    setStatus(msg, r.ok);
    if (r.ok) loadPeople();
}

async function unblockUser(uri) {
    const r = await api('/ap/action', { user: uri, action: 'Unblock' });
    const msg = await r.text();
    setStatus(msg, r.ok);
    if (r.ok) loadPeople();
}

const loaders = { posts: loadPosts, timeline: () => loadTimeline(true), inbox: () => loadInbox(true), people: loadPeople, profile: () => {} };

function switchTab(name, btn) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    ['posts','timeline','inbox','people','profile'].forEach(t => {
        document.getElementById(`panel-${t}`).style.display = t === name ? '' : 'none';
    });
    loaders[name]();
}

document.getElementById('content').addEventListener('keydown', event => {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
        event.preventDefault();
        post();
    }
});

countChars();
const initialTab = new URLSearchParams(location.search).get('tab');
if (initialTab && loaders[initialTab]) {
    switchTab(initialTab, document.getElementById(`tab-${initialTab}`));
} else {
    loadTimeline(true);
}
</script>
</body>
</html>
