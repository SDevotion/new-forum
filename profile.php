<?php
// profile.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [6.массовый 500] Ввод через getInt()/getStr() — массивы в id/tab/page не фаталят.
// [new] website в профиле рендерится только если проходит http(s)-валидацию
//       (данные могли попасть в БД до валидации в profile/edit.php).
// Логика вкладок/счётчиков не менялась.

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/avatar.php';
require_once __DIR__ . '/includes/stats.php';
require_once __DIR__ . '/includes/markdown.php';
require_once __DIR__ . '/includes/pagination.php';

$db = Database::getInstance()->getConnection();
$currentUser = getCurrentUser();
$userId = getInt('id');

if (!$userId && $currentUser) {
    $userId = (int)$currentUser['id'];
}

$stmt = $db->prepare("
    SELECT id, username, avatar, role, bio, location, website, signature,
           posts_total, topics_total, created_at, last_login, last_activity
    FROM users WHERE id = ? AND is_active = 1
");
$stmt->execute([$userId]);
$profile = $stmt->fetch();

if (!$profile) {
    http_response_code(404);
    $pageTitle = 'Пользователь не найден';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="empty-state"><div class="empty-icon">👤</div><h3>Пользователь не найден</h3><a href="/index.php" class="btn btn-primary">Вернуться к форуму</a></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = 'Профиль: ' . $profile['username'];
$isOwn = $currentUser && (int)$currentUser['id'] === (int)$profile['id'];
$isStaff = isModerator();
$staffFlag = $isStaff ? 1 : 0;
$roles = ['user' => 'Участник', 'moderator' => 'Модератор', 'admin' => 'Администратор'];

$stmt = $db->prepare("
    SELECT COUNT(*) FROM topics t
    JOIN forums f ON f.id = t.forum_id
    JOIN categories c ON c.id = f.category_id
    WHERE t.user_id = ? AND f.is_active = 1 AND c.is_active = 1 AND (t.is_hidden = 0 OR ? = 1)
");
$stmt->execute([$userId, $staffFlag]);
$visibleTopics = (int)$stmt->fetchColumn();

$stmt = $db->prepare("
    SELECT COUNT(*) FROM posts p
    JOIN topics t ON t.id = p.topic_id
    JOIN forums f ON f.id = t.forum_id
    JOIN categories c ON c.id = f.category_id
    WHERE p.user_id = ? AND f.is_active = 1 AND c.is_active = 1
      AND (p.is_hidden = 0 OR ? = 1) AND (t.is_hidden = 0 OR ? = 1)
");
$stmt->execute([$userId, $staffFlag, $staffFlag]);
$visiblePosts = (int)$stmt->fetchColumn();

$tab = getStr('tab', 'topics') === 'posts' ? 'posts' : 'topics';
$perPage = max(1, setting('per_page_posts', 15));
$page = max(1, getInt('page', 1));
$offset = ($page - 1) * $perPage;

if ($tab === 'topics') {
    $total = $visibleTopics;
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

    $stmt = $db->prepare("
        SELECT t.id, t.title, t.views_count, t.created_at,
               (SELECT COUNT(*) FROM posts vp WHERE vp.topic_id = t.id AND (vp.is_hidden = 0 OR ? = 1)) AS visible_replies,
               f.title AS forum_title
        FROM topics t
        JOIN forums f ON f.id = t.forum_id
        JOIN categories c ON c.id = f.category_id
        WHERE t.user_id = ? AND f.is_active = 1 AND c.is_active = 1 AND (t.is_hidden = 0 OR ? = 1)
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute([$staffFlag, $userId, $staffFlag]);
} else {
    $total = $visiblePosts;
    $totalPages = max(1, (int)ceil($total / $perPage));
    if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

    $stmt = $db->prepare("
        SELECT p.id, p.content, p.created_at, p.topic_id,
               t.title AS topic_title
        FROM posts p
        JOIN topics t ON t.id = p.topic_id
        JOIN forums f ON f.id = t.forum_id
        JOIN categories c ON c.id = f.category_id
        WHERE p.user_id = ? AND f.is_active = 1 AND c.is_active = 1
          AND (p.is_hidden = 0 OR ? = 1) AND (t.is_hidden = 0 OR ? = 1)
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute([$userId, $staffFlag, $staffFlag]);
}
$items = $stmt->fetchAll();

$websiteSafe = ($profile['website'] && preg_match('#^https?://#i', $profile['website'])
                && filter_var($profile['website'], FILTER_VALIDATE_URL)) ? $profile['website'] : null;

require_once __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumbs">
    <a href="/index.php">Главная</a>
    <span class="sep">›</span>
    <span class="current"><?= escape($profile['username']) ?></span>
</nav>

<div class="profile-card">
    <div class="profile-main">
        <img src="<?= avatarUrl($profile['avatar']) ?>" alt="" class="profile-avatar">
        <div class="profile-info">
            <h1 class="profile-name">
                <?= escape($profile['username']) ?>
                <?php if ($profile['last_activity'] && (time() - strtotime($profile['last_activity'])) <= ONLINE_WINDOW): ?>
                    <span class="profile-online-badge">онлайн</span>
                <?php endif; ?>
            </h1>
            <div class="post-author-role role-<?= escape($profile['role']) ?>">
                <?= $roles[$profile['role']] ?? 'Участник' ?>
            </div>

            <?php if ($profile['bio']): ?>
                <p class="profile-bio"><?= nl2br(escape($profile['bio'])) ?></p>
            <?php endif; ?>

            <div class="profile-details">
                <?php if ($profile['location']): ?>
                    <span>📍 <?= escape($profile['location']) ?></span>
                <?php endif; ?>
                <?php if ($websiteSafe): ?>
                    <span>🔗 <a href="<?= escape($websiteSafe) ?>" target="_blank" rel="noopener nofollow noreferrer"><?= escape($websiteSafe) ?></a></span>
                <?php endif; ?>
                <span>📅 На форуме с <?= date('d.m.Y', strtotime($profile['created_at'])) ?></span>
                <?php $lastSeen = $profile['last_activity'] ?: $profile['last_login']; ?>
                <?php if ($lastSeen): ?>
                    <span>🕒 Был(а) <?= date('d.m.Y H:i', strtotime($lastSeen)) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="profile-side">
        <div class="profile-stats">
            <div class="profile-stat">
                <span class="profile-stat-value"><?= $visibleTopics ?></span>
                <span class="profile-stat-label">Тем</span>
            </div>
            <div class="profile-stat">
                <span class="profile-stat-value"><?= $visiblePosts ?></span>
                <span class="profile-stat-label">Сообщений</span>
            </div>
        </div>
        <?php if ($isOwn): ?>
            <a href="/profile/edit.php" class="btn btn-primary btn-block">Редактировать профиль</a>
        <?php endif; ?>
    </div>
</div>

<div class="tabs">
    <a href="/profile.php?id=<?= $userId ?>&tab=topics" class="tab <?= $tab === 'topics' ? 'active' : '' ?>">
        Темы <span class="tab-count"><?= $visibleTopics ?></span>
    </a>
    <a href="/profile.php?id=<?= $userId ?>&tab=posts" class="tab <?= $tab === 'posts' ? 'active' : '' ?>">
        Сообщения <span class="tab-count"><?= $visiblePosts ?></span>
    </a>
</div>

<?php if (empty($items)): ?>
    <div class="empty-state empty-state-sm">
        <div class="empty-icon">📭</div>
        <h3>Пока пусто</h3>
        <p><?= $tab === 'topics' ? 'Пользователь ещё не создавал тем.' : 'Пользователь ещё не оставлял сообщений.' ?></p>
    </div>
<?php else: ?>
    <div class="activity-list">
        <?php foreach ($items as $item): ?>
            <?php if ($tab === 'topics'): ?>
                <a href="/topic.php?id=<?= (int)$item['id'] ?>" class="activity-row">
                    <div class="activity-title"><?= escape($item['title']) ?></div>
                    <div class="activity-meta">
                        Раздел: <?= escape($item['forum_title']) ?>
                        · <?= (int)$item['visible_replies'] ?> отв.
                        · <?= date('d.m.Y H:i', strtotime($item['created_at'])) ?>
                    </div>
                </a>
            <?php else: ?>
                <a href="/topic.php?id=<?= (int)$item['topic_id'] ?>#post-<?= (int)$item['id'] ?>" class="activity-row">
                    <div class="activity-excerpt"><?= escape(mdExcerpt($item['content'], 180)) ?></div>
                    <div class="activity-meta">
                        В теме: <?= escape($item['topic_title']) ?>
                        · <?= date('d.m.Y H:i', strtotime($item['created_at'])) ?>
                    </div>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php renderPagination($page, $totalPages, '/profile.php?id=' . $userId . '&tab=' . $tab); ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>