<?php
require_once __DIR__ . '/includes/editing.php';
require_once __DIR__ . '/includes/markdown.php';
require_once __DIR__ . '/includes/pagination.php';
require_once __DIR__ . '/includes/visibility.php';

$db = Database::getInstance()->getConnection();
$type = ($_GET['type'] ?? '') === 'post' ? 'post' : 'topic';
$id = (int)($_GET['id'] ?? 0);

$isStaff = isModerator();

if ($type === 'topic') {
    $t = loadVisibleTopic($id, false);
    $obj = $t ? ['id' => $t['id'], 'title' => $t['title'], 'content' => $t['content'], 'edit_count' => $t['edit_count'], 'back_id' => $t['id']] : null;
} else {
    [$cond, $params] = visPostSql();
    $stmt = $db->prepare("
        SELECT p.id, NULL AS title, p.content, p.edit_count, p.topic_id AS back_id
        FROM posts p " . visPostJoins() . "
        WHERE p.id = ? AND $cond
    ");
    $stmt->execute(array_merge([$id], $params));
    $obj = $stmt->fetch() ?: null;
}

if (!$obj) {
    http_response_code(404);
    $pageTitle = 'Не найдено';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="empty-state"><div class="empty-icon">🔍</div><h3>Объект не найден</h3><a href="/index.php" class="btn btn-primary">К форуму</a></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$backUrl = '/topic.php?id=' . (int)$obj['back_id'] . ($type === 'post' ? '#post-' . $id : '');
$pageTitle = 'История правок';
$staff = isModerator();

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));

$revVis = $isStaff ? '' : ' AND is_hidden = 0';
$st = $db->prepare("SELECT COUNT(*) FROM post_edits WHERE target_type = ? AND target_id = ?$revVis");
$st->execute([$type, $id]);
$totalRevisions = (int)$st->fetchColumn();
$totalPages = max(1, (int)ceil($totalRevisions / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$st = $db->prepare("
    SELECT e.*, u.username, u.avatar
    FROM post_edits e
    JOIN users u ON u.id = e.editor_id
    WHERE e.target_type = ? AND e.target_id = ?$revVis
    ORDER BY e.created_at DESC, e.id DESC
    LIMIT $perPage OFFSET $offset
");
$st->execute([$type, $id]);
$revisions = $st->fetchAll();

mdPrimeMentions(array_merge([$obj['content']], array_column($revisions, 'old_content')));

require_once __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumbs">
    <a href="/index.php">Главная</a><span class="sep">›</span>
    <a href="<?= escape($backUrl) ?>">Тема</a><span class="sep">›</span>
    <span class="current">История правок</span>
</nav>

<div class="page-header">
    <h1>История правок</h1>
    <p class="page-subtitle"><?= $type === 'topic' ? 'Тема' : 'Сообщение' ?> #<?= $id ?> · всего правок: <?= $totalRevisions ?></p>
</div>

<div class="revision-current">
    <div class="revision-head">
        <span class="badge badge-active">Текущая версия</span>
        <?php if ($obj['title']): ?><strong><?= escape($obj['title']) ?></strong><?php endif; ?>
    </div>
    <div class="post-content"><?= mdRender($obj['content']) ?></div>
</div>

<?php if (empty($revisions)): ?>
    <div class="empty-state empty-state-sm">
        <div class="empty-icon">📝</div>
        <h3>Правок не было</h3>
        <p>Это исходная версия сообщения.</p>
    </div>
<?php else: ?>
    <?php foreach ($revisions as $i => $rev): ?>
        <?php $versionNum = $totalRevisions - $offset - $i; ?>
        <details class="revision-item">
            <summary class="revision-summary">
                <span class="revision-num">Версия <?= $versionNum ?></span>
                <span class="revision-meta"><?= escape($rev['username']) ?> · <?= date('d.m.Y H:i', strtotime($rev['created_at'])) ?></span>
                <?php if ($isStaff && $rev['is_hidden']): ?><span class="badge badge-banned">скрыта от участников</span><?php endif; ?>
                <?php if ($rev['reason']): ?><span class="revision-reason"><?= escape($rev['reason']) ?></span><?php endif; ?>
            </summary>
            <div class="revision-body">
                <?php if ($rev['old_title']): ?><div class="revision-old-title"><?= escape($rev['old_title']) ?></div><?php endif; ?>
                <div class="post-content"><?= mdRender($rev['old_content']) ?></div>

                <?php if ($staff): ?>
                    <form method="POST" action="/edit/restore.php" class="inline-form js-confirm-restore">
                        <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                        <input type="hidden" name="revision_id" value="<?= (int)$rev['id'] ?>">
                        <button class="btn btn-sm btn-outline">Восстановить эту версию</button>
                    </form>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>
    <?php renderPagination($page, $totalPages, '/history.php?type=' . $type . '&id=' . $id); ?>
<?php endif; ?>

<div class="form-actions" style="margin-top:24px">
    <a href="<?= escape($backUrl) ?>" class="btn btn-outline">Вернуться к теме</a>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
