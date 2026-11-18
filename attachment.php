<?php
// attachment.php — выдача вложений с проверкой прав видимости.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';

$id = (int)($_GET['id'] ?? 0);
$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT * FROM attachments WHERE id = ?");
$stmt->execute([$id]);
$att = $stmt->fetch();
if (!$att) { http_response_code(404); exit('Файл не найден'); }

$isStaff = isModerator();
$staffFlag = $isStaff ? 1 : 0;

// Видимость родительского объекта: те же правила, что у topic.php
if ($att['target_type'] === 'topic') {
    $stmt = $db->prepare("
        SELECT t.id FROM topics t
        JOIN forums f ON f.id = t.forum_id
        JOIN categories c ON c.id = f.category_id
        WHERE t.id = ? AND f.is_active = 1 AND c.is_active = 1 AND (t.is_hidden = 0 OR ? = 1)
    ");
    $stmt->execute([(int)$att['target_id'], $staffFlag]);
} else {
    $stmt = $db->prepare("
        SELECT p.id FROM posts p
        JOIN topics t ON t.id = p.topic_id
        JOIN forums f ON f.id = t.forum_id
        JOIN categories c ON c.id = f.category_id
        WHERE p.id = ? AND f.is_active = 1 AND c.is_active = 1
          AND (p.is_hidden = 0 OR ? = 1) AND (t.is_hidden = 0 OR ? = 1)
    ");
    $stmt->execute([(int)$att['target_id'], $staffFlag, $staffFlag]);
}
if (!$stmt->fetch()) { http_response_code(404); exit('Файл не найден'); }

// Файл на диске: имя строго нашего формата
if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $att['stored_name'])) {
    http_response_code(404); exit('Файл не найден');
}
$path = __DIR__ . '/assets/uploads/attachments/' . $att['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('Файл отсутствует'); }

// Счётчик скачиваний (не чаще раза за сессию на файл)
if (empty($_SESSION['attach_dl'][$id])) {
    $db->prepare("UPDATE attachments SET downloads = downloads + 1 WHERE id = ?")->execute([$id]);
    $_SESSION['attach_dl'][$id] = true;
}

$isImage = (bool)$att['is_image'];
$mime = $isImage ? $att['mime'] : 'application/octet-stream';
$downloadName = str_replace(['"', "\r", "\n"], '', $att['orig_name']);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($isImage ? 'inline' : 'attachment') . '; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($att['orig_name']));
header('Cache-Control: private, max-age=86400');
if (!$isImage) {
    header("Content-Security-Policy: sandbox; default-src 'none'");
}
readfile($path);
exit;