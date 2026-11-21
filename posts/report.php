<?php
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/ratelimit.php';
require_once __DIR__ . '/../includes/visibility.php';

if (!isLoggedIn()) { setFlashMessage('error', 'Необходимо войти'); redirect('/auth/login.php'); }
enforceActiveUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/index.php');
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) { setFlashMessage('error', 'Недействительный запрос'); redirect('/index.php'); }

$type = ($_POST['target_type'] ?? '') === 'post' ? 'post' : 'topic';
$id = (int)($_POST['target_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$back = $_POST['back'] ?? '/index.php';
if (!preg_match('~^/[A-Za-z0-9_./?=&#%-]*$~', $back)) $back = '/index.php';

if ($id <= 0) { setFlashMessage('error', 'Объект не найден'); redirect($back); }

$reportMin = setting('report_min', 5);
$reportMax = setting('report_max', 500);
if (mb_strlen($reason) < $reportMin) { setFlashMessage('error', "Опишите причину жалобы (минимум $reportMin символов)"); redirect($back); }
if (mb_strlen($reason) > $reportMax) { setFlashMessage('error', "Причина слишком длинная (максимум $reportMax символов)"); redirect($back); }

$db = Database::getInstance()->getConnection();
$u = getCurrentUser();

// Видимость объекта: скрытые темы/посты и неактивные разделы не принимаются
// (и не подтверждают своё существование). Единые правила из visibility.php.
$visible = $type === 'post' ? postVisible($id) : topicVisible($id);
if (!$visible) { setFlashMessage('error', 'Объект не найден'); redirect($back); }

$table = $type === 'post' ? 'posts' : 'topics';
$stmt = $db->prepare("SELECT id, user_id FROM `$table` WHERE id = ?");
$stmt->execute([$id]);
$targetObj = $stmt->fetch();
if (!$targetObj) { setFlashMessage('error', 'Объект не найден'); redirect($back); }
if ((int)$targetObj['user_id'] === (int)$u['id']) {
    setFlashMessage('error', 'Нельзя пожаловаться на собственное сообщение');
    redirect($back);
}

// Лимитер — только после всех проверок, чтобы не тратить квоту на невалидные запросы
if (!rateLimitCheck('report', max(1, setting('rl_report_max', 5)), max(60, setting('rl_report_window', 3600)))) {
    setFlashMessage('error', 'Слишком много жалоб за последнее время. Попробуйте позже.');
    redirect($back);
}

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
