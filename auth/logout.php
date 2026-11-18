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

// Уничтожение сессии
$_SESSION = [];
session_destroy();

// Очистка cookie сессии
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Новая короткая сессия только для flash-сообщения
session_start();
session_regenerate_id(true);
$_SESSION['flash_message'] = $flash;

redirect('/index.php');