<?php
// admin/users.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [6.массовый 500] Ввод через getStr()/getInt(): ?q[]=x / ?role[]=x / ?page[]=x
//       больше не валят страницу TypeError'ом.
// Логика страницы не менялась.

require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/_layout.php';

$pageTitle = 'Управление пользователями';
$db = Database::getInstance()->getConnection();
$me = getCurrentUser();
require_once __DIR__ . '/../includes/pagination.php';

$q = trim(getStr('q'));
$roleFilter = getStr('role');
$perPage = max(1, setting('per_page_staff', 15));
$page = max(1, getInt('page', 1));
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($q !== '') {
    $qLike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $where[] = "(username LIKE ? OR email LIKE ?)";
    $params[] = $qLike;
    $params[] = $qLike;
}
if (in_array($roleFilter, ['user', 'moderator', 'admin'], true)) { $where[] = "role = ?"; $params[] = $roleFilter; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("SELECT COUNT(*) FROM users $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$stmt = $db->prepare("SELECT id, username, email, role, is_active, posts_total, topics_total, created_at FROM users $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();

$roles = ['user' => 'Участник', 'moderator' => 'Модератор', 'admin' => 'Админ'];
$queryBase = '/admin/users.php?q=' . urlencode($q) . '&role=' . urlencode($roleFilter);

require_once __DIR__ . '/../includes/header.php';
adminOpen('users');
?>

<nav class="breadcrumbs">
    <a href="/index.php">Главная</a><span class="sep">›</span>
    <a href="/moderation/index.php">Модерация</a><span class="sep">›</span>
    <span class="current">Пользователи</span>
</nav>

<div class="page-header"><h1>Пользователи</h1><p class="page-subtitle">Всего найдено: <?= $total ?></p></div>

<form method="GET" action="" class="filter-bar">
    <input type="text" name="q" class="form-control" value="<?= escape($q) ?>" placeholder="Поиск по нику или email">
    <select name="role" class="form-control">
        <option value="">Все роли</option>
        <?php foreach ($roles as $k => $v): ?>
            <option value="<?= $k ?>" <?= $roleFilter === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-primary">Найти</button>
    <a href="/admin/users.php" class="btn btn-outline">Сброс</a>
</form>

<div class="table-wrap">
    <table class="admin-table">
        <thead><tr><th>ID</th><th>Пользователь</th><th>Роль</th><th>Активность</th><th>Статус</th><th>Действия</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr class="<?= $u['is_active'] ? '' : 'row-inactive' ?>">
                <td><?= (int)$u['id'] ?></td>
                <td>
                    <a href="/profile.php?id=<?= (int)$u['id'] ?>"><strong><?= escape($u['username']) ?></strong></a>
                    <div class="muted small"><?= escape($u['email']) ?></div>
                    <div class="muted small">Рег.: <?= date('d.m.Y', strtotime($u['created_at'])) ?></div>
                </td>
                <td>
                    <?php if ((int)$u['id'] === (int)$me['id']): ?>
                        <span class="post-author-role role-<?= escape($u['role']) ?>"><?= $roles[$u['role']] ?></span>
                        <div class="muted small">это вы</div>
                    <?php else: ?>
                        <form method="POST" action="/moderation/action.php" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                            <input type="hidden" name="action" value="user_role">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <input type="hidden" name="back" value="<?= escape($queryBase . '&page=' . $page) ?>">
                            <select name="role" class="form-control form-control-sm js-auto-submit">
                                <?php foreach ($roles as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php endif; ?>
                </td>
                <td class="small"><?= (int)$u['topics_total'] ?> тем<br><?= (int)$u['posts_total'] ?> сообщ.</td>
                <td>
                    <?php if ($u['is_active']): ?><span class="badge badge-active">Активен</span>
                    <?php else: ?><span class="badge badge-banned">Заблокирован</span><?php endif; ?>
                </td>
                <td>
                    <?php if ((int)$u['id'] !== (int)$me['id'] && $u['role'] !== 'admin'): ?>
                        <form method="POST" action="/moderation/action.php" class="inline-form js-confirm-ban">
                            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                            <input type="hidden" name="action" value="user_ban">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <input type="hidden" name="back" value="<?= escape($queryBase . '&page=' . $page) ?>">
                            <input type="hidden" name="reason" value="Решение администратора">
                            <button class="btn btn-sm <?= $u['is_active'] ? 'btn-danger' : 'btn-outline' ?>">
                                <?= $u['is_active'] ? 'Блокировать' : 'Разблокировать' ?>
                            </button>
                        </form>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php renderPagination($page, $totalPages, $queryBase); ?>
<?php
adminClose();
require_once __DIR__ . '/../includes/footer.php';
?>