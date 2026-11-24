# Inbox reply folding — design

Date: 2026-11-24
Status: approved by user, pending implementation plan

## Problem

The panel inbox (`GET /ap/inbox` in `index.php`, rendered by `templates/panel.php`)
returns a flat, paginated list where every incoming activity is its own row. On a
federated account, a single post that attracts N replies pushes N nearly identical
"reply" letters onto the page — the user asked to see the posts instead, with each
post's replies collapsed underneath it, expandable on demand.

## Decisions (from brainstorming)

1. **Only replies fold.** Replies to the same target collapse into one expandable
   group. Likes, boosts, mentions and follows stay as individual top-level rows.
2. **Nest under the direct parent.** A reply to a reply appears indented under that
   reply when both are in the inbox; otherwise it starts its own group. No remote
   walking of parent chains to a conversation root.
3. **Unread semantics unchanged.** Loading the inbox tab still marks everything read
   (`mark_read=1` on the first load, per-item and mark-all endpoints untouched). No
   group-level read tracking; a group shows an unread dot if any of its replies was
   unread in the returned payload.
4. **Server-side grouping.** The API returns what the UI shows. Chosen over
   client-side folding (threads split across pages, untestable logic in the browser)
   and a separate thread endpoint (extra round trip, awkward flat pagination).

## API shape

`GET /ap/inbox` keeps its auth, `limit`, `before_id`, `unread`, `mark_read`
semantics. The response changes from a flat `items` array to a mixed `entries`
array:

```json
{
  "entries": [
    { "type": "item", "item": { "...today's inbox row, unchanged..." } },
    {
      "type": "group",
      "parent_uri": "https://example.com/ap/post/abc",
      "parent": { "local": true, "guid": "abc", "content": "<p>your post</p>" },
      "count": 3,
      "replies": [ { "...row, oldest first..." }, { "...row..." } ],
      "is_read": 0
    }
  ],
  "next_before_id": 41,
  "unread": 7
}
```

Grouping rules (all inside `inbox_items()` in `index.php`):

- Fetch up to `limit + 1` rows exactly as today, classify each row with the
  existing `inbox_kind()`.
- `kind === 'reply'` rows are grouped by their `reply_to` URI; every other row
  becomes a top-level `item` entry.
- Replies inside a group are ordered **oldest first** (reading order).
- `count` = number of replies included in the group (capped at 100 per group;
  the headline count matches what is included).
- `is_read` = 1 only if **all** replies in the group are read.
- **Nested threads:** if a reply row's `object_uri` equals the `parent_uri` of
  another group in the same payload, the reply row gets `has_children: true` and
  the panel renders that group nested inside it (indented one level deeper).
- **Parent resolution:** if `parent_uri` starts with the local base URL +
  `/ap/post/`, look the post up in the `Post` table by guid and attach
  `parent: { local: true, guid, content }`. Remote (or deleted) parents get
  `parent: { local: false }` — no network fetch.
- **Pagination:** `next_before_id` is the id of the oldest row *consumed*,
  including folded replies, so load-more never resurfaces them. A page that
  folds 20 replies into one group returns one entry; clicking "load more"
  continues past all 20.

## Panel rendering (`templates/panel.php`)

- `loadInbox()` maps `page.entries` through a new `renderInboxEntry()`:
  - `type === 'item'` → existing `renderInboxItem`, unchanged.
  - `type === 'group'` → a `.letter.group` card:
    - Header: `✎ N replies` stamp; parent preview — truncated formatted local
      post content when `parent.local`, otherwise a muted "replying to
      host.name" link; newest reply's timestamp; unread dot if any reply unread.
    - One **"replies"** toggle button. The old per-item "thread" button
      disappears for folded replies.
    - No group-level delete or mark-read buttons.
- Expanding a group reveals a `.thread` container with compact mini-letters:
  handle, time, preview, per-reply **reply / delete / open** buttons (existing
  handlers reused).
- A reply with `has_children: true` gets its own nested "replies" toggle,
  rendered one indent deeper; recursion capped at depth 3.
- `toggleThread()` (the network-fetching parent lookup) is **deleted**. Parent
  content arrives in the payload; remote parents show the link row, same as its
  old non-local branch.

## CSS

Additions beside the existing `.letter` / `.thread` styles in `panel.php`:
`.letter.group` header accent, indent + left rule for nested levels, a compact
`.letter` variant for replies inside a thread. No changes to
`templates/_theme.php` — panel chrome only.

## Edge cases

- **Reply to a parent not in the inbox** (old post, cleared inbox): forms its
  own group; local parents still resolve from the `Post` table, remote ones
  show the link row.
- **Missing `object_uri` or empty `inReplyTo`:** classified `post`/`mention` by
  existing rules, stays top-level. Folding only ever touches `kind === 'reply'`.
- **Parent post deleted locally:** `Post` lookup misses → falls back to the
  remote-style link header. No fetch, no error.
- **One actor replying many times:** group count reflects it; replies included
  are capped at 100 per group.
- **"Clear read":** keeps sweeping read rows; groups whose replies vanish
  re-render empty and drop out on next load.

## Testing

- Update the existing inbox section of `tests/run.php` to the `entries` shape
  (handle/kind/preview/unread/pagination/mark-read/per-item read-delete
  assertions around lines 634–725 stay, reading from `entries`).
- New assertions:
  - two replies to the same post merge into one group (count = 2, oldest first);
  - a reply-to-a-reply sets `has_children` on its parent reply;
  - local parent resolves with `guid` + content;
  - remote parent gets `local: false`;
  - a page consisting only of replies returns one entry with a correct
    `next_before_id`;
  - likes, boosts and mentions still arrive as plain `item` entries.
- `./scripts/test.sh` must pass before shipping.
