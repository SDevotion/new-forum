<?php
// includes/session.php — хранение сессий в БД (таблица user_sessions).
// Блокировка на время запроса (GET_LOCK) — как у файлового обработчика PHP:
// параллельные запросы одной сессии сериализуются, потерянных обновлений нет.

require_once __DIR__ . '/../config/database.php';

if (!defined('SESSION_NAME'))     define('SESSION_NAME', 'FORUMSESSID');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 30 * 86400);

class DbSessionHandler implements SessionHandlerInterface {
    private $db;
    private $lockName = null;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    private function acquireLock($id) {
        $this->lockName = 'sess:' . md5($id);
        $st = $this->db->prepare("SELECT GET_LOCK(?, 10)");
        $st->execute([$this->lockName]);
        if ((int)$st->fetchColumn() !== 1) {
            $this->lockName = null;
            return false;
        }
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
            if (!$this->acquireLock($id)) {
                error_log('Session lock timeout: ' . $id);
                return ''; // деградируем в пустую сессию вместо гонки
            }
            $stmt = $this->db->prepare(
                "SELECT payload FROM user_sessions WHERE session_token = ? AND expires_at > NOW()"
            );
            $stmt->execute([$id]);
            $payload = $stmt->fetchColumn();
            return $payload === false ? '' : (string)$payload;
        } catch (PDOException $e) {
            error_log('Session read error: ' . $e->getMessage());
            return '';
        }
    }

    public function write($id, $data) {
        try {
            $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
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
            $ok = $stmt->execute([
                $uid, $id,
                isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
                mb_substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 1000),
                $data,
                SESSION_LIFETIME,
            ]);
            $this->releaseLock();
            return $ok;
        } catch (PDOException $e) {
            error_log('Session write error: ' . $e->getMessage());
            $this->releaseLock();
            return false;
        }
    }

    public function destroy($id) {
        try {
            $this->db->prepare("DELETE FROM user_sessions WHERE session_token = ?")->execute([$id]);
            $this->releaseLock();
            return true;
        } catch (PDOException $e) {
            error_log('Session destroy error: ' . $e->getMessage());
            $this->releaseLock();
            return false;
        }
    }

    public function gc($maxLifetime) {
        try {
            return $this->db->exec("DELETE FROM user_sessions WHERE expires_at < NOW()");
        } catch (PDOException $e) {
            error_log('Session gc error: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Старт сессии с защищёнными cookie (вызывается из functions.php).
 */
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

    if (random_int(1, 100) === 1) {
        $handler->gc(SESSION_LIFETIME);
    }
}
