<?php
require_once __DIR__ . '/permissions.php';

/**
 * Может ли текущий пользователь редактировать объект.
 * Модератор — всегда. Автор — в течение окна edit_window_hours,
 * но НЕ скрытый модератором объект и НЕ в закрытой теме.
 * $flags: ['is_hidden' => bool, 'topic_closed' => bool] (опционально).
 */
function canEdit($ownerId, $createdAt, array $flags = []) {
    $u = getCurrentUser();
    if (!$u) return false;
    if (isModerator()) return true;
    if ((int)$u['id'] !== (int)$ownerId) return false;
    if (!empty($flags['is_hidden'])) return false;
    if (!empty($flags['topic_closed'])) return false;

    $limit = max(0, setting('edit_window_hours', 24)) * 3600;
    return (time() - strtotime($createdAt)) <= $limit;
}

/**
 * Сохранение версии перед правкой.
 * Храним последние 100 версий объекта — защита от разрастания истории.
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

    // Подчищаем самые старые версии сверх лимита
    $db->prepare("
        DELETE FROM post_edits
        WHERE target_type = ? AND target_id = ?
          AND id NOT IN (
              SELECT id FROM (
                  SELECT id FROM post_edits
                  WHERE target_type = ? AND target_id = ?
                  ORDER BY created_at DESC, id DESC
                  LIMIT 100
              ) keep
          )
    ")->execute([$type, $id, $type, $id]);
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
