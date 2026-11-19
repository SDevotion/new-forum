<?php
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/_layout.php';

$pageTitle = 'Структура форума';
$db = Database::getInstance()->getConnection();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Недействительный запрос';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'add_category') {
                $title = trim($_POST['title'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $sort = (int)($_POST['sort_order'] ?? 0);
                if (mb_strlen($title) < 2 || mb_strlen($title) > 100) {
                    $errors[] = 'Название категории: 2–100 символов';
                } else {
                    $db->prepare("INSERT INTO categories (title, description, sort_order) VALUES (?,?,?)")
                       ->execute([$title, $desc ?: null, $sort]);
                    logModeration('category_add', 'category', (int)$db->lastInsertId(), $title);
                    setFlashMessage('success', 'Категория создана');
                    redirect('/admin/structure.php');
                }

            } elseif ($action === 'add_forum') {
                $cat = (int)($_POST['category_id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $icon = trim($_POST['icon'] ?? '💬');
                $sort = (int)($_POST['sort_order'] ?? 0);

                $stmt = $db->prepare("SELECT id FROM categories WHERE id = ?");
                $stmt->execute([$cat]);

                if (mb_strlen($title) < 2 || mb_strlen($title) > 150) {
                    $errors[] = 'Название раздела: 2–150 символов';
                } elseif (!$stmt->fetch()) {
                    $errors[] = 'Категория не найдена';
                } else {
                    $db->prepare("INSERT INTO forums (category_id,title,description,icon,sort_order) VALUES (?,?,?,?,?)")
                       ->execute([$cat, $title, $desc ?: null, mb_substr($icon, 0, 10), $sort]);
                    logModeration('forum_add', 'forum', (int)$db->lastInsertId(), $title);
                    setFlashMessage('success', 'Раздел создан');
                    redirect('/admin/structure.php');
                }

            } elseif ($action === 'toggle_category') {
                $id = (int)$_POST['id'];
                $db->prepare("UPDATE categories SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
                logModeration('category_toggle', 'category', $id);
                setFlashMessage('success', 'Видимость категории изменена');
                redirect('/admin/structure.php');

            } elseif ($action === 'toggle_forum') {
                $id = (int)$_POST['id'];
                $db->prepare("UPDATE forums SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
                logModeration('forum_toggle', 'forum', $id);
                setFlashMessage('success', 'Видимость раздела изменена');
                redirect('/admin/structure.php');

            } elseif ($action === 'delete_category') {
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("SELECT COUNT(*) FROM forums WHERE category_id = ?");
                $stmt->execute([$id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $errors[] = 'Сначала удалите или перенесите разделы этой категории';
                } else {
                    $db->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
                    logModeration('category_delete', 'category', $id);
                    setFlashMessage('success', 'Категория удалена');
                    redirect('/admin/structure.php');
                }

            } elseif ($action === 'delete_forum') {
                $id = (int)$_POST['id'];
                $stmt = $db->prepare("SELECT COUNT(*) FROM topics WHERE forum_id = ?");
                $stmt->execute([$id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    $errors[] = 'В разделе есть темы. Сначала перенесите или удалите их';
                } else {
                    $db->prepare("DELETE FROM forums WHERE id = ?")->execute([$id]);
                    logModeration('forum_delete', 'forum', $id);
                    setFlashMessage('success', 'Раздел удалён');
                    redirect('/admin/structure.php');
                }
            }
        } catch (PDOException $e) {
            error_log('Structure error: ' . $e->getMessage());
            $errors[] = 'Ошибка базы данных';
        }
    }
}

$categories = $db->query("SELECT * FROM categories ORDER BY sort_order, id")->fetchAll();
$forumsStmt = $db->prepare("SELECT * FROM forums WHERE category_id = ? ORDER BY sort_order, id");

require_once __DIR__ . '/../includes/header.php';
adminOpen('structure');
?>

<nav class="breadcrumbs">
    <a href="/index.php">Главная</a><span class="sep">›</span>
    <a href="/moderation/index.php">Модерация</a><span class="sep">›</span>
    <span class="current">Структура форума</span>
</nav>

<div class="page-header"><h1>Структура форума</h1><p class="page-subtitle">Категории и разделы</p></div>

<?php if ($errors): ?>
    <div class="alert alert-error"><ul class="error-list"><?php foreach ($errors as $e): ?><li><?= escape($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="admin-grid">
    <div class="form-card form-card-flat">
        <h2 class="form-card-title form-card-title-sm">Новая категория</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="action" value="add_category">
            <div class="form-group"><label>Название</label><input type="text" name="title" class="form-control" maxlength="100" required></div>
            <div class="form-group"><label>Описание</label><input type="text" name="description" class="form-control"></div>
            <div class="form-group"><label>Порядок</label><input type="number" name="sort_order" class="form-control" value="0"></div>
            <button class="btn btn-primary btn-block">Создать категорию</button>
        </form>
    </div>

    <div class="form-card form-card-flat">
        <h2 class="form-card-title form-card-title-sm">Новый раздел</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="action" value="add_forum">
            <div class="form-group">
                <label>Категория</label>
                <select name="category_id" class="form-control" required>
                    <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= escape($c['title']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Название</label><input type="text" name="title" class="form-control" maxlength="150" required></div>
            <div class="form-group"><label>Описание</label><input type="text" name="description" class="form-control"></div>
            <div class="form-row">
                <div class="form-group"><label>Иконка</label><input type="text" name="icon" class="form-control" value="💬" maxlength="10"></div>
                <div class="form-group"><label>Порядок</label><input type="number" name="sort_order" class="form-control" value="0"></div>
            </div>
            <button class="btn btn-primary btn-block">Создать раздел</button>
        </form>
    </div>
</div>

<?php foreach ($categories as $c): ?>
    <?php $forumsStmt->execute([$c['id']]); $forums = $forumsStmt->fetchAll(); ?>
    <div class="category-block">
        <div class="category-header category-header-admin">
            <div>
                <h2><?= escape($c['title']) ?> <?= $c['is_active'] ? '' : '<span class="badge badge-banned">скрыта</span>' ?></h2>
                <?php if ($c['description']): ?><p><?= escape($c['description']) ?></p><?php endif; ?>
            </div>
            <div class="header-btn-group">
                <form method="POST" class="inline-form">
                    <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                    <input type="hidden" name="action" value="toggle_category">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button class="btn btn-sm btn-light"><?= $c['is_active'] ? 'Скрыть' : 'Показать' ?></button>
                </form>
                <form method="POST" class="inline-form js-confirm-structure-delete">
                    <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button class="btn btn-sm btn-danger">Удалить</button>
                </form>
            </div>
        </div>

        <?php if (empty($forums)): ?>
            <div class="admin-empty-inline">Разделов нет</div>
        <?php else: ?>
            <?php foreach ($forums as $f): ?>
                <div class="admin-forum-row">
                    <div class="forum-icon"><?= escape($f['icon']) ?></div>
                    <div class="forum-info">
                        <div class="forum-title"><?= escape($f['title']) ?> <?= $f['is_active'] ? '' : '<span class="badge badge-banned">скрыт</span>' ?></div>
                        <div class="forum-desc"><?= escape($f['description'] ?? '') ?></div>
                        <div class="muted small"><?= (int)$f['topics_count'] ?> тем · <?= (int)$f['posts_count'] ?> сообщений</div>
                    </div>
                    <div class="header-btn-group">
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                            <input type="hidden" name="action" value="toggle_forum">
                            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                            <button class="btn btn-sm btn-outline"><?= $f['is_active'] ? 'Скрыть' : 'Показать' ?></button>
                        </form>
                        <form method="POST" class="inline-form js-confirm-structure-delete">
                            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                            <input type="hidden" name="action" value="delete_forum">
                            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                            <button class="btn btn-sm btn-danger">Удалить</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php
adminClose();
require_once __DIR__ . '/../includes/footer.php';
?>