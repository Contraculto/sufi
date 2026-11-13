<?php
global $server, $username, $user_data, $base_url;
$base = $base_url;
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($user_data['name'] ?? $username) ?> (@<?= htmlspecialchars($username) ?>@<?= htmlspecialchars($server) ?>)</title>
<style>body{font-family:sans-serif;max-width:600px;margin:2rem auto;padding:0 1rem}</style>
</head>
<body>
<h1><?= htmlspecialchars($user_data['name'] ?? $username) ?></h1>
<p>@<?= htmlspecialchars($username) ?>@<?= htmlspecialchars($server) ?></p>
<?php if (!empty($user_data['summary'])): ?>
<p><?= htmlspecialchars($user_data['summary']) ?></p>
<?php endif; ?>
<p><a href="<?= $base ?>ap/actor">Actor JSON</a> · <a href="<?= $base ?>ap/outbox">Outbox</a></p>
</body>
</html>
