<?php
// edit/restore.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [new] Откат защищён оптимистичной блокировкой: UPDATE ... WHERE edit_count = ?
//       (версия читается в той же транзакции под FOR UPDATE) — параллельная правка
//       автора не теряется молчаливо.
// [6.массовый 500] Ввод через postStr()/postInt().
// [new] saveRevision и UPDATE в одной транзакции (было так же), но чтение текущей
//       версии перенесено ВНУТРЬ транзакции под блокировку — раньше cur читался до неё.
// Требование requireModerator, скрытая ревизия отката, журнал — сохранены.

require_once __DIR__ . '/../includes/editing.php';
require_once __DIR__ . '/../includes/visibility.php';
requireModerator();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/index.php');
if (!verifyCSRFToken(postStr('csrf_token'))) { setFlashMessage('error', 'Недействительный запрос'); redirect('/index.php'); }

$db = Database::getInstance()->getConnection();
$me = getCurrentUser();
$revId = postInt('revision_id');

$stmt = $db->prepare("SELECT * FROM post_edits WHERE id = ?");
$stmt->execute([$revId]);
$rev = $stmt->fetch();

if (!$rev) { setFlashMessage('error', 'Версия не найдена'); redirect('/index.php'); }

$type = $rev['target_type'];
$id = (int)$rev['target_id'];
$backUrl = '/index.php';

$exists = $type === 'topic' ? topicVisible($id) : postVisible($id);
if (!$exists) { setFlashMessage('error', 'Объект больше недоступен'); redirect('/index.php'); }

try {
    $db->beginTransaction();

    if ($type === 'topic') {
        $stmt = $db->prepare("SELECT title, content, edit_count FROM topics WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $cur = $stmt->fetch();
        if (!$cur) {
            $db->rollBack();
            setFlashMessage('error', 'Тема не найдена');
            redirect('/index.php');
        }

        saveRevision('topic', $id, $cur['title'], $cur['content'], 'Откат к версии #' . $revId, true);
        $st = $db->prepare("
            UPDATE topics SET title = ?, content = ?, edited_at = NOW(), edited_by = ?, edit_count = edit_count + 1
            WHERE id = ? AND edit_count = ?
        ");
        $st->execute([$rev['old_title'], $rev['old_content'], $me['id'], $id, (int)$cur['edit_count']]);
        $backUrl = '/topic.php?id=' . $id;
    } else {
        $stmt = $db->prepare("SELECT content, topic_id, edit_count FROM posts WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $cur = $stmt->fetch();
        if (!$cur) {
            $db->rollBack();
            setFlashMessage('error', 'Сообщение не найдено');
            redirect('/index.php');
        }

        saveRevision('post', $id, null, $cur['content'], 'Откат к версии #' . $revId, true);
        $st = $db->prepare("
            UPDATE posts SET content = ?, updated_at = NOW(), edited_by = ?, edit_count = edit_count + 1
            WHERE id = ? AND edit_count = ?
        ");
        $st->execute([$rev['old_content'], $me['id'], $id, (int)$cur['edit_count']]);
        $backUrl = '/topic.php?id=' . (int)$cur['topic_id'] . '#post-' . $id;
    }

    if ($st->rowCount() === 0) {
        $db->rollBack();
        setFlashMessage('error', 'Объект изменился параллельно. Обновите историю и повторите.');
        redirect($backUrl);
    }
    $db->commit();

    logModeration('restore_revision', $type, $id, 'версия #' . $revId);
    setFlashMessage('success', 'Версия восстановлена');
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Restore error: ' . $e->getMessage());
    setFlashMessage('error', 'Ошибка при восстановлении');
}

redirect($backUrl);