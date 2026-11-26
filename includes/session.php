<?php
// includes/session.php — хранение сессий в БД (таблица user_sessions).
// Запись только при изменении данных или раз в 5 минут.
// Авторизованные сессии: лок берётся в read() и держится до write()/close() —
// параллельные запросы одной сессии сериализуются ЦЕЛИКОМ (антидубль, flash, CSRF).
// Гостевые — без лока (быстрые просмотры/скачивания).

require_once __DIR__ . '/../config/database.php';

if (!defined('SESSION_NAME'))     define('SESSION_NAME', 'FORUMSESSID');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 30 * 86400);
if (!defined('SESSION_REFRESH_INTERVAL')) define('SESSION_REFRESH_INTERVAL', 300);

class DbSessionHandler implements SessionHandlerInterface {
    private $db;
    private $lockName = null;
    private $lastPayload = null;
    private $lastWriteTs = 0;
    private $exists = false;
    private $lockedForUid = 0;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    private function acquireLock($id) {
        $name = 'sess:' . md5($id);
        $st = $this->db->prepare("SELECT GET_LOCK(?, 10)");
        $st->execute([$name]);
        if ((int)$st->fetchColumn() !== 1) {
            return false;
        }
        $this->lockName = $name;
        return true;
    }

    private function releaseLock() {
        if ($this->lockName !== null) {
            $st = $this->db->prepare("SELECT RELEASE_LOCK(?)");
            $st->execute([$this->lockName]);
            $this->lockName = null;
        }
    }

    public function open($savePath, $sessionName) {
        return true;
    }

    public function close() {
        $this->releaseLock();
        return true;
    }

    public function read($id) {
        try {
            $stmt = $this->db->prepare(
                "SELECT user_id, payload, UNIX_TIMESTAMP(updated_ts) AS ts
                 FROM user_sessions WHERE session_token = ? AND expires_at > NOW()"
            );
            try {
                $stmt->execute([$id]);
                $row = $stmt->fetch();
            } catch (PDOException $e) {
                $stmt = $this->db->prepare(
                    "SELECT user_id, payload, UNIX_TIMESTAMP(created_at) AS ts
                     FROM user_sessions WHERE session_token = ? AND expires_at > NOW()"
                );
                $stmt->execute([$id]);
                $row = $stmt->fetch();
            }

            if (!$row) {
                $this->exists = false;
                $this->lastPayload = null;
                $this->lastWriteTs = 0;
                $this->lockedForUid = 0;
                return '';
            }
            $this->exists = true;
            $this->lastPayload = (string)$row['payload'];
            $this->lastWriteTs = (int)$row['ts'];
            $this->lockedForUid = (int)$row['user_id'];

            // Авторизованная сессия: держим лок весь запрос (до write/close).
            // Так два параллельных POST не прочитают одинаковый payload.
            if ($this->lockedForUid > 0 && !$this->acquireLock($id)) {
                error_log('Session lock timeout: ' . $id);
                // Не отдаём данные без лока — иначе возможна гонка записи.
                $this->exists = false;
                $this->lastPayload = null;
                return '';
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

        // Логин в этом запросе (гость -> пользователь): лока ещё нет — берём.
        // Если лок уже удерживается с read(), повторно не берём.
        $needNewLock = ($uid > 0 && $this->lockName === null);
        if ($needNewLock && !$this->acquireLock($id)) {
            error_log('Session write lock timeout: ' . $id);
            return false;
        }

        try {
            $now = time();
            $changed = ($data !== $this->lastPayload);
            $stale = ($now - $this->lastWriteTs) >= SESSION_REFRESH_INTERVAL;

            if ($this->exists && !$changed && !$stale) {
                return true;
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000);

            if ($this->exists && !$changed && $stale) {
                $stmt = $this->db->prepare(
                    "UPDATE user_sessions SET expires_at = NOW() + INTERVAL ? SECOND, updated_ts = NOW()
                     WHERE session_token = ?"
                );
                try {
                    $ok = $stmt->execute([SESSION_LIFETIME, $id]);
                } catch (PDOException $e) {
                    $stmt = $this->db->prepare(
                        "UPDATE user_sessions SET expires_at = NOW() + INTERVAL ? SECOND WHERE session_token = ?"
                    );
                    $ok = $stmt->execute([SESSION_LIFETIME, $id]);
                }
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, payload, expires_at, updated_ts)
                    VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND, NOW())
                    ON DUPLICATE KEY UPDATE
                        user_id = VALUES(user_id),
                        ip_address = VALUES(ip_address),
                        user_agent = VALUES(user_agent),
                        payload = VALUES(payload),
                        expires_at = VALUES(expires_at),
                        updated_ts = NOW()
                ");
                try {
                    $ok = $stmt->execute([$uid, $id, $ip, $ua, $data, SESSION_LIFETIME]);
                } catch (PDOException $e) {
                    $stmt = $this->db->prepare("
                        INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, payload, expires_at)
                        VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)
                        ON DUPLICATE KEY UPDATE
                            user_id = VALUES(user_id),
                            ip_address = VALUES(ip_address),
                            user_agent = VALUES(user_agent),
                            payload = VALUES(payload),
                            expires_at = VALUES(expires_at)
                    ");
                    $ok = $stmt->execute([$uid, $id, $ip, $ua, $data, SESSION_LIFETIME]);
                }
            }

            $this->lastPayload = $data;
            $this->lastWriteTs = $now;
            $this->exists = true;
            return $ok;
        } catch (PDOException $e) {
            error_log('Session write error: ' . $e->getMessage());
            return false;
        } finally {
            // Лок освобождаем здесь (или в close(), если write не вызывался).
            $this->releaseLock();
        }
    }

    public function destroy($id) {
        try {
            $this->db->prepare("DELETE FROM user_sessions WHERE session_token = ?")->execute([$id]);
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

    public function gc($maxLifetime) {
        try {
            // Пачками, чтобы не держать длинный лок на большой таблице.
            $total = 0;
            do {
                $n = $this->db->exec("DELETE FROM user_sessions WHERE expires_at < NOW() LIMIT 500");
                $total += $n;
            } while ($n === 500);
            return $total;
        } catch (PDOException $e) {
            error_log('Session gc error: ' . $e->getMessage());
            return false;
        }
    }
}

function sessionBoot() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $secure = (defined('FORCE_SECURE_COOKIES') && FORCE_SECURE_COOKIES)
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $handler = new DbSessionHandler();
    session_set_save_handler($handler, true);
    session_start();

    if (random_int(1, 200) === 1) {
        $handler->gc(SESSION_LIFETIME);
    }
}
