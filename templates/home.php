<?php
global $server, $username, $user_data, $base_url, $db_handler;

require_once __DIR__ . '/_theme.php';

$base      = $base_url;
$name      = $user_data['name'] ?? $username;
$summary   = trim((string) ($user_data['summary'] ?? ''));
$handle    = '@' . $username . '@' . $server;
$entry_all = array_values(array_filter(
    \SUFI\System\Database\get_posts($db_handler),
    fn($p) => ($p['visibility'] ?? 'public') === 'public'
));
$recent    = array_slice($entry_all, 0, 8);
$followers = \SUFI\System\Database\count_followers($db_handler);
$following = \SUFI\System\Database\count_following($db_handler);
$oldest    = end($entry_all);
$since     = $oldest ? (int) date('Y', (int) strtotime((string) $oldest['published'])) : (int) date('Y');

$home_css = <<<'CSS'
.stats {
    display: flex;
    gap: 1.8rem;
    flex-wrap: wrap;
    margin: 1.4rem 0 2.2rem;
    padding: .8rem 0;
    border-top: 1px solid var(--rule);
    border-bottom: 1px solid var(--rule);
    font: .68rem/1.6 var(--mono);
    color: var(--ink-soft);
}
.stats b { display: block; font: 700 1.4rem/1.2 var(--serif); color: var(--ink); }
.entry-body figure { margin: .6rem 0; }
.entry-body figure img {
    max-width: 100%;
    display: block;
    border: 1px solid var(--rule);
    padding: 3px;
    background: var(--paper-3);
}
.entry-body figcaption { font: .72rem/1.5 var(--mono); color: var(--ink-soft); margin-top: .25rem; }
.visit {
    margin-top: 2.6rem;
    background: var(--paper-3);
    border: 1px solid var(--rule);
    box-shadow: var(--shadow);
    padding: 1.1rem 1.2rem;
}
.visit code { font-size: .9rem; padding: .1rem .35rem; }
.links { display: flex; gap: 1.1rem; flex-wrap: wrap; font: .7rem/1.7 var(--mono); }
CSS;

header('Content-Type: text/html; charset=utf-8');
\SUFI\Templates\theme_head($name . ' (' . $handle . ')', $home_css);
?><body>
<div id="app">
  <header class="masthead">
    <div class="kicker">
      <span>a personal page on the fediverse</span>
      <span><?= date('j F Y') ?></span>
    </div>
    <div class="masthead-row">
      <div>
        <h1 class="nameplate"><?= htmlspecialchars($name) ?></h1>
        <div class="handle"><?= htmlspecialchars($handle) ?></div>
      </div>
      <a class="btn ghost small" href="<?= htmlspecialchars($base) ?>sufi/">owner login</a>
    </div>
    <p class="epigraph"><?= htmlspecialchars(\SUFI\Templates\theme_epigraph()) ?></p>
  </header>

  <?php if ($summary !== ''): ?>
  <p class="lede"><?= nl2br(htmlspecialchars($summary)) ?></p>
  <?php else: ?>
  <p class="lede">A quiet corner of the fediverse. One person writes here; anyone is welcome to read.</p>
  <?php endif; ?>

  <div class="stats">
    <span><b><?= count($entry_all) ?></b><?= count($entry_all) === 1 ? 'entry' : 'entries' ?></span>
    <span><b><?= $followers ?></b><?= $followers === 1 ? 'follower' : 'followers' ?></span>
    <span><b><?= $following ?></b>following</span>
    <span><b><?= $since ?></b>writing since</span>
  </div>

  <section>
    <div class="eyebrow">recent entries</div>
    <?php if (!$recent): ?>
      <div class="empty">Nothing published yet. The notebook is open and waiting.</div>
    <?php else: ?>
    <div class="ledger">
      <?php foreach ($recent as $post):
          $data = json_decode((string) $post['data'], true) ?: [];
          $note = $data['object'] ?? $data;
          $atts = is_array($note['attachment'] ?? null) ? $note['attachment'] : [];
          $atts = is_array($atts) && isset($atts['url']) ? [$atts] : $atts;
          $cwd  = trim((string) ($post['summary'] ?? ''));
      ?>
      <article class="entry">
        <div class="entry-meta">
          <time datetime="<?= htmlspecialchars((string) $post['published']) ?>"><?= htmlspecialchars(\SUFI\Templates\theme_date((string) $post['published'], '—')) ?></time>
          <?php if ($post['in_reply'] ?? null): ?><span class="kind">↳ reply</span><?php endif; ?>
          <?php if ($cwd !== ''): ?><span class="kind">cw · <?= htmlspecialchars($cwd) ?></span><?php endif; ?>
        </div>
        <div class="entry-body">
          <?= \SUFI\format_content((string) $post['content']) ?>
          <?php foreach ($atts as $att):
              $url = is_array($att) ? (string) ($att['url'] ?? '') : '';
              if ($url === '') { continue; }
              $desc = is_array($att) ? trim((string) ($att['name'] ?? '')) : '';
          ?>
          <figure>
            <img src="<?= htmlspecialchars($url) ?>" alt="<?= htmlspecialchars($desc) ?>" loading="lazy">
            <?php if ($desc !== ''): ?><figcaption><?= htmlspecialchars($desc) ?></figcaption><?php endif; ?>
          </figure>
          <?php endforeach; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <section class="visit">
    <div class="eyebrow plain">read along</div>
    <p>Paste <code><?= htmlspecialchars($handle) ?></code> into the search box of any
      fediverse instance — Mastodon, Misskey, GoToSocial — and press follow.
      New entries land in your timeline like mail.</p>
    <p class="links">
      <a href="<?= htmlspecialchars($base) ?>ap/outbox">full outbox</a>
      <a href="<?= htmlspecialchars($base) ?>ap/actor">actor json</a>
      <a href="<?= htmlspecialchars($base) ?>ap/followers">followers</a>
      <a href="<?= htmlspecialchars($base) ?>ap/following">following</a>
    </p>
  </section>

  <footer class="colophon">
    <span>Written by hand. Published over ActivityPub.</span>
    <span>Powered by SUFI</span>
  </footer>
</div>
</body>
</html>
