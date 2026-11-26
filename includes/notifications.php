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
 * Отправка email-уведомления одному получателю (если включено глобально и у пользователя).
 * Вызывается ТОЛЬКО после реально вставленной строки уведомления — без дублей.
 */
function notifyEmailSend($userId, $type, $actorId, $topicId, $postId, $excerptVal) {
    if (!setting('email_notifications', false)) return;

    try {
        require_once __DIR__ . '/mailer.php';
        $db = Database::getInstance()->getConnection();

        $st = $db->prepare("SELECT email, username, notify_email FROM users WHERE id = ? AND is_active = 1");
        $st->execute([(int)$userId]);
        $u = $st->fetch();
        if (!$u || !$u['notify_email']) return;

        $actorName = 'Система';
        if ((int)$actorId > 0) {
            $actorSt = $db->prepare("SELECT username FROM users WHERE id = ?");
            $actorSt->execute([(int)$actorId]);
            $actorName = $actorSt->fetchColumn() ?: 'Участник';
        }

        $kindLabel = [
            'reply'      => 'Новый ответ',
            'mention'    => 'Вас упомянули',
            'quote'      => 'Вас процитировали',
            'moderation' => 'Сообщение от модерации',
        ][$type] ?? 'Уведомление';

        $body = "Здравствуйте, {$u['username']}!\n\n";
        if ($topicId) {
            $topicSt = $db->prepare("SELECT title FROM topics WHERE id = ?");
            $topicSt->execute([(int)$topicId]);
            $topicTitle = $topicSt->fetchColumn() ?: '';
            $body .= "$actorName: $kindLabel в теме «$topicTitle».\n\n";
        } else {
            $body .= "$kindLabel.\n\n";
        }
        if ($excerptVal) $body .= "$excerptVal\n\n";
        if ($topicId) $body .= "Читать: " . topicUrl($topicId, $postId) . "\n\n";
        $body .= "Отключить email-уведомления можно в настройках профиля.\n" . MAIL_FROM_NAME;

        // Внимание: отправленный email неотзываем — при последующем скрытии
        // контента письмо останется у получателя. Очередь с отложенной отправкой — TODO.
        if (!sendMail($u['email'], $kindLabel . ' — ' . MAIL_FROM_NAME, $body)) {
            error_log('Notify email delivery failed for user ' . (int)$userId);
        }
    } catch (Throwable $e) {
        error_log('Notify email error: ' . $e->getMessage());
    }
}

/**
 * Одно уведомление с учётом настроек получателя.
 * Email — только если строка реально вставлена (не дубль).
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

        $excerptVal = $excerpt !== null ? mb_substr($excerpt, 0, 255) : null;
        $stmt = $db->prepare("
            INSERT IGNORE INTO notifications (user_id, type, actor_id, topic_id, post_id, excerpt)
            VALUES (?,?,?,?,?,?)
        ");
        $stmt->execute([$userId, $type, $actorId, $topicId, $postId, $excerptVal]);

        if ($stmt->rowCount() > 0) {
            notifyEmailSend($userId, $type, $actorId, $topicId, $postId, $excerptVal);
        }
    } catch (PDOException $e) {
        error_log('Notify error: ' . $e->getMessage());
    }
}

/**
 * Пакетная рассылка одного типа уведомления списку получателей.
 * Вставка построчная (INSERT IGNORE + rowCount), чтобы точно знать,
 * кому уведомление реально добавлено, и слать email только им — без дублей.
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
        $excerptVal = $excerpt !== null ? mb_substr($excerpt, 0, 255) : null;

        // Один подготовленный запрос на всех: вставляем по одному id,
        // фильтр prefs/is_active применяется в самом SELECT.
        $ins = $db->prepare("
            INSERT IGNORE INTO notifications (user_id, type, actor_id, topic_id, post_id, excerpt)
            SELECT u.id, ?, ?, ?, ?, ?
            FROM users u
            WHERE u.id = ? AND u.is_active = 1 $prefCond
        ");

        $insertedIds = [];
        foreach ($userIds as $uid) {
            $ins->execute([$type, (int)$actorId, (int)$topicId, $postId, $excerptVal, $uid]);
            if ($ins->rowCount() > 0) {
                $insertedIds[] = $uid;
            }
        }

        // Email — только тем, кому строка реально добавилась.
        foreach ($insertedIds as $uid) {
            notifyEmailSend($uid, $type, $actorId, $topicId, $postId, $excerptVal);
        }
    } catch (PDOException $e) {
        error_log('Notify batch error: ' . $e->getMessage());
    }
}

/**
 * Извлечение имён из @упоминаний и [quote=...].
 */
function parseMentions($text) {
    // Код-блоки и inline-код не содержат упоминаний (как и в рендере mdRender)
    $text = function_exists('mdStripCode') ? mdStripCode($text) : (string)$text;
    $names = [];
    if (preg_match_all('/\[quote=([^\]\n]{1,60})\]/u', $text, $m)) {
        foreach ($m[1] as $name) $names[$name] = 'quote';
    }
    if (preg_match_all('/@([a-zA-Z0-9_]{3,50})/u', $text, $m)) {
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
 * Рассылка уведомлений о новом ответе.
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

    // 3) Подписчики (исключая уже уведомлённых)
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
