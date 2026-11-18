<?php
require_once __DIR__ . '/../includes/notifications.php';

if (!isLoggedIn()) redirect('/auth/login.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/notifications.php');
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('error', 'Недействительный запрос');
    redirect('/notifications.php');
}

$db = Database::getInstance()->getConnection();
$uid = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$back = $_POST['back'] ?? '/notifications.php';
if (!preg_match('#^/[A-Za-z0-9_./?=&#%-]*$#', $back)) $back = '/notifications.php';

try {
    if ($action === 'read_all') {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$uid]);
        setFlashMessage('success', 'Все уведомления прочитаны');

    } elseif ($action === 'delete_all') {
        $db->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1")->execute([$uid]);
        setFlashMessage('success', 'Прочитанные уведомления удалены');

    } elseif ($action === 'read') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT topic_id, post_id FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $uid]);
        $n = $stmt->fetch();

        if ($n) {
            $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
            $url = '/topic.php?id=' . (int)$n['topic_id'];
            if ($n['post_id']) $url .= '#post-' . (int)$n['post_id'];
            redirect($url);
        }
        setFlashMessage('error', 'Уведомление не найдено');

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
        setFlashMessage('success', 'Уведомление удалено');
    }
} catch (PDOException $e) {
    error_log('Notification action error: ' . $e->getMessage());
    setFlashMessage('error', 'Ошибка при выполнении действия');
}

redirect($back);