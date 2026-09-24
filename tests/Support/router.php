<?php

/**
 * Router for the fixture http server that simulates the monitored sites.
 *
 * GET /site/<name> answers according to <state dir>/<name>.json:
 *   {"status": 503, "delay": 0.4, "location": "/site/other"}
 * Every request is logged to <state dir>/<name>.requests (one json line per request).
 */

$dir = getenv('FIXTURE_STATE_DIR');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (!preg_match('#^/site/([\w-]+)$#', $path, $m)) {
    http_response_code(404);
    echo 'not found';
    return;
}

$name = $m[1];
file_put_contents("$dir/$name.requests", json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers' => getallheaders(),
    'body' => file_get_contents('php://input'),
]) . "\n", FILE_APPEND | LOCK_EX);

$file = "$dir/$name.json";
$state = is_file($file) ? json_decode(file_get_contents($file), true) : [];

if (!empty($state['delay'])) {
    usleep((int) ($state['delay'] * 1_000_000));
}
http_response_code($state['status'] ?? 200);
if (isset($state['location'])) {
    header('Location: ' . $state['location']);
}
echo 'ok';
