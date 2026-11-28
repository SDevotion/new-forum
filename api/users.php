<?php
// api/users.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [6.api] avatar отдаётся через avatarUrl() (строгий regex + is_file) — битые
//       пути не уходят в JSON.
// [6.массовый 500] Ввод через getStr().
// LIKE-экранирование и лимитер — сохранены.

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ratelimit.php';
require_once __DIR__ . '/../includes/avatar.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn()) { echo '[]'; exit; }
if (!getCurrentUser()) { echo '[]'; exit; }

if (!rateLimitCheck('autocomplete', 30, 60)) {
    http_response_code(429);
    echo '[]';
    exit;
}

$q = trim(getStr('q'));
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
    // avatarUrl возвращает escape()-нутую строку; для JSON декодируем сущности обратно.
    $r['avatar'] = htmlspecialchars_decode(avatarUrl($r['avatar']), ENT_QUOTES);
}
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);