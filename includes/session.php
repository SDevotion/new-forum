<?php
// includes/session.php — хранение сессий в БД (таблица user_sessions).
// Для авторизованных: строка user_sessions (user_id > 0).
// Для гостей: строка-«якорь» (user_id = 0) — нужна для онлайн-трекинга гостей.
// $_SESSION остаётся рабочим API, но живёт в БД, а не в файлах.
// Совместимо с PHP 7.x и 8.x: без union-типов и без атрибутов.

require_once __DIR__ . '/../config/database.php';

if (!defined('SESSION_NAME'))     define('SESSION_NAME', 'FORUMSESSID');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 30 * 86400); // скользящее окно, сек.

class DbSessionHandler implements SessionHandlerInterface {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function open($savePath, $sessionName) {
        return true;
    }

    public function close() {
        return true;
    }

    public function read($id) {
        try {
            $stmt = $this->db->prepare(
                "SELECT payload FROM user_sessions WHERE session_token = ? AND expires_at > NOW()"
            );
            $stmt->execute([$id]);
            $payload = $stmt->fetchColumn();
            if ($payload === false) {
                return '';
            }
            // Скользящее продление жизни сессии
            $this->db->prepare(
                "UPDATE user_sessions SET expires_at = NOW() + INTERVAL ? SECOND WHERE session_token = ?"
            )->execute([SESSION_LIFETIME, $id]);
            return (string)$payload;
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
            return $stmt->execute([
                $uid, $id,
                isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
                mb_substr(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 0, 1000),
                $data,
                SESSION_LIFETIME,
            ]);
        } catch (PDOException $e) {
            error_log('Session write error: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy($id) {
        try {
            $this->db->prepare("DELETE FROM user_sessions WHERE session_token = ?")->execute([$id]);
            return true;
        } catch (PDOException $e) {
            error_log('Session destroy error: ' . $e->getMessage());
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
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $handler = new DbSessionHandler();
    session_set_save_handler($handler, true);
    session_start();

    // Вероятностный gc самого PHP ненадёжен (на части систем отключён вовсе),
    // поэтому чистим протухшие строки явно: ~1 запрос из 100
    if (random_int(1, 100) === 1) {
        $handler->gc(SESSION_LIFETIME);
    }
}