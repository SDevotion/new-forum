<?php
require_once __DIR__ . '/../includes/permissions.php';
requireAdmin();
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/attachments.php';

$pageTitle = 'Обслуживание';
$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Недействительный запрос');
        redirect('/admin/maintenance.php');
    }
    $op = $_POST['op'] ?? '';

    switch ($op) {
        case 'recount':
            // Счётчики разделов
            $db->exec("
                UPDATE forums f
                SET topics_count = (SELECT COUNT(*) FROM topics t WHERE t.forum_id = f.id),
                    posts_count  = (SELECT COUNT(*) FROM topics t WHERE t.forum_id = f.id)
                                 + (SELECT COUNT(*) FROM posts p JOIN topics t ON t.id = p.topic_id WHERE t.forum_id = f.id)
            ");
            // last_post_id разделов
            foreach ($db->query("SELECT id FROM forums")->fetchAll(PDO::FETCH_COLUMN) as $fid) {
                $st = $db->prepare("
                    SELECT p.id FROM posts p JOIN topics t ON t.id = p.topic_id
                    WHERE t.forum_id = ? ORDER BY p.created_at DESC, p.id DESC LIMIT 1
                ");
                $st->execute([$fid]);
                $lastId = $st->fetchColumn() ?: null;
                $db->prepare("UPDATE forums SET last_post_id = ? WHERE id = ?")->execute([$lastId, $fid]);
            }
            // Ответы и last_post_at тем
            $db->exec("
                UPDATE topics t
                SET replies_count = (SELECT COUNT(*) FROM posts p WHERE p.topic_id = t.id),
                    last_post_at  = COALESCE((SELECT MAX(p.created_at) FROM posts p WHERE p.topic_id = t.id), t.created_at)
            ");
            // Счётчики участников
            $db->exec("
                UPDATE users u
                SET topics_total = (SELECT COUNT(*) FROM topics t WHERE t.user_id = u.id),
                    posts_total  = (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id)
            ");
            logModeration('maint_recount', 'forum', 0);
            setFlashMessage('success', 'Счётчики пересчитаны');
            break;

        case 'clean_orphan_records':
            $n1 = $db->exec("
                DELETE e FROM post_edits e
                LEFT JOIN topics t ON e.target_type = 'topic' AND t.id = e.target_id
                LEFT JOIN posts p  ON e.target_type = 'post'  AND p.id = e.target_id
                WHERE t.id IS NULL AND p.id IS NULL
            ");
            $n2 = $db->exec("
                DELETE r FROM reports r
                LEFT JOIN topics t ON r.target_type = 'topic' AND t.id = r.target_id
                LEFT JOIN posts p  ON r.target_type = 'post'  AND p.id = r.target_id
                WHERE t.id IS NULL AND p.id IS NULL
            ");
            logModeration('maint_clean', 'forum', 0, "Записи без целей: правок — $n1, жалоб — $n2");
            setFlashMessage('success', "Удалено записей без целей: правок — $n1, жалоб — $n2");
            break;

        case 'clean_ratelimits':
            $n = $db->exec("DELETE FROM rate_limits WHERE created_at < NOW() - INTERVAL 1 DAY");
            logModeration('maint_clean', 'forum', 0, "Лимитеры частоты: удалено записей — $n");
            setFlashMessage('success', "Удалено записей rate_limits: $n");
            break;

        case 'clean_searchlog':
            $n = $db->exec("DELETE FROM search_log WHERE created_at < NOW() - INTERVAL 90 DAY");
            logModeration('maint_clean', 'forum', 0, "Журнал поиска: удалено записей — $n");
            setFlashMessage('success', "Удалено записей search_log: $n");
            break;

        case 'clean_notifications':
            $n = $db->exec("DELETE FROM notifications WHERE is_read = 1 AND created_at < NOW() - INTERVAL 30 DAY");
            logModeration('maint_clean', 'forum', 0, "Прочитанные уведомления: удалено — $n");
            setFlashMessage('success', "Удалено прочитанных уведомлений: $n");
            break;

        case 'orphan_attachments':
            // Файлы на диске без записи в attachments
            $known = $db->query("SELECT stored_name FROM attachments")->fetchAll(PDO::FETCH_COLUMN);
            $known = array_flip($known);
            $removed = 0; $bytes = 0;
            foreach (@scandir(ATTACH_DIR) ?: [] as $fn) {
                if ($fn === '.' || $fn === '..' || isset($known[$fn])) continue;
                $fp = ATTACH_DIR . '/' . $fn;
                if (is_file($fp)) { $bytes += filesize($fp); if (@unlink($fp)) $removed++; }
            }
            logModeration('maint_clean', 'forum', 0, "Вложения без записей: удалено файлов — $removed");
            setFlashMessage('success', "Удалено осиротевших вложений: $removed (" . round($bytes / 1048576, 1) . " МБ)");
            break;

        case 'orphan_avatars':
            // Файлы аватаров, на которые никто не ссылается
            $dir = __DIR__ . '/../assets/uploads/avatars';
            $known = [];
            foreach ($db->query("SELECT avatar FROM users WHERE avatar IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $path) {
                $known[basename($path)] = true;
            }
            $removed = 0; $bytes = 0;
            foreach (@scandir($dir) ?: [] as $fn) {
                if ($fn === '.' || $fn === '..' || isset($known[$fn])) continue;
                $fp = $dir . '/' . $fn;
                if (is_file($fp)) { $bytes += filesize($fp); if (@unlink($fp)) $removed++; }
            }
            logModeration('maint_clean', 'forum', 0, "Аватары без ссылок: удалено файлов — $removed");
            setFlashMessage('success', "Удалено неиспользуемых аватаров: $removed (" . round($bytes / 1048576, 1) . " МБ)");
            break;

        default:
            setFlashMessage('error', 'Неизвестная операция');
    }
    redirect('/admin/maintenance.php');
}

// ===== Журнал модерации с фильтрами =====
$fAction = trim($_GET['action'] ?? '');
$fUser   = (int)($_GET['user'] ?? 0);
$fTarget = $_GET['target'] ?? '';
if (!in_array($fTarget, ['topic','post','user','forum','category'], true)) $fTarget = '';

$where = []; $params = [];
if ($fAction !== '') { $where[] = 'l.action = ?'; $params[] = $fAction; }
if ($fUser > 0)      { $where[] = 'l.user_id = ?'; $params[] = $fUser; }
if ($fTarget !== '') { $where[] = 'l.target_type = ?'; $params[] = $fTarget; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = max(1, setting('per_page_staff', 15));
$page = max(1, (int)($_GET['page'] ?? 1));

$st = $db->prepare("SELECT COUNT(*) FROM moderation_log l $whereSql");
$st->execute($params);
$total = (int)$st->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$st = $db->prepare("
    SELECT l.*, u.username
    FROM moderation_log l
    JOIN users u ON u.id = l.user_id
    $whereSql
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT $perPage OFFSET $offset
");
$st->execute($params);
$log = $st->fetchAll();

$actionNames = moderationActionNames();
$targetNames = ['topic' => 'Тема', 'post' => 'Сообщение', 'user' => 'Пользователь', 'forum' => 'Раздел', 'category' => 'Категория'];

// Список действий для фильтра — из реальных записей
$usedActions = $db->query("SELECT DISTINCT action FROM moderation_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$staffUsers = $db->query("SELECT id, username FROM users WHERE role IN ('moderator','admin') ORDER BY username")->fetchAll();

// Размеры таблиц обслуживания
$sizes = [
    'rate_limits'   => (int)$db->query("SELECT COUNT(*) FROM rate_limits")->fetchColumn(),
    'search_log'    => (int)$db->query("SELECT COUNT(*) FROM search_log")->fetchColumn(),
    'notifications' => (int)$db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 1")->fetchColumn(),
];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/pagination.php';
adminOpen('maintenance');
?>

<div class="page-header"><h1>Обслуживание</h1></div>

<div class="admin-cards">
    <div class="admin-card">
        <h3>Пересчёт счётчиков</h3>
        <p class="admin-muted">Темы/сообщения разделов, ответы и активность тем, счётчики участников, последние сообщения разделов. Безопасно, можно выполнять в любой момент.</p>
        <form method="POST" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="recount">
            <button class="btn btn-primary">Пересчитать</button>
        </form>
    </div>
    <div class="admin-card">
        <h3>Очистка данных</h3>
        <form method="POST" class="admin-clean-row js-confirm-maint">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="clean_orphan_records">
            <span>Правки и жалобы на удалённые темы/сообщения</span>
            <button class="btn btn-sm btn-outline">Очистить</button>
        </form>
        <form method="POST" class="admin-clean-row">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="clean_ratelimits">
            <span>rate_limits старше суток (<?= $sizes['rate_limits'] ?> записей всего)</span>
            <button class="btn btn-sm btn-outline">Очистить</button>
        </form>
        <form method="POST" class="admin-clean-row">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="clean_searchlog">
            <span>search_log старше 90 дней (<?= $sizes['search_log'] ?> записей всего)</span>
            <button class="btn btn-sm btn-outline">Очистить</button>
        </form>
        <form method="POST" class="admin-clean-row">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="clean_notifications">
            <span>Прочитанные уведомления старше 30 дней (<?= $sizes['notifications'] ?> прочитанных)</span>
            <button class="btn btn-sm btn-outline">Очистить</button>
        </form>
    </div>
    <div class="admin-card">
        <h3>Осиротевшие файлы</h3>
        <p class="admin-muted">Файлы на диске, на которые нет ссылок в БД (после сбоев загрузки или ручных удалений).</p>
        <form method="POST" class="inline-form js-confirm-maint">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="orphan_attachments">
            <button class="btn btn-sm btn-danger">Вложения без записей</button>
        </form>
        <form method="POST" class="inline-form js-confirm-maint">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="orphan_avatars">
            <button class="btn btn-sm btn-danger">Аватары без ссылок</button>
        </form>
    </div>
</div>

<div class="admin-card">
    <h3>Журнал модерации (<?= $total ?>)</h3>
    <form method="GET" class="admin-log-filters">
        <select name="action" class="form-control form-control-sm">
            <option value="">Все действия</option>
            <?php foreach ($usedActions as $a): ?>
                <option value="<?= escape($a) ?>" <?= $a === $fAction ? 'selected' : '' ?>><?= escape($actionNames[$a] ?? $a) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="user" class="form-control form-control-sm">
            <option value="0">Все авторы</option>
            <?php foreach ($staffUsers as $su): ?>
                <option value="<?= (int)$su['id'] ?>" <?= (int)$su['id'] === $fUser ? 'selected' : '' ?>><?= escape($su['username']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="target" class="form-control form-control-sm">
            <option value="">Все объекты</option>
            <?php foreach ($targetNames as $k => $label): ?>
                <option value="<?= $k ?>" <?= $k === $fTarget ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-primary">Фильтр</button>
        <a href="/admin/maintenance.php" class="btn btn-sm btn-outline">Сброс</a>
    </form>

    <?php if (!$log): ?>
        <p class="admin-muted">Записей нет.</p>
    <?php else: ?>
        <ul class="admin-log">
            <?php foreach ($log as $l): ?>
                <li>
                    <span class="admin-log-date"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></span>
                    <strong><?= escape($l['username']) ?></strong>
                    — <?= escape($actionNames[$l['action']] ?? $l['action']) ?>
                    · <?= escape($targetNames[$l['target_type']] ?? $l['target_type']) ?> #<?= (int)$l['target_id'] ?>
                    <?php $r = moderationReasonLabel($l['reason']); ?>
                    <?= $r !== '' ? '· ' . escape($r) : '' ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        $qs = http_build_query(array_filter(['action' => $fAction, 'user' => $fUser ?: null, 'target' => $fTarget]));
        renderPagination($page, $totalPages, '/admin/maintenance.php' . ($qs ? '?' . $qs : ''));
        ?>
    <?php endif; ?>
</div>

<script>
document.querySelectorAll('.js-confirm-maint').forEach(f => f.addEventListener('submit', e => {
    if (!confirm('Удалить файлы безвозвратно?')) e.preventDefault();
}));
</script>

<?php
adminClose();
require_once __DIR__ . '/../includes/footer.php';