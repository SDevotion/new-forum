<?php
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/permissions.php';

if (!isLoggedIn()) redirect('/auth/login.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/notifications.php');
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlashMessage('error', 'Недействительный запрос');
    redirect('/notifications.php');
}

$db = Database::getInstance()->getConnection();
$uid = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';
$back = $_POST['back'] ?? '/notifications.php';
if (!preg_match('~^/[A-Za-z0-9_./?=&#%-]*$~', $back)) $back = '/notifications.php';

try {
    if ($action === 'read_all') {
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$uid]);
        setFlashMessage('success', 'Все уведомления прочитаны');

    } elseif ($action === 'delete_all') {
        $db->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1")->execute([$uid]);
        setFlashMessage('success', 'Прочитанные уведомления удалены');

    } elseif ($action === 'read') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT topic_id, post_id FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $uid]);
        $n = $stmt->fetch();

        if ($n) {
            $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $uid]);

            $topicId = (int)$n['topic_id'];
            $url = '/topic.php?id=' . $topicId;

            // Тема могла быть скрыта или её раздел отключён — проверяем доступность
            // (стафф видит скрытые темы, как и в topic.php)
            $staffFlag = isModerator() ? 1 : 0;
            $st = $db->prepare("
                SELECT t.id FROM topics t
                JOIN forums f ON f.id = t.forum_id
                JOIN categories c ON c.id = f.category_id
                WHERE t.id = ? AND f.is_active = 1 AND c.is_active = 1 AND (t.is_hidden = 0 OR ? = 1)
            ");
            $st->execute([$topicId, $staffFlag]);
            if (!$st->fetch()) {
                setFlashMessage('info', 'Тема больше недоступна');
                redirect($back);
            }

            // Вычисляем страницу пагинации, на которой находится пост
            // (скрытые посты учитываются для стаффа — как в topic.php)
            if ($n['post_id']) {
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
                if ($p) {
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
            }
            redirect($url);
        }
        setFlashMessage('error', 'Уведомление не найдено');

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")->execute([$id, $uid]);
        setFlashMessage('success', 'Уведомление удалено');
    }
} catch (PDOException $e) {
    error_log('Notification action error: ' . $e->getMessage());
    setFlashMessage('error', 'Ошибка при выполнении действия');
}

redirect($back);