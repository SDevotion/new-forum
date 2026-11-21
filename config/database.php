<?php
// config/database.php

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3308');
define('DB_NAME', 'example_db');
define('DB_USER', 'example_user');
define('DB_PASS', 'example_password');
define('DB_CHARSET', 'utf8mb4');

// Режим отладки: на проде ВСЕГДА false. Ошибки уходят только в error_log.
define('APP_DEBUG', false);

// Доверенные прокси: список IP/подсетей (префикс), с которых принимаем X-Forwarded-For.
// Пустой массив = не доверяем никому (берём REMOTE_ADDR). Пример: ['127.0.0.1', '10.0.']
define('TRUSTED_PROXIES', []);

// true — если сайт работает за TLS-терминирующим прокси и $_SERVER['HTTPS'] не пробрасывается.
define('FORCE_SECURE_COOKIES', false);

// Единая таймзона приложения и соединения MySQL (иначе даты «уезжают»).
define('APP_TIMEZONE', 'Europe/Moscow');

date_default_timezone_set(APP_TIMEZONE);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL); // логируем всё, но не показываем
}

// Глобальные заголовки безопасности (CSP страниц задаётся отдельно в header.php)
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (FORCE_SECURE_COOKIES || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Необработанные исключения: лог + нейтральная страница, без стектрейсов наружу.
set_exception_handler(function ($e) {
    error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    if (APP_DEBUG) {
        echo '<pre style="padding:24px;background:#fee2e2;color:#991b1b">' . htmlspecialchars((string)$e) . '</pre>';
    } else {
        echo '<!DOCTYPE html><html lang="ru"><meta charset="utf-8"><title>Ошибка</title>'
           . '<body style="font-family:sans-serif;padding:48px;text-align:center">'
           . '<h1>Внутренняя ошибка</h1><p>Мы уже работаем над этим. Попробуйте позже.</p>'
           . '<p><a href="/index.php">На главную</a></p></body></html>';
    }
    exit;
});

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        $dsn = "mysql:host=" . DB_HOST
             . ";port=" . DB_PORT
             . ";dbname=" . DB_NAME
             . ";charset=" . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ];

        try {
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Синхронизируем таймзону соединения с PHP: NOW()/CURDATE() и date() совпадают.
            $tz = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P');
            $this->connection->exec("SET time_zone = '" . $tz . "'");
        } catch (PDOException $e) {
            // Никаких DSN/кредов/текстов ошибок наружу — только в лог.
            error_log("Database connection error: " . $e->getMessage());
            if (!headers_sent()) http_response_code(500);
            die('<!DOCTYPE html><html lang="ru"><meta charset="utf-8"><title>Недоступно</title>'
              . '<body style="font-family:sans-serif;padding:48px;text-align:center">'
              . '<h1>Сервис временно недоступен</h1><p>Попробуйте обновить страницу через минуту.</p></body></html>');
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }
}
