<?php
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/markdown.php';
requireModerator();

$pageTitle = 'Панель модерации';
$db = Database::getInstance()->getConnection();
$me = getCurrentUser();

$stats = [
    'users'   => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'topics'  => (int)$db->query("SELECT COUNT(*) FROM topics")->fetchColumn(),
    'posts'   => (int)$db->query("SELECT COUNT(*) FROM posts")->fetchColumn(),
    'reports' => (int)$db->query("SELECT COUNT(*) FROM reports WHERE status='new'")->fetchColumn(),
];

$reports = $db->query("
    SELECT r.*, u.username AS author, h.username AS handler
    FROM reports r
    JOIN users u ON u.id = r.user_id
    LEFT JOIN users h ON h.id = r.handled_by
    ORDER BY FIELD(r.status,'new','resolved','rejected'), r.created_at DESC
    LIMIT " . max(1, setting('per_page_staff', 15)) . "
")->fetchAll();

// Превью целей — двумя пакетными запросами вместо запроса на каждую жалобу
$topicIds = []; $postIds = [];
foreach ($reports as $r) {
    if ($r['target_type'] === 'topic') $topicIds[] = (int)$r['target_id'];
    else $postIds[] = (int)$r['target_id'];
}
$topicMap = []; $postMap = [];
if ($topicIds) {
    $in = implode(',', array_fill(0, count($topicIds), '?'));
    $st = $db->prepare("SELECT id, title AS preview, id AS link_id FROM topics WHERE id IN ($in)");
    $st->execute($topicIds);
    foreach ($st->fetchAll() as $row) $topicMap[(int)$row['id']] = $row;
}
if ($postIds) {
    $in = implode(',', array_fill(0, count($postIds), '?'));
    $st = $db->prepare("SELECT id, content AS preview, topic_id AS link_id FROM posts WHERE id IN ($in)");
    $st->execute($postIds);
    foreach ($st->fetchAll() as $row) $postMap[(int)$row['id']] = $row;
}
foreach ($reports as &$r) {
    $tid = (int)$r['target_id'];
    $r['target'] = $r['target_type'] === 'topic' ? ($topicMap[$tid] ?? null) : ($postMap[$tid] ?? null);
}
unset($r);

$log = $db->query("
    SELECT l.*, u.username
    FROM moderation_log l
    JOIN users u ON u.id = l.user_id
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT " . max(1, setting('per_page_staff', 15)) . "
")->fetchAll();

$actionNames = [
    'topic_pin_on' => 'Закрепление темы', 'topic_pin_off' => 'Открепление темы',
    'topic_close_on' => 'Закрытие темы', 'topic_close_off' => 'Открытие темы',
    'topic_hide_on' => 'Скрытие темы', 'topic_hide_off' => 'Показ темы',
    'topic_move' => 'Перенос темы', 'topic_delete' => 'Удаление темы',
    'post_hide' => 'Скрытие сообщения', 'post_unhide' => 'Показ сообщения',
    'report_resolve' => 'Жалоба принята', 'report_reject' => 'Жалоба отклонена',
    'user_ban' => 'Блокировка пользователя', 'user_unban' => 'Разблокировка',
    'user_role' => 'Смена роли',
    'restore_revision' => 'Откат версии',
    'category_add' => 'Создание категории', 'category_toggle' => 'Видимость категории',
    'category_delete' => 'Удаление категории', 'forum_add' => 'Создание раздела',
    'forum_toggle' => 'Видимость раздела', 'forum_delete' => 'Удаление раздела',
    'settings_update' => 'Изменение настроек',
    'maint_recount' => 'Пересчёт счётчиков', 'maint_clean' => 'Очистка данных',
];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-row">
        <div>
            <h1>Панель модерации</h1>
            <p class="page-subtitle"><?= escape($me['username']) ?> · <?= $me['role'] === 'admin' ? 'Администратор' : 'Модератор' ?></p>
        </div>
        <?php if (isAdmin()): ?>
            <div class="header-btn-group">
                <a href="/admin/users.php" class="btn btn-outline">Пользователи</a>
                <a href="/admin/structure.php" class="btn btn-primary">Структура форума</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="admin-stats">
    <div class="admin-stat"><span class="admin-stat-value"><?= $stats['users'] ?></span><span class="admin-stat-label">Пользователей</span></div>
    <div class="admin-stat"><span class="admin-stat-value"><?= $stats['topics'] ?></span><span class="admin-stat-label">Тем</span></div>
    <div class="admin-stat"><span class="admin-stat-value"><?= $stats['posts'] ?></span><span class="admin-stat-label">Сообщений</span></div>
    <div class="admin-stat <?= $stats['reports'] ? 'admin-stat-alert' : '' ?>"><span class="admin-stat-value"><?= $stats['reports'] ?></span><span class="admin-stat-label">Новых жалоб</span></div>
</div>

<h2 class="section-title">Жалобы</h2>

<?php if (empty($reports)): ?>
    <div class="empty-state empty-state-sm"><div class="empty-icon">✅</div><h3>Жалоб нет</h3></div>
<?php else: ?>
    <div class="admin-list">
        <?php foreach ($reports as $r): ?>
            <div class="admin-row report-<?= escape($r['status']) ?>">
                <div class="admin-row-main">
                    <div class="report-head">
                        <span class="badge badge-status-<?= escape($r['status']) ?>">
                            <?= ['new' => 'Новая', 'resolved' => 'Принята', 'rejected' => 'Отклонена'][$r['status']] ?>
                        </span>
                        <span class="report-type"><?= $r['target_type'] === 'topic' ? 'Тема' : 'Сообщение' ?> #<?= (int)$r['target_id'] ?></span>
                        <span class="report-date"><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></span>
                    </div>
                    <div class="report-reason"><?= nl2br(escape($r['reason'])) ?></div>
                    <div class="activity-meta">
                        От: <strong><?= escape($r['author']) ?></strong>
                        <?php if ($r['handler']): ?> · Обработал: <?= escape($r['handler']) ?><?php endif; ?>
                    </div>
                    <?php if ($r['target']): ?>
                        <a class="report-preview" target="_blank"
                           href="/topic.php?id=<?= (int)$r['target']['link_id'] ?><?= $r['target_type'] === 'post' ? '#post-' . (int)$r['target_id'] : '' ?>">
                            <?= escape($r['target_type'] === 'post' ? mdExcerpt($r['target']['preview'], 120) : $r['target']['preview']) ?> ↗
                        </a>
                    <?php else: ?>
                        <span class="no-posts">Объект удалён</span>
                    <?php endif; ?>
                </div>

                <?php if ($r['status'] === 'new'): ?>
                    <div class="admin-row-actions">
                        <form method="POST" action="/moderation/action.php" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                            <input type="hidden" name="action" value="report_resolve">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="back" value="/moderation/index.php">
                            <button class="btn btn-primary btn-sm">Принять</button>
                        </form>
                        <form method="POST" action="/moderation/action.php" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                            <input type="hidden" name="action" value="report_reject">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="back" value="/moderation/index.php">
                            <button class="btn btn-outline btn-sm">Отклонить</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="section-title">Журнал действий</h2>

<?php if (empty($log)): ?>
    <div class="empty-state empty-state-sm"><div class="empty-icon">📋</div><h3>Журнал пуст</h3></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Дата</th><th>Кто</th><th>Действие</th><th>Объект</th><th>Комментарий</th></tr></thead>
            <tbody>
            <?php foreach ($log as $l): ?>
                <tr>
                    <td class="nowrap"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
                    <td><a href="/profile.php?id=<?= (int)$l['user_id'] ?>"><?= escape($l['username']) ?></a></td>
                    <td><?= escape($actionNames[$l['action']] ?? $l['action']) ?></td>
                    <td><?= escape($l['target_type']) ?> #<?= (int)$l['target_id'] ?></td>
                    <td class="muted"><?= escape($l['reason'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>