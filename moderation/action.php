<?php
// moderation/action.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [приоритет#9] «Последний админ»: понижение роли admin проверяется под GET_LOCK
//       с пересчётом активных админов ВНУТРИ лока — два админа не могут разжаловать
//       друг друга параллельно и оставить форум без админов.
// [1.5] topic_delete: тема блокируется FOR UPDATE ПЕРВОЙ операцией транзакции;
//       сбор имён вложений (attachCollectTopicTree ... FOR UPDATE) и авторы ответов
//       читаются ПОД блокировкой — конкурентный reply не добавит файл между сбором
//       и DELETE (раньше сбор был до транзакции).
// [3.2] post_hide: уведомление автору только при РЕАЛЬНОМ изменении флага
//       (условный UPDATE + rowCount) — повторные клики/гонки не плодят дубли;
//       сами moderation-строки теперь дедуплицируются uniq_event (миграция: NOT NULL 0).
// [6.массовый 500] Весь ввод через postStr()/postInt(); back — через safeBackUrl().
// [new] user_role/user_ban: цель читается FOR UPDATE — роль/статус не меняются
//       параллельно между проверкой и UPDATE.

require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/counters.php';
requireModerator();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/moderation/index.php');
if (!verifyCSRFToken(postStr('csrf_token'))) { setFlashMessage('error', 'Недействительный запрос'); redirect('/moderation/index.php'); }

$db = Database::getInstance()->getConnection();
$me = getCurrentUser();
$action = postStr('action');
$reason = trim(postStr('reason'));
$id = postInt('id');
$back = safeBackUrl(postStr('back'), '/moderation/index.php');

function done($msg, $back, $type = 'success') { setFlashMessage($type, $msg); redirect($back); }

try {
    switch ($action) {

        case 'topic_pin':
        case 'topic_close':
        case 'topic_hide': {
            $col = ['topic_pin' => 'is_pinned', 'topic_close' => 'is_closed', 'topic_hide' => 'is_hidden'][$action];
            $stmt = $db->prepare("SELECT id, forum_id, `$col` AS val FROM topics WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) done('Тема не найдена', $back, 'error');

            $new = $row['val'] ? 0 : 1;
            // Условный UPDATE: повторный клик тем же состоянием — no-op без журнала.
            $st = $db->prepare("UPDATE topics SET `$col` = ? WHERE id = ? AND `$col` = ?");
            $st->execute([$new, $id, $row['val']]);
            if ($st->rowCount() === 0) done('Состояние уже изменено другим модератором', $back, 'info');

            if ($action === 'topic_hide' && $new) {
                $db->prepare("DELETE FROM notifications WHERE topic_id = ? AND type <> 'moderation'")->execute([$id]);
            }
            if ($action === 'topic_hide') {
                recountForum((int)$row['forum_id']);
            }
            logModeration($action . ($new ? '_on' : '_off'), 'topic', $id, $reason);

            $names = ['topic_pin' => 'закреплена', 'topic_close' => 'закрыта', 'topic_hide' => 'скрыта'];
            done('Тема ' . ($new ? $names[$action] : 'снова активна'), $back);
            break;
        }

        case 'topic_move': {
            $forumId = postInt('forum_id');
            $stmt = $db->prepare("SELECT forum_id FROM topics WHERE id = ?");
            $stmt->execute([$id]);
            $t = $stmt->fetch();
            if (!$t) done('Тема не найдена', $back, 'error');

            $stmt = $db->prepare("SELECT id FROM forums WHERE id = ? AND is_active = 1");
            $stmt->execute([$forumId]);
            if (!$stmt->fetch()) done('Раздел не найден', $back, 'error');
            if ((int)$t['forum_id'] === $forumId) done('Тема уже в этом разделе', $back, 'info');

            $db->beginTransaction();
            $db->prepare("UPDATE topics SET forum_id = ? WHERE id = ?")->execute([$forumId, $id]);
            $db->commit();

            recountForum((int)$t['forum_id']);
            recountForum($forumId);

            logModeration('topic_move', 'topic', $id, 'из #' . $t['forum_id'] . ' в #' . $forumId);
            done('Тема перенесена', $back);
            break;
        }

        case 'topic_delete': {
            $db->beginTransaction();

            // Блокируем тему ПЕРВОЙ операцией: конкурентный reply (тоже берёт
            // FOR UPDATE на тему) либо завершится до нас, либо увидит её удалённой.
            $stmt = $db->prepare("SELECT id, forum_id, user_id FROM topics WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $topicRow = $stmt->fetch();
            if (!$topicRow) {
                $db->rollBack();
                done('Тема не найдена', $back, 'error');
            }

            // Авторы и файлы — под блокировкой, каскад ещё не стёр посты.
            $st = $db->prepare("SELECT DISTINCT user_id FROM posts WHERE topic_id = ?");
            $st->execute([$id]);
            $affectedUsers = $st->fetchAll(PDO::FETCH_COLUMN);
            $affectedUsers[] = (int)$topicRow['user_id'];

            $attachNames = attachCollectTopicTree($id, true); // FOR UPDATE

            attachPurgeTopicTree($id);
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

            recountForum((int)$topicRow['forum_id']);
            recountUsers($affectedUsers);

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
            // Условный UPDATE: уведомление и журнал — только при реальной смене.
            $st = $db->prepare("UPDATE posts SET is_hidden = ? WHERE id = ? AND is_hidden = ?");
            $st->execute([$new, $id, $row['is_hidden']]);
            if ($st->rowCount() === 0) done('Состояние уже изменено другим модератором', $back, 'info');

            if ($new) {
                $db->prepare("DELETE FROM notifications WHERE post_id = ? AND type <> 'moderation'")->execute([$id]);
            }

            $st = $db->prepare("SELECT forum_id FROM topics WHERE id = ?");
            $st->execute([(int)$row['topic_id']]);
            $forumId = (int)$st->fetchColumn();
            recountTopic((int)$row['topic_id']);
            if ($forumId > 0) recountForum($forumId);

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
            $stmt = $db->prepare("SELECT target_type, target_id, user_id FROM reports WHERE id = ?");
            $stmt->execute([$id]);
            $r = $stmt->fetch();

            $stmt = $db->prepare("UPDATE reports SET status = ?, handled_by = ?, handled_at = NOW() WHERE id = ? AND status = 'new'");
            $stmt->execute([$status, $me['id'], $id]);

            if ($stmt->rowCount() === 0) {
                done($r ? 'Жалоба уже обработана другим модератором' : 'Жалоба не найдена', $back, 'info');
            }
            logModeration($action, $r['target_type'], (int)$r['target_id'], $reason);

            $reporterId = (int)$r['user_id'];
            if ($reporterId > 0) {
                $resultText = $status === 'resolved'
                    ? 'Ваша жалоба рассмотрена и принята. Спасибо за помощь в модерации.'
                    : 'Ваша жалоба рассмотрена и отклонена: нарушений не обнаружено.';
                // Дедуп обеспечен и условным UPDATE выше, и uniq_event (NOT NULL 0).
                notifyUser($reporterId, 'moderation', $me['id'], null, null, $resultText);
            }

            done($status === 'resolved' ? 'Жалоба принята' : 'Жалоба отклонена', $back);
            break;
        }

        case 'user_ban': {
            requireAdmin();
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT id, role, is_active FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) { $db->rollBack(); done('Пользователь не найден', $back, 'error'); }
            if ((int)$target['id'] === (int)$me['id']) { $db->rollBack(); done('Нельзя заблокировать себя', $back, 'error'); }
            if ($target['role'] === 'admin') { $db->rollBack(); done('Нельзя блокировать администратора', $back, 'error'); }

            $new = $target['is_active'] ? 0 : 1;
            $db->prepare("UPDATE users SET is_active = ? WHERE id = ?")->execute([$new, $id]);
            if (!$new) {
                $db->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$id]);
            }
            $db->commit();

            logModeration($new ? 'user_unban' : 'user_ban', 'user', $id, $reason);
            done($new ? 'Пользователь разблокирован' : 'Пользователь заблокирован', $back);
            break;
        }

        case 'user_role': {
            requireAdmin();
            $role = postStr('role');
            if (!in_array($role, ['user', 'moderator', 'admin'], true)) done('Некорректная роль', $back, 'error');
            if ($id === (int)$me['id']) done('Нельзя менять свою роль', $back, 'error');

            // Сериализация смены ролей: два админа не разжалуют друг друга параллельно.
            $got = $db->query("SELECT GET_LOCK('forum_role_change', 5)");
            if ((int)$got->fetchColumn() !== 1) done('Подождите завершения другой операции с ролями', $back, 'error');

            try {
                $db->beginTransaction();
                $stmt = $db->prepare("SELECT id, role, is_active FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$id]);
                $target = $stmt->fetch();
                if (!$target) { $db->rollBack(); done('Пользователь не найден', $back, 'error'); }

                if ($target['role'] === 'admin' && $role !== 'admin') {
                    // Последний активный админ неприкасаем (считаем под локом).
                    $cnt = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
                    if ($cnt <= 1) {
                        $db->rollBack();
                        done('Нельзя понизить последнего администратора', $back, 'error');
                    }
                }
                if ($target['role'] === $role) { $db->rollBack(); done('Роль уже установлена', $back, 'info'); }

                $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);
                $db->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$id]);
                $db->commit();
            } finally {
                $db->query("SELECT RELEASE_LOCK('forum_role_change')");
            }

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