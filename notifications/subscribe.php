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
    // Атомарная инверсия одним UPSERT: нет строки -> создаём подписку (1);
    // есть -> 1 превращается в 0 (явный отказ, автоподписка его больше не вернёт),
    // 0 превращается в 1. DELETE не используем — состояние "отписан" должно храниться.
    $db->prepare("
        INSERT INTO topic_subscriptions (user_id, topic_id, is_subscribed) VALUES (?,?,1)
        ON DUPLICATE KEY UPDATE is_subscribed = 1 - is_subscribed
    ")->execute([$uid, $topicId]);

    $stmt = $db->prepare("SELECT is_subscribed FROM topic_subscriptions WHERE user_id = ? AND topic_id = ?");
    $stmt->execute([$uid, $topicId]);
    if ((int)$stmt->fetchColumn() === 1) {
        setFlashMessage('success', 'Вы подписались на новые ответы в теме');
    } else {
        setFlashMessage('info', 'Подписка на тему отключена');
    }
} catch (PDOException $e) {
    error_log('Subscribe error: ' . $e->getMessage());
    setFlashMessage('error', 'Ошибка при изменении подписки');
}

redirect($back);
