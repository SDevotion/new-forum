<?php
// includes/session.php — хранение сессий в БД (user_sessions).
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [parse error] Убран union-тип ": int|false" (PHP 8.0+) — файл совместим с PHP 7.2+.
// [1.2] Гонка логина: write() при переходе guest->user берёт лок и перечитывает
//       актуальный payload; устаревший payload не затирает более свежие данные.
// [1.3] Таймаут сессионного лока = честный 503 + Retry-After, а не тихая пустая
//       сессия (POST не отрабатывает как гостевой с потерей формы).
// [4.5/6.logout] Пустые гостевые сессии не пишутся в БД: краулеры без cookie-jar
//       и logout больше не плодят строки user_sessions.
// [5.2] Убраны фолбэки «без updated_ts»: отсутствие колонки — явная ошибка в лог.
// [5.8] GC убран из веб-запросов — чистка только через install/cron.php.
// Лок освобождается строго один раз (write/destroy/close идемпотентны).

require_once __DIR__ . '/../config/database.php';

if (!defined('SESSION_NAME'))             define('SESSION_NAME', 'FORUMSESSID');
if (!defined('SESSION_LIFETIME'))         define('SESSION_LIFETIME', 30 * 86400);
if (!defined('GUEST_SESSION_LIFETIME'))   define('GUEST_SESSION_LIFETIME', 6 * 3600);
if (!defined('SESSION_REFRESH_INTERVAL')) define('SESSION_REFRESH_INTERVAL', 300);
if (!defined('SESSION_LOCK_WAIT'))        define('SESSION_LOCK_WAIT', 10);

class DbSessionHandler implements SessionHandlerInterface {
    private $db;
    private $lockName = null;
    private $lastPayload = null;
    private $lastWriteTs = 0;
    private $exists = false;
    private $readUid = 0;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    private function acquireLock($id) {
        $name = 'sess:' . md5($id);
        $st = $this->db->prepare('SELECT GET_LOCK(?, ?)');
        $st->execute([$name, SESSION_LOCK_WAIT]);
        if ((int)$st->fetchColumn() !== 1) return false;
        $this->lockName = $name;
        return true;
    }

    private function releaseLock() {
        if ($this->lockName !== null) {
            try {
                $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->lockName]);
            } catch (PDOException $e) { /* соединение умерло — лок снимется сервером */ }
            $this->lockName = null;
        }
    }

    public function open($savePath, $sessionName) { return true; }

    public function close() {
        $this->releaseLock();
        return true;
    }

    public function read($id) {
        try {
            $stmt = $this->db->prepare(
                'SELECT user_id, payload, UNIX_TIMESTAMP(updated_ts) AS ts
                 FROM user_sessions WHERE session_token = ? AND expires_at > NOW()'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            if (!$row) {
                $this->exists = false;
                $this->lastPayload = null;
                $this->lastWriteTs = 0;
                $this->readUid = 0;
                return '';
            }
            $this->exists = true;
            $this->lastPayload = (string)$row['payload'];
            $this->lastWriteTs = (int)$row['ts'];
            $this->readUid = (int)$row['user_id'];

            if ($this->readUid > 0 && !$this->acquireLock($id)) {
                // Не выдаём данные без лока и НЕ притворяемся гостем.
                error_log('Session lock timeout, rejecting request: ' . $id);
                while (ob_get_level() > 0) { @ob_end_clean(); }
                if (!headers_sent()) {
                    http_response_code(503);
                    header('Retry-After: 2');
                    header('Content-Type: text/html; charset=utf-8');
                }
                exit('<!DOCTYPE html><html lang="ru"><meta charset="utf-8"><title>Подождите</title>'
                   . '<body style="font-family:sans-serif;padding:48px;text-align:center">'
                   . '<h1>Секунду…</h1><p>Предыдущий запрос ещё обрабатывается. Обновите страницу.</p></body></html>');
            }
            return $this->lastPayload;
        } catch (PDOException $e) {
            error_log('Session read error: ' . $e->getMessage());
            $this->releaseLock();
            return '';
        }
    }

    public function write($id, $data) {
        $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

        // Пустая гостевая сессия — не пишем ничего (краулеры, HEAD-хиты, logout).
        if ($uid === 0 && ($data === '' || $data === null)) {
            if ($this->exists) {
                try {
                    $this->db->prepare('DELETE FROM user_sessions WHERE session_token = ?')->execute([$id]);
                } catch (PDOException $e) { error_log('Session purge error: ' . $e->getMessage()); }
                $this->exists = false;
            }
            $this->releaseLock();
            return true;
        }

        // Переход guest->user в этом запросе (логин): лока ещё нет.
        $justLoggedIn = ($uid > 0 && $this->lockName === null);
        if ($justLoggedIn) {
            if (!$this->acquireLock($id)) {
                error_log('Session write lock timeout: ' . $id);
                return false;
            }
            // Под локом перечитываем: параллельный запрос мог записать более свежие
            // данные этой сессии — не затираем их устаревшим payload.
            try {
                $st = $this->db->prepare('SELECT payload, user_id FROM user_sessions WHERE session_token = ?');
                $st->execute([$id]);
                $cur = $st->fetch();
                if ($cur && (string)$cur['payload'] !== (string)$this->lastPayload
                          && (int)$cur['user_id'] === $uid) {
                    $base = [];
                    $ours = [];
                    session_decode_safe($cur['payload'], $base);
                    session_decode_safe($data, $ours);
                    foreach (['user_id', 'username', 'csrf_pool', 'flash_message'] as $k) {
                        if (array_key_exists($k, $ours)) $base[$k] = $ours[$k];
                    }
                    $data = session_encode_array($base);
                    error_log('Session login-race merged for ' . $id);
                }
            } catch (PDOException $e) { /* перечтение не удалось — пишем как есть */ }
        }

        try {
            $now = time();
            $changed = ($data !== $this->lastPayload);
            $stale = ($now - $this->lastWriteTs) >= SESSION_REFRESH_INTERVAL;

            if ($this->exists && !$changed && !$stale) return true;

            $lifetime = $uid > 0 ? SESSION_LIFETIME : GUEST_SESSION_LIFETIME;

            if ($this->exists && !$changed && $stale) {
                $stmt = $this->db->prepare(
                    'UPDATE user_sessions SET expires_at = NOW() + INTERVAL ? SECOND, updated_ts = NOW()
                     WHERE session_token = ?'
                );
                $ok = $stmt->execute([$lifetime, $id]);
            } else {
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000);
                $stmt = $this->db->prepare('
                    INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, payload, expires_at, updated_ts)
                    VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND, NOW())
                    ON DUPLICATE KEY UPDATE
                        user_id = VALUES(user_id), ip_address = VALUES(ip_address),
                        user_agent = VALUES(user_agent), payload = VALUES(payload),
                        expires_at = VALUES(expires_at), updated_ts = NOW()
                ');
                $ok = $stmt->execute([$uid, $id, $ip, $ua, $data, $lifetime]);
            }

            $this->lastPayload = $data;
            $this->lastWriteTs = $now;
            $this->exists = true;
            return $ok;
        } catch (PDOException $e) {
            error_log('Session write error: ' . $e->getMessage());
            return false;
        } finally {
            $this->releaseLock();
        }
    }

    public function destroy($id) {
        try {
            $this->db->prepare('DELETE FROM user_sessions WHERE session_token = ?')->execute([$id]);
            $this->exists = false;
            $this->lastPayload = null;
            return true;
        } catch (PDOException $e) {
            error_log('Session destroy error: ' . $e->getMessage());
            return false;
        } finally {
            $this->releaseLock();
        }
    }

    /** Только из install/cron.php. Пачки по 500 с паузой. */
    public function gc($maxLifetime) {
        try {
            $total = 0;
            do {
                $n = $this->db->exec('DELETE FROM user_sessions WHERE expires_at < NOW() LIMIT 500');
                $total += $n;
                if ($n === 500) usleep(50000);
            } while ($n === 500);
            return $total;
        } catch (PDOException $e) {
            error_log('Session gc error: ' . $e->getMessage());
            return false;
        }
    }
}

/** Декодирование session-payload в массив без влияния на текущую $_SESSION. */
function session_decode_safe($payload, &$out) {
    $backup = isset($_SESSION) ? $_SESSION : [];
    $ok = session_decode((string)$payload);
    $out = $ok ? (isset($_SESSION) ? $_SESSION : []) : [];
    $_SESSION = $backup;
    return $ok;
}
/** Обратная операция: массив -> session-payload. */
function session_encode_array(array $data) {
    $backup = isset($_SESSION) ? $_SESSION : [];
    $_SESSION = $data;
    $enc = session_encode();
    $_SESSION = $backup;
    return (string)$enc;
}

function sessionBoot() {
    if (session_status() !== PHP_SESSION_NONE) return;
    $secure = (defined('FORCE_SECURE_COOKIES') && FORCE_SECURE_COOKIES)
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    ini_set('session.gc_probability', '0'); // GC — только cron
    $handler = new DbSessionHandler();
    session_set_save_handler($handler, true);
    session_start();
}