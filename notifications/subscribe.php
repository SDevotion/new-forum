<?php
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/visibility.php';

if (!isLoggedIn()) redirect('/auth/login.php');
enforceActiveUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/index.php');
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('error', 'Недействительный запрос');
    redirect('/index.php');
}

$db = Database::getInstance()->getConnection();
$uid = currentUserId();
$topicId = (int)($_POST['topic_id'] ?? 0);
$back = '/topic.php?id=' . $topicId;

// Видимость темы — единые правила (скрытые не подтверждают существование)
if (!topicVisible($topicId)) {
    setFlashMessage('error', 'Тема не найдена');
    redirect('/index.php');
}

try {
    $stmt = $db->prepare("SELECT 1 FROM topic_subscriptions WHERE user_id = ? AND topic_id = ?");
    $stmt->execute([$uid, $topicId]);

    if ($stmt->fetchColumn()) {
        $db->prepare("DELETE FROM topic_subscriptions WHERE user_id = ? AND topic_id = ?")->execute([$uid, $topicId]);
        setFlashMessage('info', 'Подписка на тему отключена');
    } else {
        $db->prepare("INSERT IGNORE INTO topic_subscriptions (user_id, topic_id) VALUES (?,?)")->execute([$uid, $topicId]);
        setFlashMessage('success', 'Вы подписались на новые ответы в теме');
    }
} catch (PDOException $e) {
    error_log('Subscribe error: ' . $e->getMessage());
    setFlashMessage('error', 'Ошибка при изменении подписки');
}

redirect($back);
