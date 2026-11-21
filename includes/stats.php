<?php
/**
 * Онлайн-трекинг и сводная статистика
 */
require_once __DIR__ . '/functions.php';

if (!defined('ONLINE_WINDOW')) define('ONLINE_WINDOW', max(60, setting('online_window', 900)));

/**
 * Отметка активности авторизованного пользователя.
 * Пишем в БД не чаще раза в минуту, чтобы не грузить базу.
 */
function touchActivity() {
    if (!isLoggedIn()) return;

    $id = (int)$_SESSION['user_id'];
    $now = time();
    // Троттлинг: не чаще раза в 60 сек, метка ставится ДО проверки записи,
    // чтобы серия быстрых запросов не писала в users на каждый клик
    if (isset($_SESSION['activity_ts']) && ($now - (int)$_SESSION['activity_ts']) < 60) return;
    $_SESSION['activity_ts'] = $now;

    try {
        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$id]);
    } catch (PDOException $e) {
        error_log('Activity error: ' . $e->getMessage());
    }
}

/**
 * Отметка активности гостя: строка user_sessions (user_id = 0).
 * created_at фиксирует первый визит, «свежесть» даёт expires_at,
 * который обработчик продлевает на каждом запросе (скользящее окно).
 * Чтобы гости учитывались в ONLINE_WINDOW, отдельно трогаем updated-метку.
 */
function trackGuest() {
    if (isLoggedIn()) return;
    $_SESSION['guest_seen'] = time(); // маркер для payload; свежесть — в expires_at
}

/**
 * Список участников онлайн
 */
function getOnlineList() {
    $db = Database::getInstance()->getConnection();
    $w = (int)ONLINE_WINDOW;

    $users = $db->query("
        SELECT id, username, avatar, role, last_activity
        FROM users
        WHERE is_active = 1 AND last_activity >= DATE_SUB(NOW(), INTERVAL $w SECOND)
        ORDER BY last_activity DESC
        LIMIT 100
    ")->fetchAll();

    // Гости — строки user_sessions с user_id = 0, «тронутые» в пределах окна.
    // Обработчик на каждом запросе продлевает expires_at на SESSION_LIFETIME,
    // поэтому момент последнего визита = expires_at - SESSION_LIFETIME.
    // Кэш на запрос.
    static $guestCount = null;
    if ($guestCount === null) {
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM user_sessions
                WHERE user_id = 0
                  AND expires_at > NOW()
                  AND expires_at <= NOW() + INTERVAL ? SECOND
                  AND expires_at >= NOW() + INTERVAL ? SECOND
            ");
            // последний визит в пределах ONLINE_WINDOW:
            // (expires_at - SESSION_LIFETIME) >= NOW() - ONLINE_WINDOW
            $stmt->execute([
                SESSION_LIFETIME,
                SESSION_LIFETIME - $w,
            ]);
            $guestCount = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('Guest count error: ' . $e->getMessage());
            $guestCount = 0;
        }
    }
    $guests = $guestCount;

    return ['users' => $users, 'count' => count($users), 'guests' => $guests];
}

/**
 * Сводные счётчики форума (кэш в рамках одного запроса)
 */
function getForumTotals() {
    static $data = null;
    if ($data !== null) return $data;

    $db = Database::getInstance()->getConnection();
    $data = [
        'topics' => (int)$db->query("SELECT COUNT(*) FROM topics WHERE is_hidden = 0")->fetchColumn(),
        'posts'  => (int)$db->query("SELECT COUNT(*) FROM posts WHERE is_hidden = 0")->fetchColumn(),
        'users'  => (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn(),
        'newest' => $db->query("SELECT id, username FROM users WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch(),
        'today_posts'  => (int)$db->query("SELECT COUNT(*) FROM posts WHERE is_hidden = 0 AND created_at >= CURDATE()")->fetchColumn(),
        'today_topics' => (int)$db->query("SELECT COUNT(*) FROM topics WHERE is_hidden = 0 AND created_at >= CURDATE()")->fetchColumn(),
        'today_users'  => (int)$db->query("SELECT COUNT(*) FROM users WHERE created_at >= CURDATE()")->fetchColumn(),
    ];

    return $data;
}