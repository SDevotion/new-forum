<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ratelimit.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) { echo '[]'; exit; }
if (!getCurrentUser()) { echo '[]'; exit; }

// Лимит частоты автодополнения (антиэнумерация ников)
if (!rateLimitCheck('autocomplete', 30, 60)) {
    http_response_code(429);
    echo '[]';
    exit;
}

$q = trim($_GET['q'] ?? '');
if ($q === '' || mb_strlen($q) > 20) { echo '[]'; exit; }
$qLike = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare("
    SELECT username, avatar FROM users
    WHERE is_active = 1 AND username LIKE ?
    ORDER BY posts_total DESC LIMIT " . max(1, setting('per_page_autocomplete', 5)) . "
");
$stmt->execute([$qLike . '%']);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['avatar'] = $r['avatar'] ?: '/assets/images/default-avatar.svg';
}
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
