<?php
// install/cron.php — единая фоновая задача (CLI only).
// Crontab: */5 * * * * php /path/to/install/cron.php >> /var/log/forum-cron.log 2>&1
//
// РЕШЕНО:
// [5.8] GC сессий вне веб-запросов.
// [1.4/4.5] Регулярная чистка post_dedup/rate_limits.
// [2.6] Доставка почты из очереди с бюджетом времени.
// [new] После снятия FK notifications.topic_id/post_id (миграция части 4):
//       уведомления на удалённые темы/посты обнуляются/удаляются здесь.

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/mailer.php';

$db = Database::getInstance()->getConnection();
$t0 = microtime(true);
$log = function ($msg) { echo '[' . date('Y-m-d H:i:s') . "] $msg\n"; };

$got = $db->query("SELECT GET_LOCK('forum_cron', 0)");
if ((int)$got->fetchColumn() !== 1) { $log('another cron run in progress, skip'); exit(0); }

try {
    // 1) Почта
    $sent = mailQueueProcess(90, 300);
    $log("mail: sent $sent");

    // 2) Сессии
    $handler = new DbSessionHandler();
    $n = $handler->gc(SESSION_LIFETIME);
    $log('sessions gc: ' . (int)$n);

    // 3) Лимитеры и антидубль
    $total = 0;
    do {
        $n = $db->exec('DELETE FROM rate_limits WHERE created_at < NOW() - INTERVAL 1 DAY LIMIT 2000');
        $total += $n;
    } while ($n === 2000);
    do {
        $n = $db->exec('DELETE FROM post_dedup WHERE created_at < NOW() - INTERVAL 1 DAY LIMIT 2000');
        $total += $n;
    } while ($n === 2000);
    $log("rate_limits/post_dedup: $total");

    // 4) Токены сброса
    $n = $db->exec('DELETE FROM password_resets WHERE expires_at < NOW()');
    $log("password_resets: $n");

    // 5) Прочитанные уведомления старше 30 дней
    $n = $db->exec('DELETE FROM notifications WHERE is_read = 1 AND created_at < NOW() - INTERVAL 30 DAY LIMIT 5000');
    $log("notifications old: $n");

    // 6) Уведомления-сироты (FK снят миграцией): тема/пост удалены —
    //    привязку обнуляем; полностью мёртвые (без темы, не moderation) удаляем.
    $n = $db->exec("
        UPDATE notifications n LEFT JOIN topics t ON t.id = n.topic_id
        SET n.topic_id = 0 WHERE n.topic_id > 0 AND t.id IS NULL
    ");
    $n += $db->exec("
        UPDATE notifications n LEFT JOIN posts p ON p.id = n.post_id
        SET n.post_id = 0 WHERE n.post_id > 0 AND p.id IS NULL
    ");
    $n += $db->exec("
        DELETE FROM notifications WHERE topic_id = 0 AND type <> 'moderation' LIMIT 5000
    ");
    $log("notifications orphans: $n");

    $log('done in ' . round(microtime(true) - $t0, 1) . 's');
} finally {
    $db->query("SELECT RELEASE_LOCK('forum_cron')");
}