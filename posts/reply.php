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
    SELECT t.id, t.is_closed, t.is_hidden, t.replies_count
    FROM topics t
    JOIN forums f ON f.id = t.forum_id
    JOIN categories c ON c.id = f.category_id
    WHERE t.id = ? AND f.is_active = 1 AND c.is_active = 1
");
$stmt->execute([$topicId]);
$topic = $stmt->fetch();

if (!$topic) {
    setFlashMessage('error', 'Тема не найдена');
    redirect('/forum.php');
}

if ($topic['is_hidden']) {
    setFlashMessage('error', 'Тема недоступна для обсуждения');
    redirect('/forum.php');
}

if ($topic['is_closed']) {
    setFlashMessage('error', 'Тема закрыта для новых ответов');
    redirect($backUrl);
}

$content = trim($_POST['content'] ?? '');

if (mb_strlen($content) < 2) {
    setFlashMessage('error', 'Сообщение должно содержать минимум 2 символа');
    redirect($backUrl);
}

if (mb_strlen($content) > 20000) {
    setFlashMessage('error', 'Сообщение слишком длинное (максимум 20000 символов)');
    redirect($backUrl);
}

// Ограничение частоты ответов (антифлуд)
if (!rateLimitCheck('post_reply', 10, 300)) {
    setFlashMessage('error', 'Слишком много сообщений подряд. Подождите ' . ceil(rateLimitRetryAfter('post_reply', 300) / 60) . ' мин.');
    redirect($backUrl);
}

// Вложения
$attachFiles = attachNormalizeFiles();
$attachErrors = attachValidate($attachFiles);
if ($attachErrors) {
    setFlashMessage('error', implode('; ', $attachErrors));
    redirect($backUrl);
}

// Защита от дублей (двойная отправка / обновление страницы; учитываем и файлы)
$hash = md5($content . serialize(array_map(fn($f) => [$f['name'], $f['size']], $attachFiles)));
if (isset($_SESSION['last_post_hash'][$topicId]) && $_SESSION['last_post_hash'][$topicId] === $hash) {
    setFlashMessage('info', 'Такое сообщение уже отправлено');
    redirect($backUrl);
}

try {
    $stmt = $db->prepare("INSERT INTO posts (topic_id, user_id, content) VALUES (?, ?, ?)");
    $stmt->execute([$topicId, $currentUser['id'], $content]);
    $postId = (int)$db->lastInsertId();

    try {
        attachStore($attachFiles, 'post', $postId, (int)$currentUser['id']);
    } catch (RuntimeException $e) {
        error_log('Attach store error: ' . $e->getMessage());
        setFlashMessage('info', 'Ответ опубликован, но часть вложений не удалось сохранить');
    }

    $_SESSION['last_post_hash'][$topicId] = $hash;

    // Подписка автора ответа на тему + рассылка уведомлений
    subscribeTopic($currentUser['id'], $topicId);
    notifyTopicReply($topicId, $postId, $currentUser['id'], $content);

    // Страница, на которой окажется новый ответ
    $perPage = 15;
    $page = (int)ceil(((int)$topic['replies_count'] + 1) / $perPage);

    setFlashMessage('success', 'Ответ опубликован');
    redirect('/topic.php?id=' . $topicId . '&page=' . $page . '#post-' . $postId);
} catch (PDOException $e) {
    error_log("Post create error: " . $e->getMessage());
    setFlashMessage('error', 'Ошибка при отправке сообщения. Попробуйте позже.');
    redirect($backUrl);
}