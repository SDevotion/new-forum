<?php
require_once __DIR__ . '/../includes/functions.php';

// Уничтожение сессии
session_destroy();

// Очистка cookie сессии
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

setFlashMessage('info', 'Вы успешно вышли из системы');
redirect('/index.php');