<?php
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/avatar.php';
require_once __DIR__ . '/includes/pagination.php';

if (!isLoggedIn()) { setFlashMessage('error', 'Необходимо войти'); redirect('/auth/login.php'); }

$pageTitle = 'Уведомления';
$db = Database::getInstance()->getConnection();
$uid = currentUserId();

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'unread'], true)) $filter = 'all';

$perPage = max(1, setting('per_page_notifications', 15));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$cond = ($filter === 'unread' ? ' AND n.is_read = 0' : '') . notifVisibilityCond();

$stmt = $db->prepare("SELECT COUNT(*) FROM notifications n WHERE n.user_id = ? $cond");
$stmt->execute([$uid]);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$stmt = $db->prepare("
    SELECT n.*, a.username AS actor_name, a.avatar AS actor_avatar, t.title AS topic_title
    FROM notifications n
    LEFT JOIN users a ON a.id = n.actor_id
    LEFT JOIN topics t ON t.id = n.topic_id
    WHERE n.user_id = ? $cond
    ORDER BY n.is_read ASC, n.created_at DESC, n.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute([$uid]);
$items = $stmt->fetchAll();

$labels = [
    'reply'      => ['💬', 'ответил в теме'],
    'mention'    => ['📣', 'упомянул вас'],
    'quote'      => ['❝', 'процитировал вас'],
    'moderation' => ['🛡️', 'действие модерации'],
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="page-header-row">
        <div>
            <h1>Уведомления</h1>
            <p class="page-subtitle">Непрочитанных: <?= unreadCount() ?></p>
        </div>
        <div class="header-btn-group">
            <form method="POST" action="/notifications/action.php" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                <input type="hidden" name="action" value="read_all">
                <button class="btn btn-outline">Прочитать все</button>
            </form>
            <form method="POST" action="/notifications/action.php" class="inline-form js-confirm-clear">
                <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                <input type="hidden" name="action" value="delete_all">
                <button class="btn btn-outline">Очистить прочитанные</button>
            </form>
        </div>
    </div>
</div>

<div class="tabs">
    <a href="/notifications.php?filter=all" class="tab <?= $filter === 'all' ? 'active' : '' ?>">Все</a>
    <a href="/notifications.php?filter=unread" class="tab <?= $filter === 'unread' ? 'active' : '' ?>">Непрочитанные</a>
</div>

<?php if (empty($items)): ?>
    <div class="empty-state empty-state-sm">
        <div class="empty-icon">🔔</div>
        <h3>Уведомлений нет</h3>
        <p>Здесь появятся ответы в ваших темах и упоминания.</p>
    </div>
<?php else: ?>
    <div class="notif-list">
        <?php foreach ($items as $n): ?>
            <?php [$icon, $label] = $labels[$n['type']] ?? ['🔔', 'уведомление']; ?>
            <div class="notif-row <?= $n['is_read'] ? '' : 'notif-unread' ?>">
                <form method="POST" action="/notifications/action.php" class="notif-main-form">
                    <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                    <input type="hidden" name="action" value="read">
                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                    <button type="submit" class="notif-main">
                        <span class="notif-icon"><?= $icon ?></span>
                        <img src="<?= avatarUrl($n['actor_avatar']) ?>" alt="" class="notif-avatar">
                        <span class="notif-body">
                            <span class="notif-text">
                                <strong><?= escape($n['actor_name'] ?? 'Система') ?></strong> <?= $label ?>
                                <?php if ($n['topic_title']): ?>
                                    «<?= escape(mb_strimwidth($n['topic_title'], 0, 60, '…')) ?>»
                                <?php endif; ?>
                            </span>
                            <?php if ($n['excerpt']): ?>
                                <span class="notif-excerpt"><?= escape($n['excerpt']) ?></span>
                            <?php endif; ?>
                            <span class="notif-date"><?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></span>
                        </span>
                    </button>
                </form>
                <form method="POST" action="/notifications/action.php" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                    <input type="hidden" name="back" value="/notifications.php?filter=<?= $filter ?>&page=<?= $page ?>">
                    <button class="notif-delete" title="Удалить">&times;</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?php renderPagination($page, $totalPages, '/notifications.php?filter=' . $filter); ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>