<?php
// posts/delete.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [6.мёртвый код] unset($_SESSION['last_post_hash']) удалён — легаси-ключ нигде
//       не используется.
// [1.5] Имена файлов вложений собираются ВНУТРИ транзакции (после SELECT поста
//       FOR UPDATE) — конкурентная правка с добавлением вложения не оставит сироту.
// [6.массовый 500] Ввод через postStr()/postInt().
// Явные recount после DELETE (каскад без триггеров), журнал стафф-удаления — сохранены.

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/counters.php';

$topicId = postInt('topic_id');
$postId = postInt('post_id');
$backUrl = '/topic.php?id=' . $topicId;

if (!isLoggedIn()) redirect('/auth/login.php');
enforceActiveUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken(postStr('csrf_token'))) {
    setFlashMessage('error', 'Недействительный запрос');
    redirect($backUrl);
}

$db = Database::getInstance()->getConnection();
$currentUser = getCurrentUser();

$stmt = $db->prepare("
    SELECT p.id, p.user_id, t.forum_id
    FROM posts p JOIN topics t ON t.id = p.topic_id
    WHERE p.id = ? AND p.topic_id = ?
");
$stmt->execute([$postId, $topicId]);
$post = $stmt->fetch();

if (!$post) {
    setFlashMessage('error', 'Сообщение не найдено');
    redirect($backUrl);
}

$isOwner = ((int)$post['user_id'] === (int)$currentUser['id']);
$isStaff = in_array($currentUser['role'], ['moderator', 'admin'], true);

if (!$isOwner && !$isStaff) {
    setFlashMessage('error', 'Недостаточно прав для удаления этого сообщения');
    redirect($backUrl);
}

try {
    $db->beginTransaction();

    // Блокируем пост: параллельная правка/добавление вложений не проскочит.
    $st = $db->prepare("SELECT id FROM posts WHERE id = ? FOR UPDATE");
    $st->execute([$postId]);
    if (!$st->fetch()) {
        $db->rollBack();
        setFlashMessage('info', 'Сообщение уже удалено');
        redirect($backUrl);
    }

    $st = $db->prepare("SELECT stored_name FROM attachments WHERE target_type = 'post' AND target_id = ?");
    $st->execute([$postId]);
    $attachNames = $st->fetchAll(PDO::FETCH_COLUMN);

    $db->prepare("DELETE FROM attachments WHERE target_type = 'post' AND target_id = ?")->execute([$postId]);
    $db->prepare("DELETE FROM post_edits WHERE target_type = 'post' AND target_id = ?")->execute([$postId]);
    $db->prepare("DELETE FROM reports WHERE target_type = 'post' AND target_id = ?")->execute([$postId]);
    $db->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);
    $db->commit();

    foreach ($attachNames as $sn) attachUnlink($sn);

    recountTopic($topicId);
    recountForum((int)$post['forum_id']);
    recountUsers([(int)$post['user_id']]);

    if (!$isOwner) {
        logModeration('post_delete', 'post', $postId, 'автор #' . (int)$post['user_id']);
    }

    setFlashMessage('success', 'Сообщение удалено');
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Post delete error: ' . $e->getMessage());
    setFlashMessage('error', 'Ошибка при удалении сообщения');
}

redirect($backUrl);