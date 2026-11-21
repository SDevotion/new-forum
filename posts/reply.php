<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/ratelimit.php';

$topicId = (int)($_POST['topic_id'] ?? 0);
$backUrl = '/topic.php?id=' . $topicId;

if (!isLoggedIn()) {
    setFlashMessage('error', 'Чтобы отвечать в темах, необходимо войти');
    redirect('/auth/login.php');
}

if (setting('forum_closed', false) && !isModerator()) {
    setFlashMessage('error', setting('forum_closed_message', 'Форум временно закрыт для новых сообщений'));
    redirect($backUrl);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($backUrl);
}

if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('error', 'Недействительный запрос. Обновите страницу и попробуйте снова.');
    redirect($backUrl);
}

$db = Database::getInstance()->getConnection();
$currentUser = getCurrentUser();

$stmt = $db->prepare("
    SELECT t.id, t.is_closed, t.is_hidden
    FROM topics t
    JOIN forums f ON f.id = t.forum_id
    JOIN categories c ON c.id = f.category_id
    WHERE t.id = ? AND f.is_active = 1 AND c.is_active = 1
");
$stmt->execute([$topicId]);
$topic = $stmt->fetch();

if (!$topic) {
    setFlashMessage('error', 'Тема не найдена');
    redirect('/index.php');
}

if ($topic['is_hidden']) {
    setFlashMessage('error', 'Тема недоступна для обсуждения');
    redirect('/index.php');
}

if ($topic['is_closed']) {
    setFlashMessage('error', 'Тема закрыта для новых ответов');
    redirect($backUrl);
}

$content = trim($_POST['content'] ?? '');

$replyMin = setting('reply_min', 2);
$msgMax   = setting('message_max', 20000);

if (mb_strlen($content) < $replyMin) {
    setFlashMessage('error', "Сообщение должно содержать минимум $replyMin символов");
    redirect($backUrl);
}

if (mb_strlen($content) > $msgMax) {
    setFlashMessage('error', "Сообщение слишком длинное (максимум $msgMax символов)");
    redirect($backUrl);
}

// Ограничение частоты ответов (антифлуд)
if (!rateLimitCheck('post_reply', max(1, setting('rl_reply_max', 10)), max(60, setting('rl_reply_window', 300)))) {
    setFlashMessage('error', 'Слишком много сообщений подряд. Подождите ' . ceil(rateLimitRetryAfter('post_reply', max(60, setting('rl_reply_window', 300))) / 60) . ' мин.');
    redirect($backUrl);
}

// Вложения
$attachFiles = attachNormalizeFiles();
$attachErrors = attachValidate($attachFiles);
if ($attachErrors) {
    setFlashMessage('error', implode('; ', $attachErrors));
    redirect($backUrl);
}

// Защита от дублей (двойная отправка / обновление страницы; учитываем и файлы).
// Хэш живёт в user_sessions.payload; список ограничен 200 темами (FIFO).
$hash = md5($content . serialize(array_map(fn($f) => [$f['name'], $f['size']], $attachFiles)));
if (($_SESSION['last_post_hash'][$topicId] ?? null) === $hash) {
    setFlashMessage('info', 'Такое сообщение уже отправлено');
    redirect($backUrl);
}

try {
    $stmt = $db->prepare("INSERT INTO posts (topic_id, user_id, content) VALUES (?, ?, ?)");
    $stmt->execute([$topicId, $currentUser['id'], $content]);
    $postId = (int)$db->lastInsertId();

    try {
        attachStore($attachFiles, 'post', $postId, (int)$currentUser['id']);
    } catch (Throwable $e) {
        error_log('Attach store error: ' . $e->getMessage());
        setFlashMessage('info', 'Ответ опубликован, но часть вложений не удалось сохранить');
    }

    if (!isset($_SESSION['last_post_hash']) || !is_array($_SESSION['last_post_hash'])) {
        $_SESSION['last_post_hash'] = [];
    }
    $_SESSION['last_post_hash'][$topicId] = $hash;
    if (count($_SESSION['last_post_hash']) > 200) {
        $_SESSION['last_post_hash'] = array_slice($_SESSION['last_post_hash'], -200, null, true);
    }

    // Подписка автора ответа на тему + рассылка уведомлений
    subscribeTopic($currentUser['id'], $topicId);
    notifyTopicReply($topicId, $postId, $currentUser['id'], $content);

    // Страница, на которой окажется новый ответ: позиция среди видимых постов
    // (свежий COUNT после вставки; учитываем права стаффа на скрытые посты)
    $perPage = max(1, setting('per_page_posts', 15));
    $staffFlag = isModerator() ? 1 : 0;
    $st = $db->prepare("
        SELECT COUNT(*) FROM posts
        WHERE topic_id = ? AND (is_hidden = 0 OR ? = 1)
          AND (created_at < (SELECT created_at FROM posts WHERE id = ?)
               OR (created_at = (SELECT created_at FROM posts WHERE id = ?) AND id <= ?))
    ");
    $st->execute([$topicId, $staffFlag, $postId, $postId, $postId]);
    $pos = max(1, (int)$st->fetchColumn());
    $page = (int)ceil($pos / $perPage);

    setFlashMessage('success', 'Ответ опубликован');
    redirect('/topic.php?id=' . $topicId . '&page=' . $page . '#post-' . $postId);
} catch (PDOException $e) {
    error_log("Post create error: " . $e->getMessage());
    setFlashMessage('error', 'Ошибка при отправке сообщения. Попробуйте позже.');
    redirect($backUrl);
}
