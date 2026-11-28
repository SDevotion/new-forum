<?php
// config/database.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [5.1] Секреты больше не живут в репозитории: config/config.local.php (gitignored)
//       и переменные окружения FORUM_* переопределяют любые значения. Боевой пароль
//       физически некуда закоммитить.
// [5.1] Работа с плейсхолдерами example_* в проде блокируется (503), а не просто
//       пишется warning в лог.
// [5.3] Фаталы/исключения больше не вклеивают HTML посреди半成品-страницы:
//       глобальный ob_start() + очистка всех буферов перед выводом ошибки;
//       код 500 выставляется всегда (раньше при headers_sent оставался 200).
// [5.4] Пинг/реконнект соединения выполняется не только в CLI — будущие воркеры
//       очереди писем в веб-SAPI тоже переживут wait_timeout.
// [5.7] DOCUMENTED: все TIMESTAMP пишутся/читаются в APP_TIMEZONE соединения;
//       дамп database.sql в UTC помечен как требующий SET time_zone при импорте.
// [new] Единая точка входа для фоновых задач: cron вызывает install/cron.php,
//       сессионный GC из веб-запросов убран (см. includes/session.php).

// ---------- Загрузка значений: env -> local -> дефолт ----------
$__local = [];
if (is_file(__DIR__ . '/config.local.php')) {
    $__local = require __DIR__ . '/config.local.php';
    if (!is_array($__local)) $__local = [];
}
/**
 * forumCfg('DB_PASS', 'default') — приоритет: FORUM_DB_PASS (env) > config.local.php > default.
 */
function forumCfg($key, $default = null) {
    global $__local;
    $env = getenv('FORUM_' . $key);
    if ($env !== false && $env !== '') return $env;
    if (array_key_exists($key, $__local)) return $__local[$key];
    return $default;
}
function forumDefine($key, $default) {
    if (!defined($key)) define($key, forumCfg($key, $default));
}

forumDefine('DB_HOST', '127.0.0.1');
forumDefine('DB_PORT', '3308');
forumDefine('DB_NAME', 'example_db');
forumDefine('DB_USER', 'example_db');
forumDefine('DB_PASS', 'example_db_password');
forumDefine('DB_CHARSET', 'utf8mb4');

forumDefine('APP_DEBUG', false);
forumDefine('TRUSTED_PROXIES', []);          // ['127.0.0.1', '10.0.'] — префиксы доверенных прокси
forumDefine('FORCE_SECURE_COOKIES', false);  // true за TLS-терминирующим прокси
forumDefine('APP_TIMEZONE', 'Europe/Moscow');

forumDefine('APP_URL', 'https://forum.example.com');
forumDefine('MAIL_FROM', 'noreply@forum.example.com');
forumDefine('MAIL_FROM_NAME', 'ForumHub');
forumDefine('MAIL_TRANSPORT', 'mail');       // 'mail' | 'smtp'
forumDefine('SMTP_HOST', 'localhost');
forumDefine('SMTP_PORT', 587);
forumDefine('SMTP_USER', '');
forumDefine('SMTP_PASS', '');
forumDefine('SMTP_SECURE', 'tls');           // 'tls' | 'ssl' | ''
forumDefine('SMTP_EHLO_DOMAIN', 'localhost');
// Жёсткий бюджет на ОДНУ SMTP-сессию (секунды), поверх per-read таймаута.
forumDefine('SMTP_TOTAL_TIMEOUT', 20);

// Валидный bcrypt-хэш для фиктивной проверки пароля (анти-тайминг-энумерация).
forumDefine('DUMMY_PASSWORD_HASH', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

date_default_timezone_set(APP_TIMEZONE);

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
error_reporting(E_ALL);

// ---------- Прод-конфиг не заполнен: fail-closed, а не warning ----------
if (!APP_DEBUG && PHP_SAPI !== 'cli') {
    $placeholders = [];
    if (DB_NAME === 'example_db')            $placeholders[] = 'DB_NAME';
    if (DB_PASS === 'example_password')      $placeholders[] = 'DB_PASS';
    if (strpos(APP_URL, 'example.com') !== false)   $placeholders[] = 'APP_URL';
    if (strpos(MAIL_FROM, 'example.com') !== false) $placeholders[] = 'MAIL_FROM';
    if ($placeholders) {
        error_log('CONFIG FATAL: placeholder values in production: ' . implode(', ', $placeholders));
        http_response_code(503);
        header('Retry-After: 3600');
        exit('<!DOCTYPE html><html lang="ru"><meta charset="utf-8"><title>Не настроено</title>'
           . '<body style="font-family:sans-serif;padding:48px;text-align:center">'
           . '<h1>Форум ещё не настроен</h1><p>Администратор: заполните config/config.local.php или переменные FORUM_*.</p></body></html>');
    }
}

// ---------- Глобальный буфер вывода: ошибка не склеивается с полустраницей ----------
if (PHP_SAPI !== 'cli' && !ob_get_level()) {
    ob_start();
}

// ---------- Заголовки безопасности (CSP страниц — в header.php) ----------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (FORCE_SECURE_COOKIES || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Единый аварийный вывод: сбрасывает ВСЕ ob-буферы (включая частичную разметку
 * страницы и кэш-буфер index.php), затем отдаёт целостную страницу ошибки.
 */
function forumPanicOutput($detailForDebug) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    if (APP_DEBUG) {
        echo '<pre style="padding:24px;background:#fee2e2;color:#991b1b">'
           . htmlspecialchars((string)$detailForDebug, ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo '<!DOCTYPE html><html lang="ru"><meta charset="utf-8"><title>Ошибка</title>'
           . '<body style="font-family:sans-serif;padding:48px;text-align:center">'
           . '<h1>Внутренняя ошибка</h1><p>Мы уже работаем над этим. Попробуйте позже.</p>'
           . '<p><a href="/index.php">На главную</a></p></body></html>';
    }
}

set_exception_handler(function ($e) {
    error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    forumPanicOutput((string)$e);
    exit;
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('Fatal: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
        forumPanicOutput($e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
    }
});

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
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
            error_log('Database connection error: ' . $e->getMessage());
            while (ob_get_level() > 0) { @ob_end_clean(); }
            if (!headers_sent()) { http_response_code(503); header('Retry-After: 60'); }
            exit('<!DOCTYPE html><html lang="ru"><meta charset="utf-8"><title>Недоступно</title>'
               . '<body style="font-family:sans-serif;padding:48px;text-align:center">'
               . '<h1>Сервис временно недоступен</h1><p>Попробуйте обновить страницу через минуту.</p></body></html>');
        }
    }

    public static function getInstance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function getConnection() {
        // Пингуем в CLI и в долгоживущих процессах (очередь писем). Обычный
        // веб-запрос короткоживущий — SELECT 1 на каждый хит не нужен; для
        // воркеров вызывайте Database::getInstance()->ping() явно после паузы.
        if (PHP_SAPI === 'cli') $this->ping();
        return $this->connection;
    }

    /** Явный пинг с переподключением — для воркеров/демонов любого SAPI. */
    public function ping() {
        try {
            $this->connection->query('SELECT 1');
        } catch (PDOException $e) {
            error_log('DB reconnect: ' . $e->getMessage());
            $fresh = new self();
            $this->connection = $fresh->connection;
        }
        return $this->connection;
    }
}
