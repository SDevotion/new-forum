<?php
// includes/editing.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [3.5/приоритет#10] getRevisions() получила обязательный фильтр видимости:
//       параметр $includeHidden=false по умолчанию; скрытые ревизии отдаются
//       только при явном true (вызывающий обязан проверить isModerator()).
//       Мёртвый небезопасный API устранён — первый же вызов не сольёт скрытые версии.
// canEdit/saveRevision — без изменений (были корректны).

require_once __DIR__ . '/permissions.php';

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

function saveRevision($type, $id, $oldTitle, $oldContent, $reason = null, $hidden = false) {
    $u = getCurrentUser();
    if (!$u) return;
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        INSERT INTO post_edits (target_type, target_id, editor_id, old_title, old_content, reason, is_hidden)
        VALUES (?,?,?,?,?,?,?)
    ");
    $stmt->execute([$type, $id, $u['id'], $oldTitle, $oldContent, $reason ? mb_substr($reason, 0, 255) : null, $hidden ? 1 : 0]);

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
 * Список правок объекта.
 * $includeHidden=true допустимо ТОЛЬКО после проверки isModerator() вызывающим.
 * По умолчанию скрытые версии исключаются — безопасный дефолт.
 */
function getRevisions($type, $id, $includeHidden = false) {
    if ($includeHidden && !isModerator()) $includeHidden = false; // двойная страховка
    $db = Database::getInstance()->getConnection();
    $vis = $includeHidden ? '' : ' AND e.is_hidden = 0';
    $stmt = $db->prepare("
        SELECT e.*, u.username, u.avatar
        FROM post_edits e
        JOIN users u ON u.id = e.editor_id
        WHERE e.target_type = ? AND e.target_id = ?$vis
        ORDER BY e.created_at DESC, e.id DESC
    ");
    $stmt->execute([$type, $id]);
    return $stmt->fetchAll();
}