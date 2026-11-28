<?php
// topics/create.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [1.6-паттерн] Лимитер списывается ПОСЛЕ валидации заголовка/текста/вложений;
//       отклонённая форма возвращает квоту (rateLimitRefund).
// [1.5] attachStore внутри транзакции создания темы; при rollback файлы удаляются.
// [6.массовый 500] Ввод через postStr()/getInt()/postInt(); forum_id только из GET/POST int.
// Уведомления об упоминаниях первого сообщения, автоподписка — сохранены.

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/ratelimit.php';

if (!isLoggedIn()) {
    setFlashMessage('error', 'Для создания темы необходимо войти');
    redirect('/auth/login.php');
}
enforceActiveUser();

if (setting('forum_closed', false) && !isModerator()) {
    setFlashMessage('error', setting('forum_closed_message', 'Форум временно закрыт для новых сообщений'));
    redirect('/index.php');
}

$db = Database::getInstance()->getConnection();
$currentUser = getCurrentUser();
$forumId = $_SERVER['REQUEST_METHOD'] === 'POST' ? postInt('forum_id') : getInt('forum_id');

$stmt = $db->prepare("
    SELECT f.*, c.title AS category_title
    FROM forums f
    JOIN categories c ON c.id = f.category_id
    WHERE f.id = ? AND f.is_active = 1 AND c.is_active = 1
");
$stmt->execute([$forumId]);
$forum = $stmt->fetch();

if (!$forum) {
    setFlashMessage('error', 'Раздел не найден');
    redirect('/index.php');
}

$pageTitle = 'Создание темы';
$errors = [];
$title = '';
$content = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken(postStr('csrf_token'))) {
        $errors[] = 'Недействительный запрос. Попробуйте снова.';
    } else {
        $title = trim(postStr('title'));
        $content = trim(postStr('content'));

        $titleMin = setting('title_min', 5);
        $titleMax = setting('title_max', 200);
        $topicMin = setting('topic_min', 10);
        $msgMax   = setting('message_max', 20000);

        if (mb_strlen($title) < $titleMin) {
            $errors[] = "Заголовок должен содержать минимум $titleMin символов";
        } elseif (mb_strlen($title) > $titleMax) {
            $errors[] = "Заголовок не должен превышать $titleMax символов";
        }
        if (mb_strlen($content) < $topicMin) {
            $errors[] = "Сообщение должно содержать минимум $topicMin символов";
        } elseif (mb_strlen($content) > $msgMax) {
            $errors[] = "Сообщение слишком длинное (максимум $msgMax символов)";
        }

        $attachFiles = attachNormalizeFiles();
        $errors = array_merge($errors, attachValidate($attachFiles));

        // Лимитер — только когда форма валидна.
        if (empty($errors)) {
            $rlWindow = max(60, setting('rl_topic_window', 600));
            if (!rateLimitCheck('topic_create', max(1, setting('rl_topic_max', 5)), $rlWindow)) {
                $errors[] = 'Слишком часто создаёте темы. Подождите ' . ceil(rateLimitRetryAfter('topic_create', $rlWindow) / 60) . ' мин.';
            }
        }

        if (empty($errors)) {
            $savedFiles = [];
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("
                    INSERT INTO topics (forum_id, user_id, title, content, last_post_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$forumId, $currentUser['id'], $title, $content]);
                $topicId = (int)$db->lastInsertId();

                $savedFiles = attachStore($attachFiles, 'topic', $topicId, (int)$currentUser['id']);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                foreach ($savedFiles as $sn) attachUnlink($sn);
                rateLimitRefund('topic_create');
                error_log('Topic create error: ' . $e->getMessage());
                $errors[] = 'Ошибка при создании темы. Попробуйте позже.';
                $topicId = 0;
            }

            if (empty($errors)) {
                subscribeTopic($currentUser['id'], $topicId);

                $mentions = parseMentions($content);
                if ($mentions) {
                    $names = array_keys($mentions);
                    $in = implode(',', array_fill(0, count($names), '?'));
                    $st = $db->prepare("SELECT id, username FROM users WHERE username IN ($in) AND is_active = 1");
                    $st->execute($names);
                    $byKind = ['mention' => [], 'quote' => []];
                    foreach ($st->fetchAll() as $row) {
                        $uid = (int)$row['id'];
                        if ($uid === (int)$currentUser['id']) continue;
                        $kind = $mentions[$row['username']] ?? 'mention';
                        $byKind[$kind][] = $uid;
                    }
                    foreach ($byKind as $kind => $ids) {
                        if ($ids) notifyUsersBatch($ids, $kind, (int)$currentUser['id'], $topicId, null, mdExcerpt($content));
                    }
                }

                setFlashMessage('success', 'Тема успешно создана!');
                redirect('/topic.php?id=' . $topicId);
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<nav class="breadcrumbs">
    <a href="/index.php">Главная</a>
    <span class="sep">›</span>
    <a href="/section.php?id=<?= (int)$forum['id'] ?>"><?= escape($forum['title']) ?></a>
    <span class="sep">›</span>
    <span class="current">Новая тема</span>
</nav>

<div class="form-card">
    <h1 class="form-card-title">Создание темы</h1>
    <p class="form-card-subtitle">Раздел: <?= escape($forum['title']) ?></p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul class="error-list">
                <?php foreach ($errors as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="topicForm" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
        <input type="hidden" name="forum_id" value="<?= (int)$forum['id'] ?>">

        <div class="form-group">
            <label for="title">Заголовок темы</label>
            <input type="text" id="title" name="title" class="form-control"
                   value="<?= escape($title) ?>" placeholder="Кратко и по сути"
                   maxlength="<?= setting('title_max', 200) ?>" required>
            <small class="form-hint">От <?= setting('title_min', 5) ?> до <?= setting('title_max', 200) ?> символов</small>
        </div>

        <div class="form-group">
            <label for="content">Первое сообщение</label>
            <?php
            $mdName = 'content'; $mdId = 'content'; $mdValue = $content;
            $mdRows = 10; $mdRequired = true;
            $mdAutosaveId = 'topic-' . (int)$forum['id'];
            require __DIR__ . '/../includes/md_editor.php';
            ?>
            <small class="form-hint">Минимум <?= setting('topic_min', 10) ?> символов. Символов: <span id="charCount">0</span> / <?= setting('message_max', 20000) ?></small>
        </div>

        <div class="form-group">
            <?php require __DIR__ . '/../includes/attach_upload.php'; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Создать тему</button>
            <a href="/section.php?id=<?= (int)$forum['id'] ?>" class="btn btn-outline">Отмена</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>