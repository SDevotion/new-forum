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

// ===== Почта (восстановление пароля, email-уведомления) =====
define('APP_URL', 'https://forum.example.com');
define('MAIL_FROM', 'noreply@forum.example.com');
define('MAIL_FROM_NAME', 'ForumHub');
define('MAIL_TRANSPORT', 'mail'); // 'mail' или 'smtp'
define('SMTP_HOST', 'localhost');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('SMTP_SECURE', 'tls'); // 'tls', 'ssl' или ''

// Валидный bcrypt-хэш для фиктивной проверки пароля при несуществующем логине
// (выравнивает время ответа против тайминг-энумерации). Хэш от строки 'password'.
define('DUMMY_PASSWORD_HASH', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

date_default_timezone_set(APP_TIMEZONE);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
}

// Предупреждение о незаполненном проде-конфиге (только в лог, не наружу).
if (!APP_DEBUG) {
    $placeholders = [];
    if (strpos(APP_URL, 'example.com') !== false) $placeholders[] = 'APP_URL';
    if (strpos(MAIL_FROM, 'example.com') !== false) $placeholders[] = 'MAIL_FROM';
    if (DB_NAME === 'example_db') $placeholders[] = 'DB_NAME';
    if ($placeholders) {
        error_log('CONFIG WARNING: placeholder values in production: ' . implode(', ', $placeholders));
    }
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

// Fatal errors (parse/TypeError/memory) не ловятся set_exception_handler —
// добиваем их shutdown-хендлером, чтобы прод не отдавал белый экран.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('Fatal: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) http_response_code(500);
        if (APP_DEBUG) {
            echo '<pre style="padding:24px;background:#fee2e2;color:#991b1b">' . htmlspecialchars($e['message'] . ' @ ' . $e['file'] . ':' . $e['line']) . '</pre>';
        } else {
            echo '<!DOCTYPE html><html lang="ru"><meta charset="utf-8"><title>Ошибка</title>'
               . '<body style="font-family:sans-serif;padding:48px;text-align:center">'
               . '<h1>Внутренняя ошибка</h1><p>Попробуйте позже.</p>'
               . '<p><a href="/index.php">На главную</a></p></body></html>';
        }
    }
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
            $tz = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P');
            $this->connection->exec("SET time_zone = '" . $tz . "'");
        } catch (PDOException $e) {
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
        // В CLI (maintenance/create_admin) соединение может умереть по wait_timeout —
        // пингуем и переподключаемся. В веб-запросах соединение всегда свежее.
        if (PHP_SAPI === 'cli') {
            try {
                $this->connection->query("SELECT 1");
            } catch (PDOException $e) {
                error_log('DB reconnect: ' . $e->getMessage());
                $fresh = new self();
                $this->connection = $fresh->connection;
            }
        }
        return $this->connection;
    }
}
