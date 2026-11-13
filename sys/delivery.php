<?php
namespace SUFI;

/**
 * Outbound federation: signed HTTP requests, the retry queue, and fetching
 * remote actor documents. Shared by index.php and the delivery worker in
 * scripts/ops/deliver.php.
 */

// ── Logging ───────────────────────────────────────────────────────────────────

/**
 * Append to a single rotated log file. Never create a file per request: the
 * inbox is public, so that would let anyone fill sys/logs/.
 */
function save_log($label, $message) {
    global $directories;

    $file = ($directories['logs'] ?? sys_get_temp_dir()) . '/security.log';
    if (is_file($file) && filesize($file) > 1048576) {
        @rename($file, $file . '.1');
    }

    $line = '[' . date('c') . "] {$label}: "
        . str_replace(["\r", "\n"], ' ', (string) $message) . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

// ── Signed requests ───────────────────────────────────────────────────────────

function generate_signed_headers($message, $host, $path, $method) {
    global $base_url, $key_private;

    $keyId  = "{$base_url}ap/actor#main-key";
    $signer = openssl_get_privatekey($key_private);
    $date   = date("D, d M Y H:i:s \G\M\T");

    if ($method === "POST") {
        $message_json = json_encode($message);
        $digest       = base64_encode(hash("sha256", $message_json, true));
        $stringToSign = "(request-target): post {$path}\nhost: {$host}\ndate: {$date}\ndigest: SHA-256={$digest}";

        openssl_sign($stringToSign, $signature, $signer, OPENSSL_ALGO_SHA256);
        $sig_hdr = "keyId=\"{$keyId}\",algorithm=\"rsa-sha256\",headers=\"(request-target) host date digest\","
            . "signature=\"" . base64_encode($signature) . "\"";

        return [
            "Host: {$host}",
            "Date: {$date}",
            "Digest: SHA-256={$digest}",
            "Signature: {$sig_hdr}",
            "Content-Type: application/activity+json",
            "Accept: application/activity+json",
            "User-Agent: SUFI (+{$base_url})",
        ];
    }

    $stringToSign = "(request-target): get {$path}\nhost: {$host}\ndate: {$date}";
    openssl_sign($stringToSign, $signature, $signer, OPENSSL_ALGO_SHA256);
    $sig_hdr = "keyId=\"{$keyId}\",algorithm=\"rsa-sha256\",headers=\"(request-target) host date\","
        . "signature=\"" . base64_encode($signature) . "\"";

    return [
        "Host: {$host}",
        "Date: {$date}",
        "Signature: {$sig_hdr}",
        "Accept: application/activity+json, application/json",
        "User-Agent: SUFI (+{$base_url})",
    ];
}

/** Deliver one activity now. Returns true on a 2xx response. */
function raw_send($inbox, $message) {
    $parsed = parse_url((string) $inbox);
    if (empty($parsed['host'])) { return false; }

    $host = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
    $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

    $ctx = stream_context_create(['http' => [
        'method'          => 'POST',
        'header'          => implode("\r\n", generate_signed_headers($message, $host, $path, 'POST')),
        'content'         => json_encode($message),
        'ignore_errors'   => true,
        'follow_location' => 0,
        'max_redirects'   => 0,
        'timeout'         => 10,
    ]]);

    $response = @file_get_contents($inbox, false, $ctx);
    $status   = $http_response_header[0] ?? '';

    return $response !== false && (bool) preg_match('#^HTTP/\S+\s+2\d\d#', $status);
}

// ── Delivery queue ────────────────────────────────────────────────────────────

function schedule_drain() {
    static $scheduled = false;
    if ($scheduled) { return; }
    $scheduled = true;

    register_shutdown_function(function () {
        // Flush the response first: a slow follower must not delay the request.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && deliver_pending(10) > 0) {
            // keep draining until the queue is empty or we run out of time
        }
    });
}

/** Queue an activity for one inbox and try to deliver it after the response. */
function sendMessageToSingle($inbox, $message) {
    global $db_handler;

    \SUFI\System\Database\enqueue_delivery($db_handler, $inbox, $message);
    schedule_drain();
}

/** Fan-out: queue once per unique inbox, deliver after the response. */
function sendToFollowers($message) {
    global $db_handler;

    $queued = [];
    foreach (\SUFI\System\Database\get_followers($db_handler) as $follower) {
        $inbox = (string) $follower['inbox'];
        if ($inbox === '' || isset($queued[$inbox])) { continue; }
        $queued[$inbox] = true;
        \SUFI\System\Database\enqueue_delivery($db_handler, $inbox, $message);
    }

    if ($queued) { schedule_drain(); }
}

/** Backoff schedule, in seconds, indexed by attempt number. */
function next_delivery_time($attempts) {
    $delays = [60, 300, 900, 3600, 21600];
    $delay  = $delays[min(max((int) $attempts, 1), count($delays)) - 1];
    return gmdate('Y-m-d H:i:s', time() + $delay);
}

/** Try to deliver queued activities. Returns how many succeeded. */
function deliver_pending($limit = 25) {
    global $db_handler;

    $sent = 0;
    foreach (\SUFI\System\Database\claim_deliveries($db_handler, $limit) as $row) {
        $message = json_decode((string) $row['payload'], true);
        if (!is_array($message)) {
            \SUFI\System\Database\delete_delivery($db_handler, $row['id']);
            continue;
        }

        if (raw_send($row['inbox'], $message)) {
            \SUFI\System\Database\delete_delivery($db_handler, $row['id']);
            $sent++;
            continue;
        }

        $attempts = (int) $row['attempts'] + 1;
        if ($attempts >= 10) {
            \SUFI\System\Database\delete_delivery($db_handler, $row['id']);
            save_log('Delivery.Dropped', "inbox: {$row['inbox']} attempts: {$attempts}");
        } else {
            \SUFI\System\Database\retry_delivery(
                $db_handler, $row['id'], $attempts, next_delivery_time($attempts)
            );
        }
    }

    return $sent;
}

// ── Remote fetches ────────────────────────────────────────────────────────────

/**
 * Only https to public hosts, unless $allow_private_fetch is on (local dev and
 * the test suite use http://127.0.0.1).
 */
function fetch_allowed_url($url) {
    global $allow_private_fetch;

    $parts  = parse_url((string) $url);
    $scheme = strtolower($parts['scheme'] ?? '');

    if (!in_array($scheme, ['http', 'https'], true)) { return null; }
    if (!empty($allow_private_fetch)) { return $url; }
    if ($scheme !== 'https') { return null; }

    $host = $parts['host'] ?? '';
    return ($host !== '' && host_is_public($host)) ? $url : null;
}

/** Reject loopback, private and link-local addresses (including resolved ones). */
function host_is_public($host) {
    static $seen = [];
    if (isset($seen[$host])) { return $seen[$host]; }

    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : [];
    if (!$ips) {
        foreach (gethostbynamel($host) ?: [] as $ip) {
            $ips[] = $ip;
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ipv6'])) { $ips[] = $record['ipv6']; }
        }
    }

    if (!$ips) { return $seen[$host] = false; }

    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $seen[$host] = false;
        }
    }

    return $seen[$host] = true;
}

function cache_path($url) {
    global $directories;

    $dir = dirname($directories['logs'] ?? __DIR__) . '/cache';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }

    return $dir . '/' . hash('sha256', $url) . '.json';
}

function cache_read($file) {
    if (!is_file($file)) { return [false, null]; }

    $entry = json_decode((string) @file_get_contents($file), true);
    if (!is_array($entry) || !array_key_exists('data', $entry)) { return [false, null]; }

    $ttl = !empty($entry['ok']) ? 300 : 30;
    if (time() - (int) @filemtime($file) > $ttl) { return [false, null]; }

    return [true, $entry['data']];
}

function cache_write($file, $ok, $data) {
    @file_put_contents($file, json_encode(['ok' => $ok, 'data' => $data]), LOCK_EX);
}

/**
 * Fetch a JSON document from another server.
 *
 * Bounded timeout, no redirects, public hosts only, and a short-lived disk
 * cache so repeated deliveries from the same server do not refetch its actor
 * document every time.
 */
function fetch_json($url) {
    $target = fetch_allowed_url($url);
    if ($target === null) {
        save_log('Fetch.Blocked', (string) $url);
        return null;
    }

    $cache = cache_path($target);
    [$hit, $data] = cache_read($cache);
    if ($hit) { return $data; }

    $parsed = parse_url($target);
    $host   = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
    $path   = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

    $ctx = stream_context_create(['http' => [
        'method'          => 'GET',
        'header'          => implode("\r\n", generate_signed_headers(null, $host, $path, 'GET')),
        'ignore_errors'   => true,
        'follow_location' => 0,
        'max_redirects'   => 0,
        'timeout'         => 4,
    ]]);

    $body   = @file_get_contents($target, false, $ctx);
    $status = $http_response_header[0] ?? '';
    $ok     = $body !== false && preg_match('#^HTTP/\S+\s+2\d\d#', $status);

    $data = $ok ? json_decode($body, true) : null;
    $ok   = is_array($data);

    cache_write($cache, $ok, $ok ? $data : null);

    if (!$ok) {
        save_log('Fetch.Failed', "{$target} {$status}");
        return null;
    }

    return $data;
}

function getDataFromURl($url) {
    static $cache = [];

    $url = (string) $url;
    if (array_key_exists($url, $cache)) { return $cache[$url]; }

    return $cache[$url] = fetch_json(strtok($url, '#'));
}

function getUserDetails($handle) {
    $parts = explode("@", ltrim((string) $handle, "@"));
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return ["profile" => null, "inbox" => null];
    }
    [$follow_name, $follow_server] = $parts;

    $webfinger     = getDataFromURl("https://{$follow_server}/.well-known/webfinger?resource=acct:{$follow_name}@{$follow_server}");
    $profile_url   = null;
    $profile_inbox = null;

    foreach ($webfinger["links"] ?? [] as $link) {
        if (($link["rel"] ?? '') === "self")        { $profile_url = $link["href"] ?? null; }
        if (($link["rel"] ?? '') === "inbox")       { $profile_inbox = $link["href"] ?? null; }
        if (($link["rel"] ?? '') === "sharedInbox") { $profile_inbox = $link["href"] ?? null; }
    }

    if (!$profile_inbox && $profile_url) {
        $profile       = getDataFromURl($profile_url);
        $profile_inbox = $profile["endpoints"]["sharedInbox"] ?? $profile["inbox"] ?? null;
    }

    return ["profile" => $profile_url, "inbox" => $profile_inbox];
}
