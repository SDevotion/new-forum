<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/permissions.php';

/**
 * SQL-условие видимости уведомления (алиас n).
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
 * Одно уведомление с учётом настроек получателя.
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
 * Пакетная рассылка одного типа уведомления списку получателей.
 * Один INSERT ... SELECT с фильтром по prefs и is_active. Без N+1.
 */
function notifyUsersBatch(array $userIds, $type, $actorId, $topicId, $postId, $excerpt = null) {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $userIds = array_filter($userIds, fn($id) => $id > 0 && $id !== (int)$actorId);
    if (!$userIds) return;

    $prefCol = ($type === 'reply') ? 'notify_replies' : 'notify_mentions';
    // moderation приходит всегда; для reply/mention/quote проверяем prefs.
    $prefCond = in_array($type, ['reply', 'mention', 'quote'], true) ? " AND u.$prefCol = 1" : '';

    try {
        $db = Database::getInstance()->getConnection();
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $excerptVal = $excerpt !== null ? mb_substr($excerpt, 0, 255) : null;

        $sql = "
            INSERT IGNORE INTO notifications (user_id, type, actor_id, topic_id, post_id, excerpt)
            SELECT u.id, ?, ?, ?, ?, ?
            FROM users u
            WHERE u.id IN ($in) AND u.is_active = 1 $prefCond
        ";
        $params = array_merge([$type, (int)$actorId, (int)$topicId, $postId, $excerptVal], $userIds);
        $db->prepare($sql)->execute($params);
    } catch (PDOException $e) {
        error_log('Notify batch error: ' . $e->getMessage());
    }
}

/**
 * Извлечение имён из @упоминаний и [quote=...].
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

function mdExcerptSafe($text, $len = 200) {
    if (function_exists('mdExcerpt')) return mdExcerpt($text, $len);
    $t = preg_replace('/\s+/u', ' ', strip_tags((string)$text));
    return mb_strimwidth(trim($t), 0, $len, '…');
}

/**
 * Рассылка уведомлений о новом ответе. Батчами, без N+1.
 * Приоритет: упоминания → автор темы → подписчики.
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

    // 1) Упоминания/цитаты: резолвим имена одним запросом
    $mentions = parseMentions($content);
    if ($mentions) {
        $names = array_keys($mentions);
        $in = implode(',', array_fill(0, count($names), '?'));
        $st = $db->prepare("SELECT id, username FROM users WHERE username IN ($in) AND is_active = 1");
        $st->execute($names);
        $byKind = ['mention' => [], 'quote' => []];
        foreach ($st->fetchAll() as $row) {
            $uid = (int)$row['id'];
            if (in_array($uid, $notified, true)) continue;
            $kind = $mentions[$row['username']] ?? 'mention';
            $byKind[$kind][] = $uid;
            $notified[] = $uid;
        }
        foreach ($byKind as $kind => $ids) {
            if ($ids) notifyUsersBatch($ids, $kind, $actorId, $topicId, $postId, $excerpt);
        }
    }

    // 2) Автор темы
    $ownerId = (int)$topic['user_id'];
    if (!in_array($ownerId, $notified, true)) {
        notifyUsersBatch([$ownerId], 'reply', $actorId, $topicId, $postId, $excerpt);
        $notified[] = $ownerId;
    }

    // 3) Подписчики (исключая уже уведомлённых) — одним запросом+батчем
    $st = $db->prepare("SELECT user_id FROM topic_subscriptions WHERE topic_id = ?");
    $st->execute([$topicId]);
    $subs = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $subs = array_values(array_diff($subs, $notified));
    if ($subs) notifyUsersBatch($subs, 'reply', $actorId, $topicId, $postId, $excerpt);
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
    $stmt->execute([currentUserId()]);
    return $c = (int)$stmt->fetchColumn();
}

function isSubscribed($topicId) {
    if (!isLoggedIn()) return false;
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT 1 FROM topic_subscriptions WHERE user_id = ? AND topic_id = ?");
    $stmt->execute([currentUserId(), $topicId]);
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
