<?php
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) { echo '[]'; exit; }
// Забаненный аккаунт с живой сессией не должен пользоваться API.
if (!getCurrentUser()) { echo '[]'; exit; }

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
