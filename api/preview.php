<?php
// api/preview.php — серверный предпросмотр Markdown.
// Единый парсер с отображением постов (includes/markdown.php), без дубля в JS.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/markdown.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || !getCurrentUser()) {
    http_response_code(403);
    echo json_encode(['html' => '']);
    exit;
}

// Принимаем только POST с CSRF (защита от использования как открытого рендер-прокси)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['html' => '']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || !verifyCSRFToken($data['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['html' => '']);
    exit;
}

$text = (string)($data['text'] ?? '');
$maxLen = max(1, setting('message_max', 20000));
if (mb_strlen($text) > $maxLen) {
    $text = mb_substr($text, 0, $maxLen);
}

// Тот же рендер, что на странице темы. Упоминания резолвятся батчем.
mdPrimeMentions([$text]);
$html = mdRender($text);

echo json_encode(['html' => $html], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
