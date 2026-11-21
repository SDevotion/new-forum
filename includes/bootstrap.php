<?php
// includes/bootstrap.php — единая точка инициализации приложения.
// Подключает конфиг, сессию, настройки, права, видимость.
// Страницы могут подключать только его вместо цепочки require_once.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/visibility.php';

// functions.php уже вызывает sessionBoot(); повторный вызов безопасен (no-op).
