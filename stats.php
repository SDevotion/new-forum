<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/avatar.php';
require_once __DIR__ . '/includes/stats.php';

$pageTitle = 'Статистика и рейтинги';
$db = Database::getInstance()->getConnection();
$totals = getForumTotals();
$online = getOnlineList();

$period = $_GET['period'] ?? 'all';
if (!in_array($period, ['all', 'month', 'week'], true)) $period = 'all';

$dateCond = '';
if ($period === 'month') $dateCond = " AND p.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
if ($period === 'week')  $dateCond = " AND p.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";

// Топ по сообщениям
if ($period === 'all') {
    $topPosters = $db->query("
        SELECT id, username, avatar, role, posts_total AS cnt
        FROM users WHERE is_active = 1 AND posts_total > 0
        ORDER BY posts_total DESC, id ASC LIMIT 10
    ")->fetchAll();
} else {
    $topPosters = $db->query("
        SELECT u.id, u.username, u.avatar, u.role, COUNT(p.id) AS cnt
        FROM posts p
        JOIN users u ON u.id = p.user_id
        WHERE u.is_active = 1 AND p.is_hidden = 0 $dateCond
        GROUP BY u.id, u.username, u.avatar, u.role
        ORDER BY cnt DESC, u.id ASC LIMIT 10
    ")->fetchAll();
}

// Топ по темам
$topicCond = str_replace('p.created_at', 't.created_at', $dateCond);
$topAuthors = $db->query("
    SELECT u.id, u.username, u.avatar, u.role, COUNT(t.id) AS cnt
    FROM topics t
    JOIN users u ON u.id = t.user_id
    WHERE u.is_active = 1 AND t.is_hidden = 0 $topicCond
    GROUP BY u.id, u.username, u.avatar, u.role
    ORDER BY cnt DESC, u.id ASC LIMIT 10
")->fetchAll();

// Популярные темы (вес и счётчик — по видимым ответам)
$popTopics = $db->query("
    SELECT t.id, t.title, t.views_count, u.username,
           (SELECT COUNT(*) FROM posts vp WHERE vp.topic_id = t.id AND vp.is_hidden = 0) AS visible_replies
    FROM topics t
    JOIN users u ON u.id = t.user_id
    JOIN forums f ON f.id = t.forum_id
    JOIN categories c ON c.id = f.category_id
    WHERE t.is_hidden = 0 AND f.is_active = 1 AND c.is_active = 1
    ORDER BY ((SELECT COUNT(*) FROM posts vp WHERE vp.topic_id = t.id AND vp.is_hidden = 0) * 2 + t.views_count / 10) DESC, t.id DESC LIMIT 10
")->fetchAll();

// Активность за 14 дней
$chart = $db->query("
    SELECT day, topics, posts, users FROM forum_stats_daily
    WHERE day >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    ORDER BY day ASC
")->fetchAll();

$byDay = [];
foreach ($chart as $row) $byDay[$row['day']] = $row;

$series = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $series[] = [
        'label'  => date('d.m', strtotime($d)),
        'posts'  => (int)($byDay[$d]['posts'] ?? 0),
        'topics' => (int)($byDay[$d]['topics'] ?? 0),
        'users'  => (int)($byDay[$d]['users'] ?? 0),
    ];
}
$maxVal = 1;
foreach ($series as $s) $maxVal = max($maxVal, $s['posts'], $s['topics'], $s['users']);

// Активные разделы
$topForums = $db->query("
    SELECT f.id, f.title, f.icon, f.posts_count
    FROM forums f
    JOIN categories c ON c.id = f.category_id
    WHERE f.is_active = 1 AND c.is_active = 1
    ORDER BY f.posts_count DESC, f.id ASC LIMIT 8
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>Статистика и рейтинги</h1>
    <p class="page-subtitle">Активность сообщества форума</p>
</div>

<div class="admin-stats">
    <div class="admin-stat"><span class="admin-stat-value"><?= $totals['topics'] ?></span><span class="admin-stat-label">Тем</span></div>
    <div class="admin-stat"><span class="admin-stat-value"><?= $totals['posts'] ?></span><span class="admin-stat-label">Сообщений</span></div>
    <div class="admin-stat"><span class="admin-stat-value"><?= $totals['users'] ?></span><span class="admin-stat-label">Участников</span></div>
    <div class="admin-stat"><span class="admin-stat-value"><?= $online['count'] ?></span><span class="admin-stat-label">Сейчас онлайн</span></div>
</div>

<div class="today-bar">
    <span class="today-label">Сегодня:</span>
    <span>+<?= $totals['today_topics'] ?> тем</span>
    <span>+<?= $totals['today_posts'] ?> сообщений</span>
    <span>+<?= $totals['today_users'] ?> участников</span>
    <?php if ($totals['newest']): ?>
        <span class="today-newest">Новый участник: <a href="/profile.php?id=<?= (int)$totals['newest']['id'] ?>"><?= escape($totals['newest']['username']) ?></a></span>
    <?php endif; ?>
</div>

<h2 class="section-title">Активность за 14 дней</h2>
<div class="chart-card">
    <div class="chart-legend">
        <span class="legend-item"><i class="dot dot-posts"></i>Сообщения</span>
        <span class="legend-item"><i class="dot dot-topics"></i>Темы</span>
        <span class="legend-item"><i class="dot dot-users"></i>Регистрации</span>
    </div>
    <div class="chart">
        <?php foreach ($series as $s): ?>
            <div class="chart-col" title="<?= $s['label'] ?>: <?= $s['posts'] ?> сообщ., <?= $s['topics'] ?> тем, <?= $s['users'] ?> новых">
                <div class="chart-bars">
                    <div class="bar bar-posts" style="height:<?= round($s['posts'] / $maxVal * 100) ?>%"></div>
                    <div class="bar bar-topics" style="height:<?= round($s['topics'] / $maxVal * 100) ?>%"></div>
                    <div class="bar bar-users" style="height:<?= round($s['users'] / $maxVal * 100) ?>%"></div>
                </div>
                <div class="chart-label"><?= $s['label'] ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="tabs">
    <a href="/stats.php?period=all" class="tab <?= $period === 'all' ? 'active' : '' ?>">За всё время</a>
    <a href="/stats.php?period=month" class="tab <?= $period === 'month' ? 'active' : '' ?>">За месяц</a>
    <a href="/stats.php?period=week" class="tab <?= $period === 'week' ? 'active' : '' ?>">За неделю</a>
</div>

<div class="rating-grid">
    <div class="rating-card">
        <h3 class="rating-title">🏆 Топ по сообщениям</h3>
        <?php if (empty($topPosters)): ?>
            <p class="rating-empty">Нет данных за период</p>
        <?php else: ?>
            <?php foreach ($topPosters as $i => $u): ?>
                <a href="/profile.php?id=<?= (int)$u['id'] ?>" class="rating-row">
                    <span class="rating-place place-<?= $i + 1 ?>"><?= $i + 1 ?></span>
                    <img src="<?= avatarUrl($u['avatar']) ?>" alt="" class="rating-avatar">
                    <span class="rating-name"><?= escape($u['username']) ?></span>
                    <span class="rating-value"><?= (int)$u['cnt'] ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="rating-card">
        <h3 class="rating-title">✍️ Топ по темам</h3>
        <?php if (empty($topAuthors)): ?>
            <p class="rating-empty">Нет данных за период</p>
        <?php else: ?>
            <?php foreach ($topAuthors as $i => $u): ?>
                <a href="/profile.php?id=<?= (int)$u['id'] ?>" class="rating-row">
                    <span class="rating-place place-<?= $i + 1 ?>"><?= $i + 1 ?></span>
                    <img src="<?= avatarUrl($u['avatar']) ?>" alt="" class="rating-avatar">
                    <span class="rating-name"><?= escape($u['username']) ?></span>
                    <span class="rating-value"><?= (int)$u['cnt'] ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<h2 class="section-title">🔥 Популярные темы</h2>
<?php if (empty($popTopics)): ?>
    <div class="empty-state empty-state-sm"><div class="empty-icon">📭</div><h3>Тем пока нет</h3></div>
<?php else: ?>
    <div class="activity-list">
        <?php foreach ($popTopics as $t): ?>
            <a href="/topic.php?id=<?= (int)$t['id'] ?>" class="activity-row">
                <div class="activity-title"><?= escape($t['title']) ?></div>
                <div class="activity-meta"><?= escape($t['username']) ?> · <?= (int)$t['visible_replies'] ?> ответов · <?= (int)$t['views_count'] ?> просмотров</div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2 class="section-title">📊 Активные разделы</h2>
<div class="forum-bars">
    <?php
    $maxPosts = 1;
    foreach ($topForums as $f) $maxPosts = max($maxPosts, (int)$f['posts_count']);
    ?>
    <?php if (empty($topForums)): ?>
        <p class="rating-empty">Разделов пока нет</p>
    <?php endif; ?>
    <?php foreach ($topForums as $f): ?>
        <a href="/section.php?id=<?= (int)$f['id'] ?>" class="forum-bar-row">
            <span class="forum-bar-name"><?= escape($f['icon'] . ' ' . $f['title']) ?></span>
            <span class="forum-bar-track"><span class="forum-bar-fill" style="width:<?= round($f['posts_count'] / $maxPosts * 100) ?>%"></span></span>
            <span class="forum-bar-value"><?= (int)$f['posts_count'] ?></span>
        </a>
    <?php endforeach; ?>
</div>

<h2 class="section-title">👥 Сейчас на форуме</h2>
<div class="online-card">
    <div class="online-summary">
        Всего: <strong><?= $online['count'] ?></strong> участников
        <?php if ($online['guests']): ?> и <strong><?= $online['guests'] ?></strong> гостей<?php endif; ?>
        <span class="muted small">(активность за последние <?= (int)ceil(ONLINE_WINDOW / 60) ?> мин.)</span>
    </div>
    <?php if (empty($online['users'])): ?>
        <p class="rating-empty">Никого нет онлайн</p>
    <?php else: ?>
        <div class="online-list">
            <?php foreach ($online['users'] as $u): ?>
                <a href="/profile.php?id=<?= (int)$u['id'] ?>" class="online-user">
                    <img src="<?= avatarUrl($u['avatar']) ?>" alt="" class="online-avatar">
                    <span class="online-name role-text-<?= escape($u['role']) ?>"><?= escape($u['username']) ?></span>
                    <span class="online-dot"></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>