<?php
global $server, $username, $user_data, $panel_url, $base_url, $max_post_chars;
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data: https:; "
    . "style-src 'unsafe-inline'; script-src 'unsafe-inline'; connect-src 'self'; "
    . "form-action 'self'; base-uri 'none'");

if (empty($_SESSION['auth'])):
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>sufi</title>
<style>
:root { --bg: #fff; --surface: #f6f6f6; --border: #e0e0e0; --text: #111;
        --muted: #777; --accent: #1a1a1a; --danger: #c0392b; --radius: 4px; }
@media (prefers-color-scheme: dark) {
    :root { --bg: #0f0f0f; --surface: #1a1a1a; --border: #2a2a2a; --text: #eee;
            --muted: #888; --accent: #eee; }
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, sans-serif;
       display: flex; align-items: center; justify-content: center; min-height: 100vh; }
form { display: flex; flex-direction: column; gap: .6rem; width: 260px; }
input { padding: .4rem .6rem; background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); color: var(--text); font-size: .9rem; }
input:focus { outline: 2px solid var(--accent); border-color: transparent; }
button { background: var(--accent); color: var(--bg); border: none; padding: .4rem 1rem;
         border-radius: var(--radius); cursor: pointer; font-size: .9rem; }
.err { font-size: .8rem; color: var(--danger); }
</style>
</head>
<body>
<form method="post" action="">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
  <input type="password" name="password" placeholder="password" autofocus autocomplete="current-password">
  <?php if (isset($_GET['error'])): ?>
  <p class="err">wrong password</p>
  <?php endif; ?>
  <button type="submit">login</button>
</form>
</body>
</html>
<?php
return;
endif;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@<?= $username ?>@<?= $server ?></title>
<style>
:root {
    --bg:      #fff;
    --surface: #f6f6f6;
    --border:  #e0e0e0;
    --text:    #111;
    --muted:   #777;
    --accent:  #1a1a1a;
    --danger:  #c0392b;
    --radius:  4px;
    --mono:    ui-monospace, "Cascadia Code", "Fira Mono", monospace;
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg:      #0f0f0f;
        --surface: #1a1a1a;
        --border:  #2a2a2a;
        --text:    #eee;
        --muted:   #888;
        --accent:  #eee;
    }
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, sans-serif; }

#app { max-width: 680px; margin: 0 auto; padding: 1rem; }

header { display: flex; justify-content: space-between; align-items: center;
    padding-bottom: .75rem; border-bottom: 1px solid var(--border); margin-bottom: 1.25rem; }
header h1 { font-size: .9rem; font-family: var(--mono); color: var(--muted); font-weight: 400; }
header button { font-size: .8rem; background: none; border: 1px solid var(--border);
    color: var(--muted); padding: .2rem .6rem; cursor: pointer; border-radius: var(--radius); }

#compose { margin-bottom: 1.5rem; }
#compose textarea { width: 100%; min-height: 80px; padding: .6rem .75rem;
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); font: 15px/1.5 var(--mono); resize: vertical; }
#compose textarea:focus { outline: 2px solid var(--accent); border-color: transparent; }
.compose-footer { display: flex; align-items: center; gap: .5rem; margin-top: .4rem; flex-wrap: wrap; }
.compose-footer input { flex: 1; min-width: 160px; padding: .35rem .6rem;
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--muted); font-size: .85rem; }
.compose-footer input:focus { outline: 2px solid var(--accent); border-color: transparent; color: var(--text); }
.compose-footer input::placeholder { color: var(--muted); }
#charcount { font-size: .8rem; color: var(--muted); margin-left: auto; }

button.primary { background: var(--accent); color: var(--bg); border: none;
    padding: .4rem 1rem; border-radius: var(--radius); cursor: pointer; font-size: .9rem; }
button.primary:disabled { opacity: .4; cursor: default; }
button.ghost  { background: none; border: 1px solid var(--border); color: var(--muted);
    padding: .25rem .6rem; border-radius: var(--radius); cursor: pointer; font-size: .8rem; }
button.ghost:hover { color: var(--text); border-color: var(--text); }
button.danger { background: none; border: 1px solid var(--border); color: var(--danger);
    padding: .25rem .6rem; border-radius: var(--radius); cursor: pointer; font-size: .8rem; }

.tabs { display: flex; gap: 0; border-bottom: 1px solid var(--border); margin-bottom: 1rem; }
.tab  { padding: .5rem 1rem; cursor: pointer; font-size: .9rem; color: var(--muted);
    border-bottom: 2px solid transparent; margin-bottom: -1px; background: none; border-top: none;
    border-left: none; border-right: none; }
.tab.active { color: var(--text); border-bottom-color: var(--accent); font-weight: 600; }

.item { padding: .75rem 0; border-bottom: 1px solid var(--border); }
.item:last-child { border-bottom: none; }
.item-meta  { font-size: .8rem; color: var(--muted); margin-bottom: .25rem; display: flex; gap: .75rem; }
.item-body  { font-family: var(--mono); font-size: .9rem; white-space: pre-wrap; word-break: break-word; }
.item-actions { margin-top: .4rem; display: flex; gap: .4rem; }
.item-edit  { margin-top: .5rem; }
.item-edit textarea { width: 100%; padding: .5rem; background: var(--surface);
    border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); font: 14px/1.4 var(--mono); resize: vertical; }
.type-icon { font-size: 1rem; }

.follow-form { display: flex; gap: .5rem; margin-bottom: 1rem; }
.follow-form input { flex: 1; padding: .4rem .6rem; background: var(--surface);
    border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); font-size: .9rem; }
.section-label { font-size: .8rem; color: var(--muted); margin: .75rem 0 .25rem; text-transform: uppercase;
    letter-spacing: .06em; }
.person { display: flex; justify-content: space-between; align-items: center;
    padding: .4rem 0; border-bottom: 1px solid var(--border); font-size: .85rem;
    font-family: var(--mono); }
.person a { color: var(--text); text-decoration: none; }
.person a:hover { text-decoration: underline; }
.pname { color: var(--muted); margin-left: .4rem; font-family: system-ui, sans-serif; }

#status { font-size: .8rem; color: var(--muted); min-height: 1.2rem; margin-top: .25rem; }
.attach-row { display: flex; gap: .5rem; align-items: center; margin-top: .4rem; font-size: .8rem; }
.attach-row input[type=text] { flex: 1; padding: .3rem .5rem; background: var(--surface);
    border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); font-size: .8rem; }
.attach-row input[type=file] { color: var(--muted); font-size: .75rem; max-width: 45%; }
#attach-preview img { max-height: 60px; border-radius: var(--radius); display: block; margin-top: .4rem; }
.profile-form { display: flex; flex-direction: column; gap: .5rem; max-width: 420px; }
.profile-form label { font-size: .8rem; color: var(--muted); }
.profile-form input, .profile-form textarea { padding: .4rem .6rem; background: var(--surface);
    border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); font-size: .85rem; }
.profile-form textarea { resize: vertical; min-height: 80px; }
.empty  { color: var(--muted); font-size: .9rem; padding: 1rem 0; }
</style>
</head>
<body>
<div id="app">
  <header>
    <h1>@<?= $username ?>@<?= $server ?></h1>
    <a href="?logout=1"><button>logout</button></a>
  </header>

  <div id="compose">
    <textarea id="content" placeholder="what's on your mind?" oninput="countChars()"></textarea>
    <div class="compose-footer">
      <input id="reply" type="url" placeholder="reply to (optional URL)">
      <span id="charcount"></span>
      <button class="primary" onclick="post()">Post</button>
    </div>
    <div class="attach-row">
      <input type="file" id="image" accept="image/jpeg,image/png,image/gif,image/webp" onchange="attachImage()">
      <input type="text" id="alt" placeholder="image description (alt text)">
    </div>
    <div id="attach-preview"></div>
    <div id="status"></div>
  </div>

  <div class="tabs">
    <button class="tab active" onclick="switchTab('posts', this)">Posts</button>
    <button class="tab"        id="tab-inbox" onclick="switchTab('inbox', this)">inbox</button>
    <button class="tab"        onclick="switchTab('people', this)">People</button>
    <button class="tab"        onclick="switchTab('profile', this)">Profile</button>
  </div>

  <div id="panel-posts">
    <div class="empty">loading...</div>
  </div>
  <div id="panel-inbox"  style="display:none"><div class="empty">loading...</div></div>
  <div id="panel-people" style="display:none"><div class="empty">loading...</div></div>
  <div id="panel-profile" style="display:none">
    <div class="profile-form">
      <label for="profile-name">display name</label>
      <input id="profile-name" value="<?= htmlspecialchars($user_data['name'] ?? $username) ?>">
      <label for="profile-summary">bio</label>
      <textarea id="profile-summary"><?= htmlspecialchars($user_data['summary'] ?? '') ?></textarea>
      <label for="profile-icon">avatar URL</label>
      <input id="profile-icon" type="url" value="<?= htmlspecialchars($user_data['icon'] ?? '') ?>">
      <div><button class="primary" onclick="saveProfile()">save profile</button></div>
    </div>
  </div>
</div>

<script>
const BASE = <?= json_encode(rtrim($base_url, '/')) ?>;
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
const MAX_CHARS = <?= (int) ($max_post_chars ?? 500) ?>;
let attachment = null;

// Remote actors control their own names and URLs: always escape before injecting.
const esc = s => String(s ?? '').replace(/[&<>"']/g,
    c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function setStatus(msg, ok = true) {
    const el = document.getElementById('status');
    el.textContent = msg;
    el.style.color = ok ? 'var(--muted)' : 'var(--danger)';
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

function countChars() {
    const n = document.getElementById('content').value.length;
    const el = document.getElementById('charcount');
    el.textContent = n > 0 ? `${n}/${MAX_CHARS}` : '';
    el.style.color = n > MAX_CHARS ? 'var(--danger)' : 'var(--muted)';
}

async function attachImage() {
    const input = document.getElementById('image');
    if (!input.files.length) return;

    const form = new FormData();
    form.append('file', input.files[0]);
    setStatus('uploading...');

    const r = await fetch(`${BASE}/ap/upload`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': CSRF_TOKEN },
        body: form
    });
    if (!r.ok) { setStatus('upload failed: ' + await r.text(), false); input.value = ''; return; }

    attachment = await r.json();
    document.getElementById('attach-preview').innerHTML =
        `<img src="${esc(attachment.url)}" alt="">`;
    setStatus('image attached');
}

function clearAttachment() {
    attachment = null;
    document.getElementById('image').value = '';
    document.getElementById('alt').value = '';
    document.getElementById('attach-preview').innerHTML = '';
}

async function post() {
    const content  = document.getElementById('content').value.trim();
    const in_reply = document.getElementById('reply').value.trim();
    const alt      = document.getElementById('alt').value.trim();
    if (!content && !attachment) return;
    if (content.length > MAX_CHARS) { setStatus('too long', false); return; }
    const btn = document.querySelector('button.primary');
    btn.disabled = true;
    setStatus('posting...');
    const data = { content };
    if (in_reply) data.in_reply_to = in_reply;
    if (attachment) {
        data.attachment = attachment.url;
        if (alt) data.attachment_description = alt;
    }
    const r = await api('/ap/post', data);
    btn.disabled = false;
    if (r.ok) {
        document.getElementById('content').value = '';
        document.getElementById('reply').value = '';
        clearAttachment();
        countChars();
        setStatus('posted');
        loadPosts();
    } else {
        setStatus('error', false);
    }
}

async function loadPosts() {
    const panel = document.getElementById('panel-posts');
    const posts = await get('/ap/post');
    if (!posts || !posts.length) { panel.innerHTML = '<div class="empty">no posts yet</div>'; return; }
    panel.innerHTML = posts.map(p => {
        const guid    = p.guid;
        const content = esc(p.content);
        const date    = p.published ? p.published.slice(0, 10) : '';
        return `<div class="item" id="post-${guid}">
            <div class="item-meta"><span>${date}</span></div>
            <div class="item-body">${content}</div>
            <div class="item-actions">
                <button class="ghost" onclick="startEdit('${guid}')">edit</button>
                <button class="danger" onclick="deletePost('${guid}')">delete</button>
            </div>
            <div class="item-edit" id="edit-${guid}" style="display:none">
                <textarea rows="3">${esc(p.content)}</textarea>
                <div class="item-actions">
                    <button class="primary" onclick="saveEdit('${guid}')">save</button>
                    <button class="ghost"   onclick="cancelEdit('${guid}')">cancel</button>
                </div>
            </div>
        </div>`;
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
    else { setStatus('error', false); }
}
async function deletePost(guid) {
    if (!confirm('delete this post?')) return;
    const r = await api(`/ap/post/${guid}`, { action: 'delete' });
    if (r.ok) { document.getElementById(`post-${guid}`)?.remove(); setStatus('deleted'); }
    else { setStatus('error', false); }
}

async function loadInbox() {
    const panel = document.getElementById('panel-inbox');
    const items = await get('/ap/inbox?mark_read=1');
    if (!items) return;

    const unread = items.filter(i => !i.is_read).length;
    document.getElementById('tab-inbox').textContent = unread ? `inbox (${unread})` : 'inbox';

    if (!items.length) { panel.innerHTML = '<div class="empty">inbox empty</div>'; return; }

    const icons = { Like: '♥', Announce: '↻', Create: '💬' };
    panel.innerHTML = items.map(item => {
        const icon   = icons[item.type] || '•';
        const actor  = item.actor_uri || '';
        const handle = item.actor_handle || esc(actor.replace(/https?:\/\//, ''));
        const date   = (item.created_at || '').slice(0, 16).replace(' ', ' · ');
        let preview  = '';
        if (item.type === 'Create' && item.data?.object?.content) {
            const text = String(item.data.object.content).replace(/<[^>]+>/g, '').slice(0, 220);
            preview = `<div class="item-body">${esc(text)}</div>`;
        }
        const reply = (item.type === 'Create' && item.object_uri)
            ? `<button class="ghost" data-reply="${esc(item.object_uri)}">reply</button>` : '';
        const link = item.object_uri
            ? `<a href="${esc(item.object_uri)}" target="_blank" style="color:var(--muted)">open</a>` : '';
        return `<div class="item">
            <div class="item-meta">
                <span class="type-icon">${icon}</span>
                <a href="${esc(actor)}" target="_blank" style="color:var(--muted)">${handle}</a>
                <span>${date}</span>
                ${reply} ${link}
            </div>
            ${preview}
        </div>`;
    }).join('');

    panel.querySelectorAll('[data-reply]').forEach(btn =>
        btn.addEventListener('click', () => replyTo(btn.dataset.reply)));
}

function replyTo(uri) {
    document.getElementById('reply').value = uri;
    switchTab('posts', document.querySelector('.tab'));
    document.getElementById('content').focus();
    setStatus('replying — write your reply');
}

async function saveProfile() {
    const r = await api('/ap/profile', {
        name: document.getElementById('profile-name').value,
        summary: document.getElementById('profile-summary').value,
        icon: document.getElementById('profile-icon').value
    });
    setStatus(r.ok ? 'profile saved' : 'error', r.ok);
}

async function loadPeople() {
    const panel = document.getElementById('panel-people');
    const people = await get('/ap/people');
    if (!people) return;

    const followInput = `
        <div class="follow-form">
            <input id="follow-input" type="text" placeholder="@user@server.social">
            <button class="primary" onclick="followUser()">Follow</button>
        </div>`;

    const renderList = (items, actions) => {
        if (!items.length) return '<div class="empty">none</div>';
        return items.map(p => {
            const name = p.name ? `<span class="pname">${esc(p.name)}</span>` : '';
            const btns = (actions || []).map(([label, attr, cls]) =>
                `<button class="${cls}" data-${attr}="${esc(p.uri)}">${label}</button>`).join('');
            return `<div class="person">
                <span><a href="${esc(p.url)}" target="_blank">${esc(p.handle)}</a>${name}</span>
                <span>${btns}</span>
            </div>`;
        }).join('');
    };

    panel.innerHTML = `
        ${followInput}
        <div class="section-label">following (${people.following.length})</div>
        ${renderList(people.following, [['unfollow', 'unfollow', 'ghost'], ['block', 'block', 'danger']])}
        <div class="section-label">followers (${people.followers.length})</div>
        ${renderList(people.followers, [['block', 'block', 'danger']])}
        <div class="section-label">blocked (${people.blocked.length})</div>
        ${renderList(people.blocked, [['unblock', 'unblock', 'ghost']])}
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
    if (!confirm('block this actor? Their posts stop arriving and they are removed from your followers.')) return;
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

const loaders = { posts: loadPosts, inbox: loadInbox, people: loadPeople, profile: () => {} };

function switchTab(name, btn) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    ['posts','inbox','people','profile'].forEach(t => {
        document.getElementById(`panel-${t}`).style.display = t === name ? '' : 'none';
    });
    loaders[name]();
}

loadPosts();
</script>
</body>
</html>
