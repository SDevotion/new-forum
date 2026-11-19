<?php
require_once __DIR__ . '/../includes/permissions.php';
requireAdmin();
require_once __DIR__ . '/_layout.php';

$pageTitle = 'Панель администратора';
$db = Database::getInstance()->getConnection();

$counts = [
    'users'   => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'topics'  => (int)$db->query("SELECT COUNT(*) FROM topics")->fetchColumn(),
    'posts'   => (int)$db->query("SELECT COUNT(*) FROM posts")->fetchColumn(),
    'reports' => newReportsCount(),
];
$newUsers7 = (int)$db->query("SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
$banned = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active = 0")->fetchColumn();

// Размер каталога вложений
$uploadDir = __DIR__ . '/../assets/uploads';
$uploadSize = 0; $uploadFiles = 0;
if (is_dir($uploadDir)) {
    foreach (@scandir($uploadDir) ?: [] as $fn) {
        if ($fn === '.' || $fn === '..') continue;
        $fp = $uploadDir . '/' . $fn;
        if (is_file($fp)) { $uploadSize += filesize($fp); $uploadFiles++; }
    }
}

$log = $db->query("
    SELECT ml.*, u.username
    FROM moderation_log ml
    JOIN users u ON u.id = ml.user_id
    ORDER BY ml.created_at DESC, ml.id DESC
    LIMIT 10
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
adminOpen('dashboard');
?>

<div class="page-header"><h1>Панель администратора</h1></div>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-number"><?= $counts['users'] ?></div><div class="stat-label">Участников</div></div>
    <div class="stat-card"><div class="stat-number"><?= $counts['topics'] ?></div><div class="stat-label">Тем</div></div>
    <div class="stat-card"><div class="stat-number"><?= $counts['posts'] ?></div><div class="stat-label">Ответов</div></div>
    <div class="stat-card"><div class="stat-number"><?= $counts['reports'] ?></div><div class="stat-label">Новых жалоб</div></div>
</div>

<div class="admin-cards">
    <div class="admin-card">
        <h3>Состояние</h3>
        <ul class="admin-facts">
            <li>Регистрация: <strong><?= setting('registration_enabled', true) ? 'открыта' : 'закрыта' ?></strong></li>
            <li>Форум: <strong><?= setting('forum_closed', false) ? 'закрыт (только чтение)' : 'открыт' ?></strong></li>
            <li>Новых участников за 7 дней: <strong><?= $newUsers7 ?></strong></li>
            <li>Заблокировано: <strong><?= $banned ?></strong></li>
            <li>Вложения: <strong><?= $uploadFiles ?></strong> файлов, <?= round($uploadSize / 1048576, 1) ?> МБ</li>
        </ul>
    </div>
    <div class="admin-card">
        <h3>Последние действия модерации</h3>
        <?php if (!$log): ?>
            <p class="admin-muted">Журнал пуст.</p>
        <?php else: ?>
            <ul class="admin-log">
                <?php foreach ($log as $l): ?>
                    <li>
                        <span class="admin-log-date"><?= date('d.m H:i', strtotime($l['created_at'])) ?></span>
                        <strong><?= escape($l['username']) ?></strong>
                        — <?= escape($l['action']) ?>
                        <?= $l['reason'] ? '· ' . escape(mb_strimwidth($l['reason'], 0, 60, '…')) : '' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a href="/moderation/index.php" class="btn-link">Весь журнал →</a>
        <?php endif; ?>
    </div>
</div>

<?php
adminClose();
require_once __DIR__ . '/../includes/footer.php';