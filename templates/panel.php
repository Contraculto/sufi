<?php
global $server, $username;
header('Content-Type: text/html; charset=utf-8');

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
<form method="post" action="/sufi/login">
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

#status { font-size: .8rem; color: var(--muted); min-height: 1.2rem; margin-top: .25rem; }
.empty  { color: var(--muted); font-size: .9rem; padding: 1rem 0; }
</style>
</head>
<body>
<div id="app">
  <header>
    <h1>@<?= $username ?>@<?= $server ?></h1>
    <a href="/sufi/logout"><button>logout</button></a>
  </header>

  <div id="compose">
    <textarea id="content" placeholder="what's on your mind?" oninput="countChars()"></textarea>
    <div class="compose-footer">
      <input id="reply" type="url" placeholder="reply to (optional URL)">
      <span id="charcount"></span>
      <button class="primary" onclick="post()">Post</button>
    </div>
    <div id="status"></div>
  </div>

  <div class="tabs">
    <button class="tab active" onclick="switchTab('posts', this)">Posts</button>
    <button class="tab"        onclick="switchTab('inbox', this)">Inbox</button>
    <button class="tab"        onclick="switchTab('people', this)">People</button>
  </div>

  <div id="panel-posts">
    <div class="empty">loading...</div>
  </div>
  <div id="panel-inbox"  style="display:none"><div class="empty">loading...</div></div>
  <div id="panel-people" style="display:none"><div class="empty">loading...</div></div>
</div>

<script>
const BASE = window.location.origin;
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

function setStatus(msg, ok = true) {
    const el = document.getElementById('status');
    el.textContent = msg;
    el.style.color = ok ? 'var(--muted)' : 'var(--danger)';
    if (msg) setTimeout(() => el.textContent = '', 3000);
}

async function get(path) {
    const r = await fetch(`${BASE}${path}`, { credentials: 'same-origin' });
    if (r.status === 401) { location.href = '/sufi/'; return null; }
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
    if (r.status === 401) { location.href = '/sufi/'; }
    return r;
}

function countChars() {
    const n = document.getElementById('content').value.length;
    document.getElementById('charcount').textContent = n > 0 ? `${n}` : '';
}

async function post() {
    const content  = document.getElementById('content').value.trim();
    const in_reply = document.getElementById('reply').value.trim();
    if (!content) return;
    const btn = document.querySelector('button.primary');
    btn.disabled = true;
    setStatus('posting...');
    const data = { content };
    if (in_reply) data.in_reply_to = in_reply;
    const r = await api('/ap/post', data);
    btn.disabled = false;
    if (r.ok) {
        document.getElementById('content').value = '';
        document.getElementById('reply').value = '';
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
        const content = (p.content || '').replace(/</g, '&lt;');
        const date    = p.published ? p.published.slice(0, 10) : '';
        return `<div class="item" id="post-${guid}">
            <div class="item-meta"><span>${date}</span></div>
            <div class="item-body">${content}</div>
            <div class="item-actions">
                <button class="ghost" onclick="startEdit('${guid}')">edit</button>
                <button class="danger" onclick="deletePost('${guid}')">delete</button>
            </div>
            <div class="item-edit" id="edit-${guid}" style="display:none">
                <textarea rows="3">${p.content || ''}</textarea>
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
    const items = await get('/ap/inbox');
    if (!items || !items.length) { panel.innerHTML = '<div class="empty">inbox empty</div>'; return; }
    const icons = { Like: '[like]', Announce: '[boost]', Create: '[reply]' };
    panel.innerHTML = items.map(item => {
        const icon    = icons[item.type] || '[*]';
        const actor   = item.actor_uri || '';
        const handle  = actor.replace(/https?:\/\//, '');
        const date    = (item.created_at || '').slice(0, 10);
        let preview   = '';
        if (item.type === 'Create' && item.data?.object?.content) {
            preview = `<div class="item-body">${item.data.object.content.replace(/<[^>]+>/g, '').slice(0, 200)}</div>`;
        }
        if (item.object_uri) {
            preview += `<div class="item-meta"><a href="${item.object_uri}" target="_blank" style="color:var(--muted)">${item.object_uri.slice(0, 60)}</a></div>`;
        }
        return `<div class="item">
            <div class="item-meta">
                <span class="type-icon">${icon}</span>
                <a href="${actor}" target="_blank" style="color:var(--muted)">${handle}</a>
                <span>${date}</span>
            </div>
            ${preview}
        </div>`;
    }).join('');
}

async function loadPeople() {
    const panel = document.getElementById('panel-people');
    const [followers, following] = await Promise.all([
        fetch(`${BASE}/ap/followers`).then(r => r.json()),
        fetch(`${BASE}/ap/following`).then(r => r.json()),
    ]);

    const followInput = `
        <div class="follow-form">
            <input id="follow-input" type="text" placeholder="@user@server.social">
            <button class="primary" onclick="followUser()">Follow</button>
        </div>`;

    const renderList = (items, withUnfollow) => {
        if (!items.length) return '<div class="empty">none</div>';
        return items.map(uri => {
            const handle = uri.replace(/https?:\/\//, '');
            const btn = withUnfollow
                ? `<button class="ghost" onclick="unfollowUser('${uri}')">unfollow</button>`
                : '';
            return `<div class="person"><a href="${uri}" target="_blank">${handle}</a>${btn}</div>`;
        }).join('');
    };

    panel.innerHTML = `
        ${followInput}
        <div class="section-label">following (${following.totalItems})</div>
        ${renderList(following.orderedItems || [], true)}
        <div class="section-label">followers (${followers.totalItems})</div>
        ${renderList(followers.orderedItems || [], false)}
    `;
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
    const handle = '@' + uri.replace(/https?:\/\/([^/]+)\/users\/(.+)/, '$2@$1');
    const r = await api('/ap/action', { user: handle, action: 'Unfollow' });
    const msg = await r.text();
    setStatus(msg, r.ok);
    if (r.ok) loadPeople();
}

const loaders = { posts: loadPosts, inbox: loadInbox, people: loadPeople };

function switchTab(name, btn) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    ['posts','inbox','people'].forEach(t => {
        document.getElementById(`panel-${t}`).style.display = t === name ? '' : 'none';
    });
    loaders[name]();
}

loadPosts();
</script>
</body>
</html>
