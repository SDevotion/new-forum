<?php
// members.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [6.массовый 500] Ввод через getStr()/getInt() — массивы в sort/q/page не фаталят.
// Логика не менялась.

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/avatar.php';
require_once __DIR__ . '/includes/stats.php';
require_once __DIR__ . '/includes/pagination.php';

$pageTitle = 'Участники';
$db = Database::getInstance()->getConnection();

$sort = getStr('sort', 'posts');
if (!in_array($sort, ['posts', 'topics', 'new', 'name'], true)) $sort = 'posts';
$q = trim(getStr('q'));

$perPage = max(1, setting('per_page_members', 15));
$page = max(1, getInt('page', 1));
$offset = ($page - 1) * $perPage;

$where = ["is_active = 1"];
$params = [];
if ($q !== '') {
    $qLike = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
    $where[] = "username LIKE ?";
    $params[] = $qLike . '%';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$order = [
    'posts'  => 'posts_total DESC, id ASC',
    'topics' => 'topics_total DESC, id ASC',
    'new'    => 'id DESC',
    'name'   => 'username ASC',
][$sort];

$stmt = $db->prepare("SELECT COUNT(*) FROM users $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$stmt = $db->prepare("
    SELECT id, username, avatar, role, posts_total, topics_total, created_at, last_activity
    FROM users $whereSql ORDER BY $order LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();

$roles = ['user' => 'Участник', 'moderator' => 'Модератор', 'admin' => 'Администратор'];
$base = '/members.php?sort=' . $sort . '&q=' . urlencode($q);

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>Участники</h1>
    <p class="page-subtitle">Всего: <?= $total ?></p>
</div>

<form method="GET" action="" class="filter-bar">
    <input type="hidden" name="sort" value="<?= escape($sort) ?>">
    <input type="text" name="q" class="form-control" value="<?= escape($q) ?>" placeholder="Поиск по нику">
    <button class="btn btn-primary">Найти</button>
    <a href="/members.php?sort=<?= escape($sort) ?>" class="btn btn-outline">Сброс</a>
</form>

<div class="tabs">
    <a href="/members.php?sort=posts&q=<?= urlencode($q) ?>" class="tab <?= $sort === 'posts' ? 'active' : '' ?>">По сообщениям</a>
    <a href="/members.php?sort=topics&q=<?= urlencode($q) ?>" class="tab <?= $sort === 'topics' ? 'active' : '' ?>">По темам</a>
    <a href="/members.php?sort=new&q=<?= urlencode($q) ?>" class="tab <?= $sort === 'new' ? 'active' : '' ?>">Новые</a>
    <a href="/members.php?sort=name&q=<?= urlencode($q) ?>" class="tab <?= $sort === 'name' ? 'active' : '' ?>">По алфавиту</a>
</div>

<?php if (empty($users)): ?>
    <div class="empty-state empty-state-sm"><div class="empty-icon">👤</div><h3>Никого не найдено</h3></div>
<?php else: ?>
    <div class="members-grid">
        <?php foreach ($users as $u): ?>
            <?php $isOnline = $u['last_activity'] && (time() - strtotime($u['last_activity'])) <= ONLINE_WINDOW; ?>
            <a href="/profile.php?id=<?= (int)$u['id'] ?>" class="member-card">
                <div class="member-avatar-wrap">
                    <img src="<?= avatarUrl($u['avatar']) ?>" alt="" class="member-avatar">
                    <?php if ($isOnline): ?><span class="member-online" title="Онлайн"></span><?php endif; ?>
                </div>
                <div class="member-name"><?= escape($u['username']) ?></div>
                <div class="post-author-role role-<?= escape($u['role']) ?>"><?= $roles[$u['role']] ?? 'Участник' ?></div>
                <div class="member-stats">
                    <span><strong><?= (int)$u['posts_total'] ?></strong> сообщ.</span>
                    <span><strong><?= (int)$u['topics_total'] ?></strong> тем</span>
                </div>
                <div class="muted small">с <?= date('d.m.Y', strtotime($u['created_at'])) ?></div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php renderPagination($page, $totalPages, $base); ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>