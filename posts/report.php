<?php
require_once __DIR__ . '/../includes/permissions.php';

if (!isLoggedIn()) { setFlashMessage('error', 'Необходимо войти'); redirect('/auth/login.php'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/index.php');
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) { setFlashMessage('error', 'Недействительный запрос'); redirect('/index.php'); }

$type = ($_POST['target_type'] ?? '') === 'post' ? 'post' : 'topic';
$id = (int)($_POST['target_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$back = $_POST['back'] ?? '/forum.php';
if (!preg_match('#^/[A-Za-z0-9_./?=&#%-]*$#', $back)) $back = '/forum.php';

if ($id <= 0) { setFlashMessage('error', 'Объект не найден'); redirect($back); }
if (mb_strlen($reason) < 5) { setFlashMessage('error', 'Опишите причину жалобы (минимум 5 символов)'); redirect($back); }
if (mb_strlen($reason) > 500) { setFlashMessage('error', 'Причина слишком длинная (максимум 500 символов)'); redirect($back); }

$db = Database::getInstance()->getConnection();
$u = getCurrentUser();

$table = $type === 'post' ? 'posts' : 'topics';
$stmt = $db->prepare("SELECT id FROM `$table` WHERE id = ?");
$stmt->execute([$id]);
if (!$stmt->fetch()) { setFlashMessage('error', 'Объект не найден'); redirect($back); }

try {
    $stmt = $db->prepare("INSERT INTO reports (user_id, target_type, target_id, reason) VALUES (?,?,?,?)");
    $stmt->execute([$u['id'], $type, $id, $reason]);
    setFlashMessage('success', 'Жалоба отправлена модераторам');
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        setFlashMessage('info', 'Вы уже жаловались на этот объект');
    } else {
        error_log('Report error: ' . $e->getMessage());
        setFlashMessage('error', 'Ошибка при отправке жалобы');
    }
}
redirect($back);