<?php
// includes/functions.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/session.php';

sessionBoot();

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function currentUserId() {
    return isLoggedIn() ? (int)$_SESSION['user_id'] : 0;
}

/**
 * Текущий пользователь или null. Без сайд-эффектов (редиректов/exit):
 * забаненный/удалённый аккаунт просто даёт null, сессия очищается.
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }

    static $user = null;
    static $checked = false;
    if ($checked) return $user;
    $checked = true;

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT id, username, email, avatar, role, signature, created_at FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;

    if (!$user) {
        // Аккаунт удалён или заблокирован: гасим сессию сразу.
        $_SESSION = [];
        if (isset($_COOKIE[session_name()])) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 3600,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }
        session_destroy();
        sessionBoot();
        session_regenerate_id(true);
        setFlashMessage('info', 'Сессия завершена: аккаунт недоступен');
    }

    return $user;
}

/**
 * Жёсткая проверка активного аккаунта для POST/API-эндпоинтов.
 * Вызывать сразу после проверки isLoggedIn(): забаненный пользователь
 * с живой сессией не должен выполнять действия до следующего рендера страницы.
 */
function enforceActiveUser() {
    if (!getCurrentUser()) {
        redirect('/auth/login.php');
    }
}

function escape($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Проверка CSRF токена. Только строки: массив/объект в POST — не TypeError, а false.
 */
function verifyCSRFToken($token) {
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Редирект: явный 302, чтобы Location работал даже после http_response_code(4xx).
 */
function redirect($url) {
    header('Location: ' . $url, true, 302);
    exit;
}

function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

function sessionMarkOnce($key, $id, $cap = 500) {
    $id = (int)$id;
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }
    if (isset($_SESSION[$key][$id])) {
        return false;
    }
    $_SESSION[$key][$id] = time();
    if (count($_SESSION[$key]) > $cap) {
        $_SESSION[$key] = array_slice($_SESSION[$key], -$cap, null, true);
    }
    return true;
}

function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function isValidUsername($username) {
    return preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username);
}
