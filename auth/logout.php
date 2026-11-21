<?php
require_once __DIR__ . '/../includes/functions.php';

// Выход только по POST с валидным CSRF-токеном (защита от logout-CSRF)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(405);
    header('Allow: POST');
    redirect('/index.php');
}

// Запоминаем flash до уничтожения сессии
$flash = ['type' => 'info', 'message' => 'Вы успешно вышли из системы'];

// Уничтожение сессии: строка в user_sessions удаляется обработчиком (destroy)
$_SESSION = [];
session_destroy();

// Очистка cookie сессии (с теми же параметрами, с которыми она ставилась)
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

// Новая короткая гостевая сессия только для flash-сообщения
// (в user_sessions появится строка user_id = 0, удалится сборщиком по expires_at)
sessionBoot();
session_regenerate_id(true);
$_SESSION['flash_message'] = $flash;

redirect('/index.php');