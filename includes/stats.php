<?php
// includes/stats.php — онлайн-трекинг и сводная статистика.
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [4.6] touchActivity(): presence пишется в APCu на каждый тик, а UPDATE users —
//       не чаще раза в 5 минут на пользователя (горячая строка users разгружена
//       в ~5 раз; точность «онлайн» сохраняется через APCu-слой в getOnlineList).
// [4.5] trackGuest(): гости без cookie-jar больше не создают сессию ради метки —
//       guest_seen хранится в APCu по IP; счётчик гостей считается по нему.
//       Фолбэк без APCu — прежняя сессионная метка (но пустые гостевые сессии
//       не пишутся в БД благодаря session.php части 1).
// getForumTotals — без изменений (APCu 60с уже был).

require_once __DIR__ . '/functions.php';

if (!defined('ONLINE_WINDOW')) define('ONLINE_WINDOW', max(60, setting('online_window', 900)));
if (!defined('ACTIVITY_DB_INTERVAL')) define('ACTIVITY_DB_INTERVAL', 300);

function statsApcu() {
    return function_exists('apcu_fetch') && (!function_exists('apcu_enabled') || apcu_enabled());
}

function touchActivity() {
    if (!isLoggedIn()) return;
    $id = (int)$_SESSION['user_id'];
    $now = time();
    if (isset($_SESSION['activity_ts']) && ($now - (int)$_SESSION['activity_ts']) < 60) return;
    $_SESSION['activity_ts'] = $now;

    try {
        if (statsApcu()) {
            apcu_store('presence_u_' . $id, $now, ONLINE_WINDOW + 60);
            $lastDb = apcu_fetch('presence_db_' . $id, $ok);
            if ($ok && ($now - (int)$lastDb) < ACTIVITY_DB_INTERVAL) return;
            apcu_store('presence_db_' . $id, $now, ACTIVITY_DB_INTERVAL + 60);
        }
        $db = Database::getInstance()->getConnection();
        $db->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$id]);
    } catch (PDOException $e) {
        error_log('Activity error: ' . $e->getMessage());
    }
}

function trackGuest() {
    if (isLoggedIn()) return;
    $now = time();
    if (statsApcu()) {
        require_once __DIR__ . '/ratelimit.php';
        // Ключ по IP: краулер без cookies не создаёт строку сессии.
        apcu_store('presence_g_' . md5(rateLimitIp()), $now, ONLINE_WINDOW + 60);
        return;
    }
    if (isset($_SESSION['guest_seen']) && ($now - (int)$_SESSION['guest_seen']) < 60) return;
    $_SESSION['guest_seen'] = $now;
}

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

    //Presence из APCu точнее пятиминутного UPDATE: дополняем список тех,
    // кто активен сейчас, но чья строка users ещё не обновлена.
    if (statsApcu() && $users) {
        foreach ($users as &$u) {
            $ts = apcu_fetch('presence_u_' . (int)$u['id'], $ok);
            if ($ok && (int)$ts > strtotime($u['last_activity'])) {
                $u['last_activity'] = date('Y-m-d H:i:s', (int)$ts);
            }
        }
        unset($u);
    }

    static $guestCount = null;
    if ($guestCount === null) {
        $apcu = statsApcu();
        if ($apcu) {
            $hit = apcu_fetch('forum_guest_count', $ok);
            if ($ok) $guestCount = (int)$hit;
        }
        if ($guestCount === null) {
            $guestCount = 0;
            if ($apcu) {
                // Считаем свежие presence_g_* ключи (дёшево: APCu iterator по префиксу).
                try {
                    $now = time();
                    $it = new APCUIterator('/^presence_g_/');
                    foreach ($it as $row) {
                        if ($now - (int)$row['value'] <= $w) $guestCount++;
                    }
                } catch (Throwable $e) { $guestCount = 0; }
            } else {
                try {
                    $stmt = $db->prepare("
                        SELECT COUNT(*) FROM user_sessions
                        WHERE user_id = 0
                          AND expires_at > NOW()
                          AND expires_at <= NOW() + INTERVAL ? SECOND
                          AND expires_at >= NOW() + INTERVAL ? SECOND
                    ");
                    $gl = defined('GUEST_SESSION_LIFETIME') ? GUEST_SESSION_LIFETIME : SESSION_LIFETIME;
                    $stmt->execute([$gl, $gl - $w]);
                    $guestCount = (int)$stmt->fetchColumn();
                } catch (PDOException $e) {
                    error_log('Guest count error: ' . $e->getMessage());
                }
            }
            if ($apcu) apcu_store('forum_guest_count', $guestCount, 30);
        }
    }

    return ['users' => $users, 'count' => count($users), 'guests' => $guestCount];
}

function getForumTotals() {
    static $data = null;
    if ($data !== null) return $data;

    $apcu = statsApcu();
    if ($apcu) {
        $hit = apcu_fetch('forum_totals', $ok);
        if ($ok && is_array($hit)) return $data = $hit;
    }

    $db = Database::getInstance()->getConnection();
    $data = [
        'topics' => (int)$db->query("SELECT COUNT(*) FROM topics WHERE is_hidden = 0")->fetchColumn(),
        'posts'  => (int)$db->query("SELECT COUNT(*) FROM posts WHERE is_hidden = 0")->fetchColumn(),
        'users'  => (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn(),
        'newest' => $db->query("SELECT id, username FROM users WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch(),
        'today_posts'  => (int)$db->query("SELECT COUNT(*) FROM posts WHERE is_hidden = 0 AND created_at >= CURDATE()")->fetchColumn(),
        'today_topics' => (int)$db->query("SELECT COUNT(*) FROM topics WHERE is_hidden = 0 AND created_at >= CURDATE()")->fetchColumn(),
        'today_users'  => (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active = 1 AND created_at >= CURDATE()")->fetchColumn(),
    ];

    if ($apcu) apcu_store('forum_totals', $data, 60);
    return $data;
}