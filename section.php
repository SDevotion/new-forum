<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';

$db = Database::getInstance()->getConnection();
$forumId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT f.*, c.title AS category_title 
    FROM forums f
    JOIN categories c ON c.id = f.category_id
    WHERE f.id = ? AND f.is_active = 1 AND c.is_active = 1
");
$stmt->execute([$forumId]);
$forum = $stmt->fetch();

if (!$forum) {
    http_response_code(404);
    $pageTitle = 'Раздел не найден';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="empty-state"><div class="empty-icon">🔍</div><h3>Раздел не найден</h3><a href="/index.php" class="btn btn-primary">Вернуться к форуму</a></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = $forum['title'];

// Пагинация
require_once __DIR__ . '/includes/pagination.php';
$perPage = max(1, setting('per_page_topics', 15));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$staff = isModerator();

$stmt = $db->prepare("SELECT COUNT(*) FROM topics WHERE forum_id = ? AND (is_hidden = 0 OR ? = 1)");
$stmt->execute([$forumId, $staff ? 1 : 0]);
$totalTopics = (int)$stmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalTopics / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $db->prepare("
    SELECT t.*, u.username, u.avatar, u.id AS author_id,
           COALESCE(
               (SELECT lu.username
                FROM posts lp
                JOIN users lu ON lu.id = lp.user_id
                WHERE lp.topic_id = t.id AND (lp.is_hidden = 0 OR ? = 1)
                ORDER BY lp.created_at DESC, lp.id DESC
                LIMIT 1),
               u.username
           ) AS last_username,
           (SELECT COUNT(*) FROM posts vp WHERE vp.topic_id = t.id AND (vp.is_hidden = 0 OR ? = 1)) AS visible_replies
    FROM topics t
    JOIN users u ON u.id = t.user_id
    WHERE t.forum_id = ? AND (t.is_hidden = 0 OR ? = 1)
    ORDER BY t.is_pinned DESC, t.last_post_at DESC, t.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute([$staff ? 1 : 0, $staff ? 1 : 0, $forumId, $staff ? 1 : 0]);
$topics = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumbs">
    <a href="/index.php">Главная</a>
    <span class="sep">›</span>
    <span class="current"><?= escape($forum['title']) ?></span>
</nav>

<div class="page-header">
    <div class="page-header-row">
        <div>
            <h1><?= escape($forum['icon'] . ' ' . $forum['title']) ?></h1>
            <?php if ($forum['description']): ?>
                <p class="page-subtitle"><?= escape($forum['description']) ?></p>
            <?php endif; ?>
        </div>
        <?php if (isLoggedIn()): ?>
            <a href="/topics/create.php?forum_id=<?= (int)$forum['id'] ?>" class="btn btn-primary">+ Создать тему</a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($topics)): ?>
    <div class="empty-state">
        <div class="empty-icon">📭</div>
        <h3>В этом разделе пока нет тем</h3>
        <p>Станьте первым — создайте новую тему!</p>
        <?php if (isLoggedIn()): ?>
            <a href="/topics/create.php?forum_id=<?= (int)$forum['id'] ?>" class="btn btn-primary">+ Создать тему</a>
        <?php else: ?>
            <a href="/auth/login.php" class="btn btn-primary">Войти, чтобы создать тему</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="topic-list">
        <div class="topic-list-head">
            <span>Тема</span>
            <span class="col-stats">Ответы / Просмотры</span>
            <span class="col-last">Активность</span>
        </div>

        <?php foreach ($topics as $t): ?>
            <a href="/topic.php?id=<?= (int)$t['id'] ?>" class="topic-row <?= $t['is_pinned'] ? 'topic-pinned' : '' ?> <?= $t['is_hidden'] ? 'topic-hidden-row' : '' ?>">
                <div class="topic-row-main">
                    <div class="topic-row-title">
                        <?php if ($t['is_pinned']): ?><span class="badge badge-pin">📌</span><?php endif; ?>
                        <?php if ($t['is_closed']): ?><span class="badge badge-closed">🔒</span><?php endif; ?>
                        <?= escape($t['title']) ?>
                    </div>
                    <div class="topic-row-author">
                        Автор: <strong><?= escape($t['username']) ?></strong>
                        · <?= date('d.m.Y H:i', strtotime($t['created_at'])) ?>
                    </div>
                </div>
                <div class="topic-row-stats col-stats">
                    <span><strong><?= (int)$t['visible_replies'] ?></strong> отв.</span>
                    <span><strong><?= (int)$t['views_count'] ?></strong> просм.</span>
                </div>
                <div class="topic-row-last col-last">
                    <?php if ($t['last_post_at']): ?>
                        <div><?= date('d.m.Y H:i', strtotime($t['last_post_at'])) ?></div>
                        <?php if ($t['last_username']): ?>
                            <div class="last-author"><?= escape($t['last_username']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <?php renderPagination($page, $totalPages, '/section.php?id=' . $forumId); ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>