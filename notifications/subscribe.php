<?php
require_once __DIR__ . '/../includes/notifications.php';

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

// Видимость темы: скрытые/неактивные разделы не подтверждают своё существование.
$staffFlag = isModerator() ? 1 : 0;
$stmt = $db->prepare("
    SELECT t.id FROM topics t
    JOIN forums f ON f.id = t.forum_id
    JOIN categories c ON c.id = f.category_id
    WHERE t.id = ? AND f.is_active = 1 AND c.is_active = 1 AND (t.is_hidden = 0 OR ? = 1)
");
$stmt->execute([$topicId, $staffFlag]);
if (!$stmt->fetch()) { setFlashMessage('error', 'Тема не найдена'); redirect('/index.php'); }

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
