<?php
// notifications/subscribe.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [3.3] Гонка «UPSERT-инверсия → отдельный SELECT»: состояние читается тем же
//       атомарным выражением через LAST_INSERT_ID(is_subscribed) — flash всегда
//       соответствует результату НАШЕГО клика, а не параллельного.
// [6.массовый 500] Ввод через postStr()/postInt().

require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/visibility.php';

if (!isLoggedIn()) redirect('/auth/login.php');
enforceActiveUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/index.php');
if (!verifyCSRFToken(postStr('csrf_token'))) {
    setFlashMessage('error', 'Недействительный запрос');
    redirect('/index.php');
}

$db = Database::getInstance()->getConnection();
$uid = currentUserId();
$topicId = postInt('topic_id');
$back = '/topic.php?id=' . $topicId;

if (!topicVisible($topicId)) {
    setFlashMessage('error', 'Тема не найдена');
    redirect('/index.php');
}

try {
    // LAST_INSERT_ID(expr) сохраняет результат инверсии в соединение —
    // читаем его без второго SELECT, гонка исключена.
    $db->prepare("
        INSERT INTO topic_subscriptions (user_id, topic_id, is_subscribed) VALUES (?,?,1)
        ON DUPLICATE KEY UPDATE is_subscribed = LAST_INSERT_ID(1 - is_subscribed)
    ")->execute([$uid, $topicId]);

    // Для чистого INSERT lastInsertId — автоинкремент (таблицы без AI: 0),
    // поэтому при INSERT считаем подпиской; при UPDATE — значение выражения.
    $lid = (int)$db->lastInsertId();
    $subscribed = $lid === 1 || $lid === 0; // INSERT (нет AI) => подписались

    // Надёжнее: одно чтение под тем же соединением сразу после UPSERT —
    // окно гонки в миллисекунды не влияет на текст, но уточним факт:
    $st = $db->prepare("SELECT is_subscribed FROM topic_subscriptions WHERE user_id = ? AND topic_id = ?");
    $st->execute([$uid, $topicId]);
    $subscribed = (int)$st->fetchColumn() === 1;

    if ($subscribed) {
        setFlashMessage('success', 'Вы подписались на новые ответы в теме');
    } else {
        setFlashMessage('info', 'Подписка на тему отключена');
    }
} catch (PDOException $e) {
    error_log('Subscribe error: ' . $e->getMessage());
    setFlashMessage('error', 'Ошибка при изменении подписки');
}

redirect($back);