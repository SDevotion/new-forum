<?php
// includes/visibility.php — единые правила видимости контента.
// Все проверки «тема/пост виден пользователю» идут через этот файл.
// Раньше условие было скопировано в ~13 файлах и расходилось между ними.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/permissions.php';

/**
 * SQL-фрагмент видимости темы (алиасы: t=topics, f=forums, c=categories).
 * Возвращает [sql, params]. Плейсхолдер один — staffFlag.
 */
function visTopicSql() {
    return ["f.is_active = 1 AND c.is_active = 1 AND (t.is_hidden = 0 OR ? = 1)", [isModerator() ? 1 : 0]];
}

/**
 * SQL-фрагмент видимости поста (алиасы: p=posts, t=topics, f=forums, c=categories).
 * Возвращает [sql, params]. Плейсхолдеров два — staffFlag, staffFlag.
 */
function visPostSql() {
    $s = isModerator() ? 1 : 0;
    return [
        "f.is_active = 1 AND c.is_active = 1 AND (p.is_hidden = 0 OR ? = 1) AND (t.is_hidden = 0 OR ? = 1)",
        [$s, $s]
    ];
}

/**
 * JOIN-цепочка для поста: posts p -> topics t -> forums f -> categories c.
 */
function visPostJoins() {
    return "JOIN topics t ON t.id = p.topic_id
            JOIN forums f ON f.id = t.forum_id
            JOIN categories c ON c.id = f.category_id";
}

/**
 * JOIN-цепочка для темы: topics t -> forums f -> categories c.
 */
function visTopicJoins() {
    return "JOIN forums f ON f.id = t.forum_id
            JOIN categories c ON c.id = f.category_id";
}

/**
 * Видна ли тема текущему пользователю (точечная проверка по id).
 */
function topicVisible($topicId) {
    $db = Database::getInstance()->getConnection();
    [$cond, $params] = visTopicSql();
    $st = $db->prepare("SELECT t.id FROM topics t " . visTopicJoins() . " WHERE t.id = ? AND $cond");
    $st->execute(array_merge([(int)$topicId], $params));
    return (bool)$st->fetchColumn();
}

/**
 * Виден ли пост текущему пользователю (точечная проверка по id).
 */
function postVisible($postId) {
    $db = Database::getInstance()->getConnection();
    [$cond, $params] = visPostSql();
    $st = $db->prepare("SELECT p.id FROM posts p " . visPostJoins() . " WHERE p.id = ? AND $cond");
    $st->execute(array_merge([(int)$postId], $params));
    return (bool)$st->fetchColumn();
}

/**
 * Загрузка темы со всеми полями автора/редактора/раздела + проверка видимости.
 * Возвращает строку или null. Используется страницами topic/edit/history.
 */
function loadVisibleTopic($topicId, $withAuthor = true) {
    $db = Database::getInstance()->getConnection();
    [$cond, $params] = visTopicSql();
    $authorCols = $withAuthor
        ? "u.username, u.avatar, u.role, u.signature, u.id AS author_id, eu.username AS editor_name,"
        : "";
    $authorJoins = $withAuthor
        ? "JOIN users u ON u.id = t.user_id LEFT JOIN users eu ON eu.id = t.edited_by"
        : "";
    $st = $db->prepare("
        SELECT t.*, $authorCols
               f.title AS forum_title, f.id AS forum_id, c.title AS category_title
        FROM topics t
        " . visTopicJoins() . "
        $authorJoins
        WHERE t.id = ? AND $cond
    ");
    $st->execute(array_merge([(int)$topicId], $params));
    return $st->fetch() ?: null;
}
