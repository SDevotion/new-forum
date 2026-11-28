<?php
// posts/reply.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [1.6/приоритет#5] Порядок операций исправлен: ВСЕ бизнес-проверки (тема открыта,
//       видима, раздел активен) выполняются ДО списания квоты лимитера и ДО
//       вставки дедуп-ключа. Отклонённая попытка не жжёт лимит и не оставляет
//       дедуп-ключ на час (раньше «Такое сообщение уже отправлено» после закрытой темы).
// [1.5] Вложения сохраняются ВНУТРИ транзакции поста: при rollback файлы пачки
//       удаляются (attachStore возвращает список), сирот и «пост без файлов» нет.
//       Окно «commit прошёл, файлы ещё пишутся» устранено.
// [6.массовый 500] Ввод через postStr()/postInt().
// [new] Дедуп-ключ удаляется при любой ошибке публикации (можно повторить сразу).
// Позиция нового ответа для пагинации, подписка автора, уведомления — сохранены.

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/ratelimit.php';

$topicId = postInt('topic_id');
$backUrl = '/topic.php?id=' . $topicId;

if (!isLoggedIn()) {
    setFlashMessage('error', 'Чтобы отвечать в темах, необходимо войти');
    redirect('/auth/login.php');
}
enforceActiveUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect($backUrl);

if (setting('forum_closed', false) && !isModerator()) {
    setFlashMessage('error', setting('forum_closed_message', 'Форум временно закрыт для новых сообщений'));
    redirect($backUrl);
}

if (!verifyCSRFToken(postStr('csrf_token'))) {
    setFlashMessage('error', 'Недействительный запрос. Обновите страницу и попробуйте снова.');
    redirect($backUrl);
}

$db = Database::getInstance()->getConnection();
$currentUser = getCurrentUser();

$content = trim(postStr('content'));
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

// Вложения: валидация ДО лимитера (невалидные файлы не жгут квоту).
$attachFiles = attachNormalizeFiles();
$attachErrors = attachValidate($attachFiles);
if ($attachErrors) {
    setFlashMessage('error', implode('; ', $attachErrors));
    redirect($backUrl);
}

// Проверка доступности темы ДО лимитера и дедупа (быстрый путь без блокировок).
$stmt = $db->prepare("
    SELECT t.id, t.is_closed, t.is_hidden
    FROM topics t
    JOIN forums f ON f.id = t.forum_id
    JOIN categories c ON c.id = f.category_id
    WHERE t.id = ? AND f.is_active = 1 AND c.is_active = 1
");
$stmt->execute([$topicId]);
$topicPre = $stmt->fetch();
if (!$topicPre || $topicPre['is_hidden']) {
    setFlashMessage('error', 'Тема недоступна для обсуждения');
    redirect('/index.php');
}
if ($topicPre['is_closed']) {
    setFlashMessage('error', 'Тема закрыта для новых ответов');
    redirect($backUrl);
}

// Теперь — антифлуд (все проверки пройдены, попытка засчитывается обоснованно).
$rlWindow = max(60, setting('rl_reply_window', 300));
if (!rateLimitCheck('post_reply', max(1, setting('rl_reply_max', 10)), $rlWindow)) {
    setFlashMessage('error', 'Слишком много сообщений подряд. Подождите ' . ceil(rateLimitRetryAfter('post_reply', $rlWindow) / 60) . ' мин.');
    redirect($backUrl);
}

// Антидубль: UNIQUE-ключ. Вставляется ПОСЛЕ проверок; при ошибке публикации удаляется.
$hash = md5($content . serialize(array_map(fn($f) => [$f['name'], $f['size']], $attachFiles)));
$dupKey = 'u' . (int)$currentUser['id'] . ':' . $topicId . ':' . $hash;
$dupInserted = false;
try {
    $db->prepare("DELETE FROM post_dedup WHERE dedup_key = ? AND created_at < (NOW() - INTERVAL 1 HOUR)")
       ->execute([$dupKey]);
    $db->prepare("INSERT INTO post_dedup (dedup_key) VALUES (?)")->execute([$dupKey]);
    $dupInserted = true;
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        rateLimitRefund('post_reply'); // дубль — не новая попытка
        setFlashMessage('info', 'Такое сообщение уже отправлено');
        redirect($backUrl);
    }
    throw $e;
}

$savedFiles = [];
try {
    // Повторная проверка под FOR UPDATE: модерация не закроет тему между SELECT и INSERT.
    $db->beginTransaction();
    $stmt = $db->prepare("
        SELECT t.id, t.is_closed, t.is_hidden
        FROM topics t
        JOIN forums f ON f.id = t.forum_id
        JOIN categories c ON c.id = f.category_id
        WHERE t.id = ? AND f.is_active = 1 AND c.is_active = 1
        FOR UPDATE
    ");
    $stmt->execute([$topicId]);
    $topic = $stmt->fetch();

    if (!$topic || $topic['is_hidden'] || $topic['is_closed']) {
        $db->rollBack();
        $db->prepare("DELETE FROM post_dedup WHERE dedup_key = ?")->execute([$dupKey]);
        rateLimitRefund('post_reply');
        setFlashMessage('error', $topic && $topic['is_closed'] ? 'Тема закрыта для новых ответов' : 'Тема недоступна для обсуждения');
        redirect($topic ? $backUrl : '/index.php');
    }

    $stmt = $db->prepare("INSERT INTO posts (topic_id, user_id, content) VALUES (?, ?, ?)");
    $stmt->execute([$topicId, $currentUser['id'], $content]);
    $postId = (int)$db->lastInsertId();

    // Файлы + строки attachments — в той же транзакции.
    $savedFiles = attachStore($attachFiles, 'post', $postId, (int)$currentUser['id']);

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    foreach ($savedFiles as $sn) attachUnlink($sn);
    if ($dupInserted) {
        try { $db->prepare("DELETE FROM post_dedup WHERE dedup_key = ?")->execute([$dupKey]); } catch (Throwable $ignore) {}
    }
    rateLimitRefund('post_reply');
    error_log('Post create error: ' . $e->getMessage());
    setFlashMessage('error', 'Ошибка при отправке сообщения. Попробуйте позже.');
    redirect($backUrl);
}

// Пост опубликован. Побочные эффекты — вне транзакции, ошибки не отменяют пост.
subscribeTopic($currentUser['id'], $topicId);
notifyTopicReply($topicId, $postId, $currentUser['id'], $content); // письма — в очередь, мгновенно

$perPage = max(1, setting('per_page_posts', 15));
$staffFlag = isModerator() ? 1 : 0;
$st = $db->prepare("
    SELECT COUNT(*) FROM posts p
    JOIN posts np ON np.id = ?
    WHERE p.topic_id = ? AND (p.is_hidden = 0 OR ? = 1)
      AND (p.created_at < np.created_at
           OR (p.created_at = np.created_at AND p.id <= np.id))
");
$st->execute([$postId, $topicId, $staffFlag]);
$pos = max(1, (int)$st->fetchColumn());
$page = (int)ceil($pos / $perPage);

setFlashMessage('success', 'Ответ опубликован');
redirect('/topic.php?id=' . $topicId . '&page=' . $page . '#post-' . $postId);