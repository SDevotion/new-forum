<?php
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/attachments.php';
requireModerator();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/moderation/index.php');
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) { setFlashMessage('error', 'Недействительный запрос'); redirect('/moderation/index.php'); }

$db = Database::getInstance()->getConnection();
$me = getCurrentUser();
$action = $_POST['action'] ?? '';
$reason = trim($_POST['reason'] ?? '');
$id = (int)($_POST['id'] ?? 0);
$back = $_POST['back'] ?? '/moderation/index.php';
if (!preg_match('~^/[A-Za-z0-9_./?=&#%-]*$~', $back)) $back = '/moderation/index.php';

function done($msg, $back, $type = 'success') { setFlashMessage($type, $msg); redirect($back); }

try {
    switch ($action) {

        case 'topic_pin':
        case 'topic_close':
        case 'topic_hide': {
            $col = ['topic_pin' => 'is_pinned', 'topic_close' => 'is_closed', 'topic_hide' => 'is_hidden'][$action];
            $stmt = $db->prepare("SELECT id, `$col` AS val FROM topics WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) done('Тема не найдена', $back, 'error');

            $new = $row['val'] ? 0 : 1;
            $db->prepare("UPDATE topics SET `$col` = ? WHERE id = ?")->execute([$new, $id]);
            // Скрытая тема: убираем уведомления, ведущие в неё (кроме модерационных)
            if ($action === 'topic_hide' && $new) {
                $db->prepare("DELETE FROM notifications WHERE topic_id = ? AND type <> 'moderation'")->execute([$id]);
            }
            logModeration($action . ($new ? '_on' : '_off'), 'topic', $id, $reason);

            $names = ['topic_pin' => 'закреплена', 'topic_close' => 'закрыта', 'topic_hide' => 'скрыта'];
            done('Тема ' . ($new ? $names[$action] : 'снова активна'), $back);
            break;
        }

        case 'topic_move': {
            $forumId = (int)($_POST['forum_id'] ?? 0);
            $stmt = $db->prepare("SELECT forum_id, replies_count FROM topics WHERE id = ?");
            $stmt->execute([$id]);
            $t = $stmt->fetch();
            if (!$t) done('Тема не найдена', $back, 'error');

            $stmt = $db->prepare("SELECT id FROM forums WHERE id = ? AND is_active = 1");
            $stmt->execute([$forumId]);
            if (!$stmt->fetch()) done('Раздел не найден', $back, 'error');
            if ((int)$t['forum_id'] === $forumId) done('Тема уже в этом разделе', $back, 'info');

            $weight = (int)$t['replies_count'] + 1;
            $db->beginTransaction();
            $db->prepare("UPDATE topics SET forum_id = ? WHERE id = ?")->execute([$forumId, $id]);
            $db->prepare("UPDATE forums SET topics_count = GREATEST(topics_count - 1, 0), posts_count = GREATEST(posts_count - ?, 0) WHERE id = ?")
               ->execute([$weight, $t['forum_id']]);
            $db->prepare("UPDATE forums SET topics_count = topics_count + 1, posts_count = posts_count + ? WHERE id = ?")
               ->execute([$weight, $forumId]);
            foreach ([(int)$t['forum_id'], $forumId] as $fid) {
                $st = $db->prepare("
                    SELECT p.id FROM posts p
                    JOIN topics t ON t.id = p.topic_id
                    WHERE t.forum_id = ?
                    ORDER BY p.created_at DESC, p.id DESC LIMIT 1
                ");
                $st->execute([$fid]);
                $lastId = $st->fetchColumn() ?: null;
                $db->prepare("UPDATE forums SET last_post_id = ? WHERE id = ?")->execute([$lastId, $fid]);
            }
            $db->commit();

            logModeration('topic_move', 'topic', $id, 'из #' . $t['forum_id'] . ' в #' . $forumId);
            done('Тема перенесена', $back);
            break;
        }

        case 'topic_delete': {
            $stmt = $db->prepare("SELECT id FROM topics WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) done('Тема не найдена', $back, 'error');

            $attachNames = attachCollectTopicTree($id);
            $db->beginTransaction();
            attachPurgeTopicTree($id);
            // истории правок и жалобы темы и её ответов (FK на target нет — чистим вручную)
            $db->prepare("
                DELETE FROM post_edits
                WHERE (target_type = 'topic' AND target_id = ?)
                   OR (target_type = 'post' AND target_id IN (SELECT id FROM posts WHERE topic_id = ?))
            ")->execute([$id, $id]);
            $db->prepare("
                DELETE FROM reports
                WHERE (target_type = 'topic' AND target_id = ?)
                   OR (target_type = 'post' AND target_id IN (SELECT id FROM posts WHERE topic_id = ?))
            ")->execute([$id, $id]);
            $db->prepare("DELETE FROM topics WHERE id = ?")->execute([$id]);
            $db->commit();
            foreach ($attachNames as $sn) attachUnlink($sn);
            logModeration('topic_delete', 'topic', $id, $reason);
            done('Тема удалена', '/index.php');
            break;
        }

        case 'post_hide': {
            $stmt = $db->prepare("SELECT id, is_hidden, user_id, topic_id FROM posts WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) done('Сообщение не найдено', $back, 'error');

            $new = $row['is_hidden'] ? 0 : 1;
            $db->prepare("UPDATE posts SET is_hidden = ? WHERE id = ?")->execute([$new, $id]);
            // Скрытый пост: убираем уведомления о нём (кроме модерационных)
            if ($new) {
                $db->prepare("DELETE FROM notifications WHERE post_id = ? AND type <> 'moderation'")->execute([$id]);
            }
            logModeration($new ? 'post_hide' : 'post_unhide', 'post', $id, $reason);

            if ($new) {
                notifyUser((int)$row['user_id'], 'moderation', $me['id'], (int)$row['topic_id'], $id,
                           $reason ?: 'Ваше сообщение скрыто модератором');
            }

            done($new ? 'Сообщение скрыто' : 'Сообщение восстановлено', $back);
            break;
        }

        case 'report_resolve':
        case 'report_reject': {
            $status = $action === 'report_resolve' ? 'resolved' : 'rejected';
            $stmt = $db->prepare("SELECT target_type, target_id FROM reports WHERE id = ?");
            $stmt->execute([$id]);
            $r = $stmt->fetch();

            $db->prepare("UPDATE reports SET status = ?, handled_by = ?, handled_at = NOW() WHERE id = ? AND status = 'new'")
               ->execute([$status, $me['id'], $id]);

            if ($r) logModeration($action, $r['target_type'], (int)$r['target_id'], $reason);
            done($status === 'resolved' ? 'Жалоба принята' : 'Жалоба отклонена', $back);
            break;
        }

        case 'user_ban': {
            requireAdmin();
            $stmt = $db->prepare("SELECT id, role, is_active FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) done('Пользователь не найден', $back, 'error');
            if ((int)$target['id'] === (int)$me['id']) done('Нельзя заблокировать себя', $back, 'error');
            if ($target['role'] === 'admin') done('Нельзя блокировать администратора', $back, 'error');

            $new = $target['is_active'] ? 0 : 1;
            $db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$new, $id]);
            logModeration($new ? 'user_unban' : 'user_ban', 'user', $id, $reason);
            done($new ? 'Пользователь разблокирован' : 'Пользователь заблокирован', $back);
            break;
        }

        case 'user_role': {
            requireAdmin();
            $role = $_POST['role'] ?? '';
            if (!in_array($role, ['user', 'moderator', 'admin'], true)) done('Некорректная роль', $back, 'error');
            if ($id === (int)$me['id']) done('Нельзя менять свою роль', $back, 'error');

            $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);
            logModeration('user_role', 'user', $id, $role);
            done('Роль обновлена', $back);
            break;
        }

        default:
            done('Неизвестное действие', $back, 'error');
    }
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Moderation error: ' . $e->getMessage());
    done('Ошибка при выполнении действия', $back, 'error');
}