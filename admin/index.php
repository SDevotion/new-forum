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

// Размер загрузок: кэш APCu 5 мин (рекурсивный скан дорогой на больших каталогах).
// Без APCu — скан, но не чаще раза в 5 минут через файл-метку.
$uploadDir = __DIR__ . '/../assets/uploads';
$uploadSize = 0; $uploadFiles = 0;
$cacheKey = 'forum_upload_stats';
$gotCache = false;
if (function_exists('apcu_fetch') && (!function_exists('apcu_enabled') || apcu_enabled())) {
    $hit = apcu_fetch($cacheKey, $ok);
    if ($ok && is_array($hit)) { [$uploadFiles, $uploadSize] = $hit; $gotCache = true; }
}
if (!$gotCache) {
    $marker = $uploadDir . '/.stats_cache';
    $fresh = is_file($marker) && (time() - filemtime($marker)) < 300;
    if ($fresh) {
        $data = @json_decode((string)@file_get_contents($marker), true);
        if (is_array($data)) { $uploadFiles = (int)$data[0]; $uploadSize = (int)$data[1]; $gotCache = true; }
    }
    if (!$gotCache && is_dir($uploadDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $fp) {
            if ($fp->isFile() && !in_array($fp->getFilename(), ['.htaccess', '.stats_cache'], true)) {
                $uploadSize += $fp->getSize();
                $uploadFiles++;
            }
        }
        @file_put_contents($marker, json_encode([$uploadFiles, $uploadSize]));
        if (function_exists('apcu_store') && (!function_exists('apcu_enabled') || apcu_enabled())) {
            apcu_store($cacheKey, [$uploadFiles, $uploadSize], 300);
        }
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
                        — <?= escape(moderationActionNames()[$l['action']] ?? $l['action']) ?>
                        <?php $r = moderationReasonLabel($l['reason']); ?>
                        <?= $r !== '' ? '· ' . escape(mb_strimwidth($r, 0, 60, '…')) : '' ?>
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
