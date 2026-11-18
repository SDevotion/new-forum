<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/attachments.php';

$topicId = (int)($_POST['topic_id'] ?? 0);
$postId = (int)($_POST['post_id'] ?? 0);
$backUrl = '/topic.php?id=' . $topicId;

if (!isLoggedIn()) {
    redirect('/auth/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('error', 'Недействительный запрос');
    redirect($backUrl);
}

$db = Database::getInstance()->getConnection();
$currentUser = getCurrentUser();

$stmt = $db->prepare("SELECT id, user_id FROM posts WHERE id = ? AND topic_id = ?");
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
    $attachRows = attachForObject('post', $postId);
    $db->prepare("DELETE FROM attachments WHERE target_type = 'post' AND target_id = ?")->execute([$postId]);
    $db->prepare("DELETE FROM posts WHERE id = ?")->execute([$postId]);
    foreach ($attachRows as $ar) attachUnlink($ar['stored_name']);
    unset($_SESSION['last_post_hash'][$topicId]);
    setFlashMessage('success', 'Сообщение удалено');
} catch (PDOException $e) {
    error_log("Post delete error: " . $e->getMessage());
    setFlashMessage('error', 'Ошибка при удалении сообщения');
}

redirect($backUrl);