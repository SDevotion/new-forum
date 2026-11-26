<?php
// includes/counters.php — явный пересчёт денормализованных счётчиков.
// MySQL не активирует триггеры на FK-каскадах, поэтому DELETE-пути
// пересчитывают затронутые сущности сами. INSERT обслуживают триггеры.

require_once __DIR__ . '/functions.php';

/**
 * Пересчёт счётчиков одного раздела (темы, сообщения, last_post_id).
 */
function recountForum($forumId) {
    $forumId = (int)$forumId;
    if ($forumId <= 0) return;
    $db = Database::getInstance()->getConnection();

    $db->prepare("
        UPDATE forums f
        SET topics_count = (SELECT COUNT(*) FROM topics t WHERE t.forum_id = f.id),
            posts_count  = (SELECT COUNT(*) FROM topics t WHERE t.forum_id = f.id)
                         + (SELECT COUNT(*) FROM posts p JOIN topics t ON t.id = p.topic_id WHERE t.forum_id = f.id)
        WHERE f.id = ?
    ")->execute([$forumId]);

    // «Последнее сообщение» — только видимое (скрытый пост не должен быть витриной раздела)
    $st = $db->prepare("
        SELECT p.id FROM posts p JOIN topics t ON t.id = p.topic_id
        WHERE t.forum_id = ? AND p.is_hidden = 0 AND t.is_hidden = 0
        ORDER BY p.created_at DESC, p.id DESC LIMIT 1
    ");
    $st->execute([$forumId]);
    $lastId = $st->fetchColumn() ?: null;
    $db->prepare("UPDATE forums SET last_post_id = ? WHERE id = ?")->execute([$lastId, $forumId]);
}

/**
 * Пересчёт счётчиков одной темы (ответы, last_post_at).
 */
function recountTopic($topicId) {
    $topicId = (int)$topicId;
    if ($topicId <= 0) return;
    $db = Database::getInstance()->getConnection();
    $db->prepare("
        UPDATE topics t
        SET replies_count = (SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id),
            last_post_at  = COALESCE(
                (SELECT MAX(p.created_at) FROM posts p WHERE p.topic_id = t.id AND p.is_hidden = 0),
                t.created_at)
        WHERE t.id = ?
    ")->execute([$topicId]);
}

/**
 * Пересчёт счётчиков участников (темы, сообщения).
 * Принимает массив id; пустой массив — ничего не делает.
 */
function recountUsers(array $userIds) {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $userIds = array_filter($userIds, fn($id) => $id > 0);
    if (!$userIds) return;
    $db = Database::getInstance()->getConnection();
    $in = implode(',', array_fill(0, count($userIds), '?'));
    $db->prepare("
        UPDATE users u
        SET topics_total = (SELECT COUNT(*) FROM topics t WHERE t.user_id = u.id),
            posts_total  = (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id)
        WHERE u.id IN ($in)
    ")->execute($userIds);
}

/**
 * Полный пересчёт всех счётчиков (обслуживание/страховка).
 */
function recountAll() {
    $db = Database::getInstance()->getConnection();
    $db->exec("
        UPDATE forums f
        SET topics_count = (SELECT COUNT(*) FROM topics t WHERE t.forum_id = f.id),
            posts_count  = (SELECT COUNT(*) FROM topics t WHERE t.forum_id = f.id)
                         + (SELECT COUNT(*) FROM posts p JOIN topics t ON t.id = p.topic_id WHERE t.forum_id = f.id)
    ");
    foreach ($db->query("SELECT id FROM forums")->fetchAll(PDO::FETCH_COLUMN) as $fid) {
        recountForum($fid);
    }
    $db->exec("
        UPDATE topics t
        SET replies_count = (SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id),
            last_post_at  = COALESCE(
                (SELECT MAX(p.created_at) FROM posts p WHERE p.topic_id = t.id AND p.is_hidden = 0),
                t.created_at)
    ");
    $db->exec("
        UPDATE users u
        SET topics_total = (SELECT COUNT(*) FROM topics t WHERE t.user_id = u.id),
            posts_total  = (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id)
    ");
}
