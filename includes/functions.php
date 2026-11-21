<?php
// includes/functions.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/session.php';

// Запуск сессии: хранение в БД (user_sessions), защищённые cookie
sessionBoot();

/**
 * Проверка авторизации пользователя
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * ID текущего пользователя (0 для гостя) — единая точка чтения из сессии
 */
function currentUserId() {
    return isLoggedIn() ? (int)$_SESSION['user_id'] : 0;
}

/**
 * Получение текущего пользователя
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    static $user = null;
    if ($user === null) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id, username, email, avatar, role, signature, created_at FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        // Если пользователь не найден или заблокирован - разлогиниваем
        if (!$user) {
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
            // Новая короткая гостевая сессия только для flash-сообщения
            sessionBoot();
            session_regenerate_id(true);
            setFlashMessage('info', 'Сессия завершена: аккаунт недоступен');
            header('Location: /index.php');
            exit;
        }
    }
    
    return $user;
}

/**
 * Защита от XSS
 */
function escape($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Генерация CSRF токена
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Проверка CSRF токена
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Редирект
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Установка flash-сообщения
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Получение flash-сообщения
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

/**
 * Отметка «уже сделано в этой сессии» с ограничением размера списка.
 * Хранит последние $cap отметок (FIFO), чтобы payload сессии не рос бесконечно.
 */
function sessionMarkOnce($key, $id, $cap = 500) {
    $id = (int)$id;
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }
    if (isset($_SESSION[$key][$id])) {
        return false; // уже отмечено
    }
    $_SESSION[$key][$id] = time();
    if (count($_SESSION[$key]) > $cap) {
        $_SESSION[$key] = array_slice($_SESSION[$key], -$cap, null, true);
    }
    return true; // отмечено впервые
}

/**
 * Валидация email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Валидация имени пользователя
 */
function isValidUsername($username) {
    return preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username);
}