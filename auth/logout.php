<?php
// auth/logout.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [6.logout/4.5] Гостевая сессия ради flash больше НЕ создаётся: сообщение
//       передаётся подписанным query-параметром (flashQuery из functions.php),
//       строка user_sessions user_id=0 на каждый logout не появляется.
// [1.2] Убран sessionBoot()+regenerate после destroy — пересечение локов
//       «destroy старой / write новой» невозможно by design.
// Выход только по POST с валидным CSRF — сохранён.

require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCSRFToken(postStr('csrf_token'))) {
    http_response_code(405);
    header('Allow: POST');
    redirect('/index.php');
}

$_SESSION = [];
session_destroy();

if (isset($_COOKIE[session_name()])) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => isset($p['samesite']) && $p['samesite'] ? $p['samesite'] : 'Lax',
    ]);
}

// Flash через подписанный query — без новой сессии и без строки в БД.
redirect('/index.php?' . flashQuery('info', 'Вы успешно вышли из системы'));