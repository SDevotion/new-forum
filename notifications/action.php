<?php
// notifications/action.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [6.массовый 500] Ввод через postStr()/postInt(); back — safeBackUrl().
// [4.2] После read/read_all/delete сбрасывается APCu-кэш unreadCount.
// [new] topic_id/post_id = 0 (миграция NOT NULL) обрабатываются как «без привязки».
// Вычисление страницы поста, проверки видимости — сохранены.

require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/visibility.php';

if (!isLoggedIn()) redirect('/auth/login.php');
enforceActiveUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/notifications.php');
if (!verifyCSRFToken(postStr('csrf_token'))) {
    setFlashMessage('error', 'Недействительный запрос');
    redirect('/notifications.php');
}

$db = Database::getInstance()->getConnection();
$uid = currentUserId();
$action = postStr('action');
$back = safeBackUrl(postStr('back'), '/notifications.php');

try {
    if ($action === 'read_all') {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$uid]);
        notifCacheBump($uid);
        setFlashMessage('success', 'Все уведомления прочитаны');

    } elseif ($action === 'delete_all') {
        $db->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1")->execute([$uid]);
        notifCacheBump($uid);
        setFlashMessage('success', 'Прочитанные уведомления удалены');

    } elseif ($action === 'read') {
        $id = postInt('id');
        $stmt = $db->prepare("SELECT topic_id, post_id, excerpt FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $uid]);
        $n = $stmt->fetch();

        if ($n) {
            $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
            notifCacheBump($uid);

            if (empty($n['topic_id'])) {
                setFlashMessage('info', $n['excerpt'] ?: 'Уведомление прочитано');
                redirect($back);
            }

            $topicId = (int)$n['topic_id'];
            $url = '/topic.php?id=' . $topicId;

            if (!topicVisible($topicId)) {
                setFlashMessage('info', 'Тема больше недоступна');
                redirect($back);
            }

            if ($n['post_id']) {
                $staffFlag = isModerator() ? 1 : 0;
                $postId = (int)$n['post_id'];
                $st = $db->prepare("SELECT created_at, is_hidden FROM posts WHERE id = ? AND topic_id = ?");
                $st->execute([$postId, $topicId]);
                $p = $st->fetch();
                if ($p && $p['is_hidden'] && !$staffFlag) {
                    setFlashMessage('info', 'Сообщение скрыто модератором');
                    redirect($back);
                }
                if (!$p) {
                    setFlashMessage('info', 'Сообщение было удалено');
                    redirect($url);
                }
                $st = $db->prepare("
                    SELECT COUNT(*) FROM posts
                    WHERE topic_id = ? AND (is_hidden = 0 OR ? = 1)
                      AND (created_at < ? OR (created_at = ? AND id <= ?))
                ");
                $st->execute([$topicId, $staffFlag, $p['created_at'], $p['created_at'], $postId]);
                $pos = (int)$st->fetchColumn();
                $url .= '&page=' . max(1, (int)ceil($pos / max(1, setting('per_page_posts', 15))));
                $url .= '#post-' . $postId;
            }
            redirect($url);
        }
        setFlashMessage('error', 'Уведомление не найдено');

    } elseif ($action === 'delete') {
        $id = postInt('id');
        $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
        notifCacheBump($uid);
        setFlashMessage('success', 'Уведомление удалено');
    }
} catch (PDOException $e) {
    error_log('Notification action error: ' . $e->getMessage());
    setFlashMessage('error', 'Ошибка при выполнении действия');
}

redirect($back);