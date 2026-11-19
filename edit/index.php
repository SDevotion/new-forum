<?php
require_once __DIR__ . '/../includes/editing.php';
require_once __DIR__ . '/../includes/attachments.php';

if (!isLoggedIn()) { setFlashMessage('error', 'Необходимо войти'); redirect('/auth/login.php'); }

$db = Database::getInstance()->getConnection();
$me = getCurrentUser();

$type = ($_REQUEST['type'] ?? '') === 'post' ? 'post' : 'topic';
$id = (int)($_REQUEST['id'] ?? 0);

if ($type === 'topic') {
    $stmt = $db->prepare("
        SELECT t.id, t.user_id, t.title, t.content, t.created_at, t.forum_id,
               f.title AS context_title
        FROM topics t
        JOIN forums f ON f.id = t.forum_id
        JOIN categories c ON c.id = f.category_id
        WHERE t.id = ? AND f.is_active = 1 AND c.is_active = 1
    ");
    $stmt->execute([$id]);
} else {
    $stmt = $db->prepare("
        SELECT p.id, p.user_id, NULL AS title, p.content, p.created_at, p.topic_id,
               t.title AS context_title
        FROM posts p
        JOIN topics t ON t.id = p.topic_id
        JOIN forums f ON f.id = t.forum_id
        JOIN categories c ON c.id = f.category_id
        WHERE p.id = ? AND f.is_active = 1 AND c.is_active = 1
    ");
    $stmt->execute([$id]);
}
$obj = $stmt->fetch();

if (!$obj) { setFlashMessage('error', 'Объект не найден'); redirect('/index.php'); }

$backUrl = $type === 'topic'
    ? '/topic.php?id=' . $id
    : '/topic.php?id=' . (int)$obj['topic_id'] . '#post-' . $id;

if (!canEdit($obj['user_id'], $obj['created_at'])) {
    setFlashMessage('error', 'Редактирование недоступно: истёк срок правки или нет прав');
    redirect($backUrl);
}

$attachExisting = attachForObject($type, $id);

$pageTitle = $type === 'topic' ? 'Редактирование темы' : 'Редактирование сообщения';
$errors = [];
$title = $obj['title'] ?? '';
$content = $obj['content'];
$reason = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Недействительный запрос. Обновите страницу.';
    } else {
        $content = trim($_POST['content'] ?? '');
        $reason  = trim($_POST['reason'] ?? '');
        if ($type === 'topic') $title = trim($_POST['title'] ?? '');

        if ($type === 'topic') {
            if (mb_strlen($title) < 5) $errors[] = 'Заголовок должен содержать минимум 5 символов';
            elseif (mb_strlen($title) > 200) $errors[] = 'Заголовок не должен превышать 200 символов';
        }

        $minLen = $type === 'topic' ? 10 : 2;
        if (mb_strlen($content) < $minLen) $errors[] = "Сообщение должно содержать минимум $minLen символов";
        elseif (mb_strlen($content) > 20000) $errors[] = 'Сообщение слишком длинное (максимум 20000 символов)';
        if (mb_strlen($reason) > 255) $errors[] = 'Причина правки: максимум 255 символов';

        $attachFiles = attachNormalizeFiles();
        $errors = array_merge($errors, attachValidate($attachFiles));

        // отмеченные к удалению — только вложения этого объекта
        $removeIds = array_map('intval', (array)($_POST['attach_remove'] ?? []));
        $validIds = array_map('intval', array_column($attachExisting, 'id'));
        $removeIds = array_values(array_intersect($removeIds, $validIds));

        $unchanged = ($content === $obj['content']) && ($type !== 'topic' || $title === $obj['title'])
                     && !$attachFiles && !$removeIds;
        if (empty($errors) && $unchanged) {
            setFlashMessage('info', 'Изменений не внесено');
            redirect($backUrl);
        }

        if (empty($errors)) {
            try {
                $db->beginTransaction();
                saveRevision($type, $id, $obj['title'], $obj['content'], $reason);

                if ($type === 'topic') {
                    $db->prepare("
                        UPDATE topics
                        SET title = ?, content = ?, edited_at = NOW(), edited_by = ?, edit_count = edit_count + 1
                        WHERE id = ?
                    ")->execute([$title, $content, $me['id'], $id]);
                } else {
                    $db->prepare("
                        UPDATE posts
                        SET content = ?, updated_at = NOW(), edited_by = ?, edit_count = edit_count + 1
                        WHERE id = ?
                    ")->execute([$content, $me['id'], $id]);
                }
                // Удаление отмеченных вложений (файлы с диска — только после commit)
                $removedNames = [];
                if ($removeIds) {
                    $in = implode(',', array_fill(0, count($removeIds), '?'));
                    $st = $db->prepare("SELECT stored_name FROM attachments WHERE target_type = ? AND target_id = ? AND id IN ($in)");
                    $st->execute(array_merge([$type, $id], $removeIds));
                    $removedNames = $st->fetchAll(PDO::FETCH_COLUMN);
                    $db->prepare("DELETE FROM attachments WHERE target_type = ? AND target_id = ? AND id IN ($in)")
                       ->execute(array_merge([$type, $id], $removeIds));
                }
                $db->commit();
                foreach ($removedNames as $sn) attachUnlink($sn);

                // Новые вложения
                try {
                    attachStore($attachFiles, $type, $id, (int)$me['id']);
                } catch (RuntimeException $e) {
                    error_log('Attach store error: ' . $e->getMessage());
                    setFlashMessage('info', 'Изменения сохранены, но часть вложений не удалось сохранить');
                    redirect($backUrl);
                }

                setFlashMessage('success', 'Изменения сохранены');
                redirect($backUrl);
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Edit error: ' . $e->getMessage());
                $errors[] = 'Ошибка при сохранении. Попробуйте позже.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<nav class="breadcrumbs">
    <a href="/index.php">Главная</a><span class="sep">›</span>
    <a href="<?= escape($backUrl) ?>">Назад к теме</a><span class="sep">›</span>
    <span class="current">Редактирование</span>
</nav>

<div class="form-card">
    <h1 class="form-card-title"><?= $type === 'topic' ? 'Редактирование темы' : 'Редактирование сообщения' ?></h1>
    <p class="form-card-subtitle">
        <?= escape($obj['context_title']) ?>
        <?php if (!isModerator()): ?> · Правка доступна в течение 24 часов после публикации<?php endif; ?>
    </p>

    <?php if ($errors): ?>
        <div class="alert alert-error"><ul class="error-list"><?php foreach ($errors as $e): ?><li><?= escape($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="POST" action="" id="editForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
        <input type="hidden" name="type" value="<?= $type ?>">
        <input type="hidden" name="id" value="<?= $id ?>">

        <?php if ($type === 'topic'): ?>
            <div class="form-group">
                <label for="title">Заголовок темы</label>
                <input type="text" id="title" name="title" class="form-control" value="<?= escape($title) ?>" maxlength="200" required>
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label for="content">Текст сообщения</label>
            <?php
            $mdName = 'content'; $mdId = 'content'; $mdValue = $content;
            $mdRows = 12; $mdRequired = true;
            require __DIR__ . '/../includes/md_editor.php';
            ?>
            <small class="form-hint">Символов: <span id="charCount">0</span> / 20000</small>
        </div>

        <div class="form-group">
            <?php require __DIR__ . '/../includes/attach_upload.php'; ?>
        </div>

        <div class="form-group">
            <label for="reason">Причина правки <span class="label-optional">(необязательно)</span></label>
            <input type="text" id="reason" name="reason" class="form-control" value="<?= escape($reason) ?>" maxlength="255" placeholder="Исправил опечатку, дополнил ответ...">
            <small class="form-hint">Отобразится в истории правок</small>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Сохранить изменения</button>
            <a href="<?= escape($backUrl) ?>" class="btn btn-outline">Отмена</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>