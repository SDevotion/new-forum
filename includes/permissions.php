<?php
require_once __DIR__ . '/functions.php';

function isModerator() {
    $u = getCurrentUser();
    return $u && in_array($u['role'], ['moderator', 'admin'], true);
}

function isAdmin() {
    $u = getCurrentUser();
    return $u && $u['role'] === 'admin';
}

function requireModerator() {
    if (!isLoggedIn()) { setFlashMessage('error', 'Необходимо войти'); redirect('/auth/login.php'); }
    if (!isModerator()) { http_response_code(403); setFlashMessage('error', 'Недостаточно прав'); redirect('/index.php'); }
}

function requireAdmin() {
    if (!isLoggedIn()) { setFlashMessage('error', 'Необходимо войти'); redirect('/auth/login.php'); }
    if (!isAdmin()) { http_response_code(403); setFlashMessage('error', 'Только для администраторов'); redirect('/index.php'); }
}

function logModeration($action, $targetType, $targetId, $reason = null) {
    $u = getCurrentUser();
    if (!$u) return;
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("INSERT INTO moderation_log (user_id, action, target_type, target_id, reason) VALUES (?,?,?,?,?)");
    $stmt->execute([$u['id'], $action, $targetType, $targetId, $reason ? mb_substr($reason, 0, 255) : null]);
}

function newReportsCount() {
    static $c = null;
    if ($c === null) {
        $db = Database::getInstance()->getConnection();
        $c = (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 'new'")->fetchColumn();
    }
    return $c;
}