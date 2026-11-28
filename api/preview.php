<?php
// api/preview.php — серверный предпросмотр Markdown.
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [6.массовый 500] text из JSON проверяется is_string(): массив/объект в поле
//       больше не даёт warning приведения (string)array и не парсится как мусор.
// Единый парсер с отображением постов, кап тела, лимитер — сохранены.

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/ratelimit.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || !getCurrentUser()) {
    http_response_code(403);
    echo json_encode(['html' => '']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['html' => '']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 300000) {
    http_response_code(413);
    echo json_encode(['html' => '']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || !verifyCSRFToken($data['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['html' => '']);
    exit;
}

if (!rateLimitCheck('preview', 60, 60)) {
    http_response_code(429);
    echo json_encode(['html' => '', 'error' => 'too_many_requests']);
    exit;
}

$text = is_string($data['text'] ?? null) ? $data['text'] : '';
$maxLen = max(1, setting('message_max', 20000));
if (mb_strlen($text) > $maxLen) {
    $text = mb_substr($text, 0, $maxLen);
}

mdPrimeMentions([$text]);
$html = mdRender($text);

echo json_encode(['html' => $html], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);