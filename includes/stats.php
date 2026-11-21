<?php
/**
 * Онлайн-трекинг и сводная статистика
 */
require_once __DIR__ . '/functions.php';

if (!defined('ONLINE_WINDOW')) define('ONLINE_WINDOW', max(60, setting('online_window', 900)));

/**
 * Отметка активности авторизованного пользователя (не чаще раза в минуту).
 */
function touchActivity() {
    if (!isLoggedIn()) return;

    $id = (int)$_SESSION['user_id'];
    $now = time();
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
 * Отметка активности гостя. Троттлинг 60 сек: без него каждая страница гостя
 * (включая ботов) форсировала запись payload сессии в БД.
 * «Свежесть» для подсчёта онлайн даёт expires_at, который write() продлевает
 * при любой записи сессии; точность окна ONLINE_WINDOW (>= 60 сек) сохраняется.
 */
function trackGuest() {
    if (isLoggedIn()) return;
    $now = time();
    if (isset($_SESSION['guest_seen']) && ($now - (int)$_SESSION['guest_seen']) < 60) return;
    $_SESSION['guest_seen'] = $now;
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

    return ['users' => $users, 'count' => count($users), 'guests' => $guestCount];
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
