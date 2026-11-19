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
    if (isset($_SESSION['activity_ts']) && ($now - $_SESSION['activity_ts']) < 60) return;
    $_SESSION['activity_ts'] = $now;

    try {
        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$id]);
    } catch (PDOException $e) {
        error_log('Activity error: ' . $e->getMessage());
    }
}

/**
 * Гость помечается временем изменения файла сессии — отдельная таблица не нужна
 */
function trackGuest() {
    if (isLoggedIn()) return;
    $_SESSION['guest_seen'] = time();
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

    // Гости — приблизительно, по свежим файлам сессий (только файловый обработчик; кэш на запрос)
    static $guestFiles = null;
    if ($guestFiles === null) {
        $guestFiles = 0;
        if (ini_get('session.save_handler') === 'files') {
            $sessDir = session_save_path() ?: sys_get_temp_dir();
            if (is_dir($sessDir) && is_readable($sessDir)) {
                $files = @glob($sessDir . '/sess_*');
                if ($files) {
                    $cut = time() - ONLINE_WINDOW;
                    foreach ($files as $f) {
                        if (@filemtime($f) >= $cut) $guestFiles++;
                    }
                }
            }
        }
    }
    $guests = max(0, $guestFiles - count($users));

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
        'today_posts'  => (int)$db->query("SELECT COUNT(*) FROM posts WHERE created_at >= CURDATE()")->fetchColumn(),
        'today_topics' => (int)$db->query("SELECT COUNT(*) FROM topics WHERE created_at >= CURDATE()")->fetchColumn(),
        'today_users'  => (int)$db->query("SELECT COUNT(*) FROM users WHERE created_at >= CURDATE()")->fetchColumn(),
    ];

    return $data;
}