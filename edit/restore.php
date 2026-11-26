<?php
require_once __DIR__ . '/../includes/editing.php';
require_once __DIR__ . '/../includes/visibility.php';
requireModerator();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/index.php');
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) { setFlashMessage('error', 'Недействительный запрос'); redirect('/index.php'); }

$db = Database::getInstance()->getConnection();
$me = getCurrentUser();
$revId = (int)($_POST['revision_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM post_edits WHERE id = ?");
$stmt->execute([$revId]);
$rev = $stmt->fetch();

if (!$rev) { setFlashMessage('error', 'Версия не найдена'); redirect('/index.php'); }

$type = $rev['target_type'];
$id = (int)$rev['target_id'];
$backUrl = '/index.php';

// Объект должен существовать в активном разделе (скрытое модератору видно).
$exists = $type === 'topic' ? topicVisible($id) : postVisible($id);
if (!$exists) { setFlashMessage('error', 'Объект больше недоступен'); redirect('/index.php'); }

try {
    if ($type === 'topic') {
        $stmt = $db->prepare("SELECT title, content FROM topics WHERE id = ?");
        $stmt->execute([$id]);
        $cur = $stmt->fetch();
        if (!$cur) { setFlashMessage('error', 'Тема не найдена'); redirect('/index.php'); }

        $db->beginTransaction();
        saveRevision('topic', $id, $cur['title'], $cur['content'], 'Откат к версии #' . $revId, true);
        $db->prepare("UPDATE topics SET title = ?, content = ?, edited_at = NOW(), edited_by = ?, edit_count = edit_count + 1 WHERE id = ?")
           ->execute([$rev['old_title'], $rev['old_content'], $me['id'], $id]);
        $db->commit();

        $backUrl = '/topic.php?id=' . $id;
    } else {
        $stmt = $db->prepare("SELECT content, topic_id FROM posts WHERE id = ?");
        $stmt->execute([$id]);
        $cur = $stmt->fetch();
        if (!$cur) { setFlashMessage('error', 'Сообщение не найдено'); redirect('/index.php'); }

        $db->beginTransaction();
        saveRevision('post', $id, null, $cur['content'], 'Откат к версии #' . $revId, true);
        $db->prepare("UPDATE posts SET content = ?, updated_at = NOW(), edited_by = ?, edit_count = edit_count + 1 WHERE id = ?")
           ->execute([$rev['old_content'], $me['id'], $id]);
        $db->commit();

        $backUrl = '/topic.php?id=' . (int)$cur['topic_id'] . '#post-' . $id;
    }

    logModeration('restore_revision', $type, $id, 'версия #' . $revId);
    setFlashMessage('success', 'Версия восстановлена');
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Restore error: ' . $e->getMessage());
    setFlashMessage('error', 'Ошибка при восстановлении');
}

redirect($backUrl);
