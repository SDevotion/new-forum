<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/permissions.php';

/**
 * SQL-условие: уведомление ведёт на доступный текущему пользователю контент.
 * Модерационные уведомления видны всегда; контентные — только если тема/пост
 * не скрыты (стафф видит всё) и раздел активен. Алиас таблицы: n.
 */
function notifVisibilityCond() {
    $staff = isModerator() ? 1 : 0;
    return " AND (n.type = 'moderation' OR (
        EXISTS (SELECT 1 FROM topics t
                JOIN forums f ON f.id = t.forum_id
                JOIN categories c ON c.id = f.category_id
                WHERE t.id = n.topic_id AND f.is_active = 1 AND c.is_active = 1
                  AND (t.is_hidden = 0 OR $staff = 1))
        AND (n.post_id IS NULL OR EXISTS (SELECT 1 FROM posts p
                WHERE p.id = n.post_id AND (p.is_hidden = 0 OR $staff = 1)))
    ))";
}

/**
 * Создание одного уведомления с учётом настроек получателя
 */
function notifyUser($userId, $type, $actorId, $topicId, $postId, $excerpt = null) {
    $userId = (int)$userId;
    $actorId = (int)$actorId;
    if ($userId <= 0 || $userId === $actorId) return;

    try {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT notify_replies, notify_mentions FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$userId]);
        $prefs = $stmt->fetch();
        if (!$prefs) return;

        if ($type === 'reply' && !$prefs['notify_replies']) return;
        if (($type === 'mention' || $type === 'quote') && !$prefs['notify_mentions']) return;
        // moderation-уведомления приходят всегда

        $stmt = $db->prepare("
            INSERT IGNORE INTO notifications (user_id, type, actor_id, topic_id, post_id, excerpt)
            VALUES (?,?,?,?,?,?)
        ");
        $stmt->execute([
            $userId, $type, $actorId, $topicId, $postId,
            $excerpt !== null ? mb_substr($excerpt, 0, 255) : null
        ]);
    } catch (PDOException $e) {
        error_log('Notify error: ' . $e->getMessage());
    }
}

/**
 * Извлечение имён из @упоминаний и [quote=...] с указанием источника.
 * Возвращает [name => 'mention'|'quote']; mention приоритетнее quote.
 */
function parseMentions($text) {
    $names = [];
    if (preg_match_all('/\[quote=([^\]\n]{1,60})\]/u', (string)$text, $m)) {
        foreach ($m[1] as $name) $names[$name] = 'quote';
    }
    if (preg_match_all('/@([a-zA-Z0-9_]{3,50})/u', (string)$text, $m)) {
        foreach ($m[1] as $name) $names[$name] = 'mention';
    }
    return $names;
}

/**
 * Короткое превью без разметки
 */
function mdExcerptSafe($text, $len = 200) {
    if (function_exists('mdExcerpt')) return mdExcerpt($text, $len);
    $t = preg_replace('/\s+/u', ' ', strip_tags((string)$text));
    return mb_strimwidth(trim($t), 0, $len, '…');
}

/**
 * Рассылка уведомлений о новом ответе.
 * Приоритет: упоминания → автор темы → подписчики. Каждый получает одно событие.
 */
function notifyTopicReply($topicId, $postId, $actorId, $content) {
    $db = Database::getInstance()->getConnection();
    $topicId = (int)$topicId;
    $postId = (int)$postId;
    $actorId = (int)$actorId;

    $stmt = $db->prepare("SELECT user_id, title FROM topics WHERE id = ?");
    $stmt->execute([$topicId]);
    $topic = $stmt->fetch();
    if (!$topic) return;

    $excerpt = mdExcerptSafe($content);
    $notified = [$actorId];

    foreach (parseMentions($content) as $name => $kind) {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND is_active = 1");
        $stmt->execute([$name]);
        $uid = (int)$stmt->fetchColumn();
        if (!$uid || in_array($uid, $notified, true)) continue;
        notifyUser($uid, $kind, $actorId, $topicId, $postId, $excerpt);
        $notified[] = $uid;
    }

    $ownerId = (int)$topic['user_id'];
    if (!in_array($ownerId, $notified, true)) {
        notifyUser($ownerId, 'reply', $actorId, $topicId, $postId, $excerpt);
        $notified[] = $ownerId;
    }

    $stmt = $db->prepare("SELECT user_id FROM topic_subscriptions WHERE topic_id = ?");
    $stmt->execute([$topicId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $uid = (int)$uid;
        if (in_array($uid, $notified, true)) continue;
        notifyUser($uid, 'reply', $actorId, $topicId, $postId, $excerpt);
        $notified[] = $uid;
    }
}

/**
 * Счётчик непрочитанных (кэш в рамках запроса)
 */
function unreadCount() {
    static $c = null;
    if ($c !== null) return $c;
    if (!isLoggedIn()) return $c = 0;

    $db = Database::getInstance()->getConnection();
    $vis = notifVisibilityCond();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications n WHERE n.user_id = ? AND n.is_read = 0 $vis");
    $stmt->execute([$_SESSION['user_id']]);
    return $c = (int)$stmt->fetchColumn();
}

function isSubscribed($topicId) {
    if (!isLoggedIn()) return false;
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT 1 FROM topic_subscriptions WHERE user_id = ? AND topic_id = ?");
    $stmt->execute([$_SESSION['user_id'], $topicId]);
    return (bool)$stmt->fetchColumn();
}

function subscribeTopic($userId, $topicId) {
    try {
        $db = Database::getInstance()->getConnection();
        $db->prepare("INSERT IGNORE INTO topic_subscriptions (user_id, topic_id) VALUES (?,?)")
           ->execute([(int)$userId, (int)$topicId]);
    } catch (PDOException $e) {
        error_log('Subscribe error: ' . $e->getMessage());
    }
}