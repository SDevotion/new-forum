<?php
// edit/index.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [3.4/приоритет#7] edit_version ОБЯЗАТЕЛЕН в POST: отсутствие/массив → отказ,
//       а не молчаливая подстановка свежего edit_count (защита от lost update
//       больше не отключается старым клиентом).
// [1.5] Новые вложения сохраняются внутри транзакции правки; при rollback — удаляются.
// [1.6-паттерн] Лимитер после валидации; отклонённая правка возвращает квоту;
//       конфликт версий тоже возвращает квоту (это не злоупотребление).
// [6.массовый 500] Ввод через postStr()/postInt(); attach_remove[] фильтруется is_scalar.
// Оптимистичная блокировка, скрытие ревизий стафф-правки, PRG — сохранены.

require_once __DIR__ . '/../includes/editing.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/ratelimit.php';

if (!isLoggedIn()) { setFlashMessage('error', 'Необходимо войти'); redirect('/auth/login.php'); }
enforceActiveUser();

$db = Database::getInstance()->getConnection();
$me = getCurrentUser();
$isStaff = isModerator();

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$type = ($isPost ? postStr('type') : getStr('type')) === 'post' ? 'post' : 'topic';
$id = $isPost ? postInt('id') : getInt('id');

if ($type === 'topic') {
    $stmt = $db->prepare("
        SELECT t.id, t.user_id, t.title, t.content, t.created_at, t.forum_id,
               t.is_hidden, t.is_closed, t.edit_count,
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
               p.is_hidden, t.is_closed, p.edit_count,
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

if (!$obj || ($obj['is_hidden'] && !$isStaff)) {
    setFlashMessage('error', 'Объект не найден');
    redirect('/index.php');
}

$backUrl = $type === 'topic'
    ? '/topic.php?id=' . $id
    : '/topic.php?id=' . (int)$obj['topic_id'] . '#post-' . $id;

$editFlags = ['is_hidden' => (bool)$obj['is_hidden'], 'topic_closed' => (bool)$obj['is_closed']];
if (!canEdit($obj['user_id'], $obj['created_at'], $editFlags)) {
    setFlashMessage('error', 'Редактирование недоступно: истёк срок правки, объект скрыт или тема закрыта');
    redirect($backUrl);
}

$attachExisting = attachForObject($type, $id);

$pageTitle = $type === 'topic' ? 'Редактирование темы' : 'Редактирование сообщения';
$errors = [];
$title = $obj['title'] ?? '';
$content = $obj['content'];
$reason = '';

if ($isPost) {
    if (!verifyCSRFToken(postStr('csrf_token'))) {
        $errors[] = 'Недействительный запрос. Обновите страницу.';
    } else {
        $content = trim(postStr('content'));
        $reason  = trim(postStr('reason'));
        if ($type === 'topic') $title = trim(postStr('title'));

        $titleMin = setting('title_min', 5);
        $titleMax = setting('title_max', 200);
        $msgMax   = setting('message_max', 20000);

        if ($type === 'topic') {
            if (mb_strlen($title) < $titleMin) $errors[] = "Заголовок должен содержать минимум $titleMin символов";
            elseif (mb_strlen($title) > $titleMax) $errors[] = "Заголовок не должен превышать $titleMax символов";
        }

        $minLen = $type === 'topic' ? setting('topic_min', 10) : setting('reply_min', 2);
        if (mb_strlen($content) < $minLen) $errors[] = "Сообщение должно содержать минимум $minLen символов";
        elseif (mb_strlen($content) > $msgMax) $errors[] = "Сообщение слишком длинное (максимум $msgMax символов)";
        if (mb_strlen($reason) > 255) $errors[] = 'Причина правки: максимум 255 символов';

        // edit_version обязателен: без него оптимистичная блокировка не работает.
        $rawVersion = $_POST['edit_version'] ?? null;
        $baseVersion = null;
        if (!is_scalar($rawVersion) || !ctype_digit((string)$rawVersion)) {
            $errors[] = 'Форма устарела. Откройте редактор заново.';
        } else {
            $baseVersion = (int)$rawVersion;
        }

        $removeIds = [];
        foreach ((array)($_POST['attach_remove'] ?? []) as $rid) {
            if (is_scalar($rid)) $removeIds[] = (int)$rid;
        }
        $validIds = array_map('intval', array_column($attachExisting, 'id'));
        $removeIds = array_values(array_intersect($removeIds, $validIds));

        $attachFiles = attachNormalizeFiles();
        $remaining = count($attachExisting) - count($removeIds);
        $errors = array_merge($errors, attachValidate($attachFiles, $remaining));

        $unchanged = ($content === $obj['content']) && ($type !== 'topic' || $title === $obj['title'])
                     && !$attachFiles && !$removeIds;
        if (empty($errors) && $unchanged) {
            setFlashMessage('info', 'Изменений не внесено');
            redirect($backUrl);
        }

        // Лимитер — после валидации.
        if (empty($errors)) {
            $rlWindow = max(60, setting('rl_edit_window', 600));
            if (!rateLimitCheck('content_edit', max(1, setting('rl_edit_max', 20)), $rlWindow)) {
                $errors[] = 'Слишком много правок подряд. Подождите ' . ceil(rateLimitRetryAfter('content_edit', $rlWindow) / 60) . ' мин.';
            }
        }

        if (empty($errors)) {
            $savedFiles = [];
            $removedNames = [];
            try {
                $db->beginTransaction();
                $hideRevision = $isStaff && (int)$me['id'] !== (int)$obj['user_id'];
                saveRevision($type, $id, $obj['title'], $obj['content'], $reason, $hideRevision);

                if ($type === 'topic') {
                    $st = $db->prepare("
                        UPDATE topics
                        SET title = ?, content = ?, edited_at = NOW(), edited_by = ?, edit_count = edit_count + 1
                        WHERE id = ? AND edit_count = ?
                    ");
                    $st->execute([$title, $content, $me['id'], $id, $baseVersion]);
                } else {
                    $st = $db->prepare("
                        UPDATE posts
                        SET content = ?, updated_at = NOW(), edited_by = ?, edit_count = edit_count + 1
                        WHERE id = ? AND edit_count = ?
                    ");
                    $st->execute([$content, $me['id'], $id, $baseVersion]);
                }
                if ($st->rowCount() === 0) {
                    $db->rollBack();
                    rateLimitRefund('content_edit');
                    setFlashMessage('error', 'Сообщение уже изменено кем-то другим. Откройте редактор заново.');
                    redirect($backUrl);
                }

                if ($removeIds) {
                    $in = implode(',', array_fill(0, count($removeIds), '?'));
                    $st = $db->prepare("SELECT stored_name FROM attachments WHERE target_type = ? AND target_id = ? AND id IN ($in)");
                    $st->execute(array_merge([$type, $id], $removeIds));
                    $removedNames = $st->fetchAll(PDO::FETCH_COLUMN);
                    $db->prepare("DELETE FROM attachments WHERE target_type = ? AND target_id = ? AND id IN ($in)")
                       ->execute(array_merge([$type, $id], $removeIds));
                }

                // Новые файлы — в той же транзакции.
                $savedFiles = attachStore($attachFiles, $type, $id, (int)$me['id']);

                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                foreach ($savedFiles as $sn) attachUnlink($sn);
                rateLimitRefund('content_edit');
                error_log('Edit error: ' . $e->getMessage());
                $errors[] = 'Ошибка при сохранении. Попробуйте позже.';
                $savedFiles = null; // сигнал: не продолжать
            }

            if ($savedFiles !== null) {
                foreach ($removedNames as $sn) attachUnlink($sn);
                setFlashMessage('success', 'Изменения сохранены');
                redirect($backUrl);
            }
        } elseif ($isPost) {
            // Валидация отклонила форму — квота не списывалась (лимитер после валидации),
            // refund не нужен; ничего не делаем.
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
        <?php if (!$isStaff): ?> · Правка доступна в течение <?= (int)setting('edit_window_hours', 24) ?> ч. после публикации<?php endif; ?>
    </p>

    <?php if ($obj['is_hidden']): ?>
        <div class="hidden-note">⚠️ Объект скрыт модератором. Правка не снимает скрытие.</div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-error"><ul class="error-list"><?php foreach ($errors as $e): ?><li><?= escape($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="POST" action="" id="editForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
        <input type="hidden" name="type" value="<?= $type ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="edit_version" value="<?= (int)$obj['edit_count'] ?>">

        <?php if ($type === 'topic'): ?>
            <div class="form-group">
                <label for="title">Заголовок темы</label>
                <input type="text" id="title" name="title" class="form-control" value="<?= escape($title) ?>" maxlength="<?= setting('title_max', 200) ?>" required>
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label for="content">Текст сообщения</label>
            <?php
            $mdName = 'content'; $mdId = 'content'; $mdValue = $content;
            $mdRows = 12; $mdRequired = true;
            require __DIR__ . '/../includes/md_editor.php';
            ?>
            <small class="form-hint">Символов: <span id="charCount">0</span> / <?= setting('message_max', 20000) ?></small>
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