<?php
require_once __DIR__ . '/permissions.php';

/**
 * Может ли текущий пользователь редактировать объект.
 * Модератор — всегда, автор — в течение 24 часов.
 */
function canEdit($ownerId, $createdAt) {
    $u = getCurrentUser();
    if (!$u) return false;
    if (isModerator()) return true;
    if ((int)$u['id'] !== (int)$ownerId) return false;

    $limit = max(0, setting('edit_window_hours', 24)) * 3600;
    return (time() - strtotime($createdAt)) <= $limit;
}

/**
 * Сохранение версии перед правкой
 */
function saveRevision($type, $id, $oldTitle, $oldContent, $reason = null) {
    $u = getCurrentUser();
    if (!$u) return;
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        INSERT INTO post_edits (target_type, target_id, editor_id, old_title, old_content, reason)
        VALUES (?,?,?,?,?,?)
    ");
    $stmt->execute([$type, $id, $u['id'], $oldTitle, $oldContent, $reason ? mb_substr($reason, 0, 255) : null]);
}

/**
 * Список правок объекта
 */
function getRevisions($type, $id) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT e.*, u.username, u.avatar
        FROM post_edits e
        JOIN users u ON u.id = e.editor_id
        WHERE e.target_type = ? AND e.target_id = ?
        ORDER BY e.created_at DESC, e.id DESC
    ");
    $stmt->execute([$type, $id]);
    return $stmt->fetchAll();
}