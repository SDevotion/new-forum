<?php
require_once __DIR__ . '/../includes/permissions.php';
requireAdmin();
require_once __DIR__ . '/_layout.php';

$pageTitle = 'Основные настройки';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Недействительный запрос');
        redirect('/admin/settings.php');
    }
    $msg = trim($_POST['forum_closed_message'] ?? '');
    if (mb_strlen($msg) > 255) $msg = mb_substr($msg, 0, 255);
    if ($msg === '') $msg = 'Форум временно закрыт для новых сообщений';

    $changed = settingsSet([
        'registration_enabled' => isset($_POST['registration_enabled']),
        'forum_closed'         => isset($_POST['forum_closed']),
        'forum_closed_message' => $msg,
    ]);
    if ($changed) {
        logModeration('settings_update', 'forum', 0, implode(', ', $changed));
        setFlashMessage('success', 'Настройки сохранены');
    } else {
        setFlashMessage('info', 'Изменений нет');
    }
    redirect('/admin/settings.php');
}

require_once __DIR__ . '/../includes/header.php';
adminOpen('general');
?>

<div class="page-header"><h1>Основные настройки</h1></div>

<form method="POST" action="/admin/settings.php" class="admin-form">
    <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">

    <div class="admin-card">
        <h3>Доступ</h3>
        <label class="admin-check">
            <input type="checkbox" name="registration_enabled" value="1" <?= setting('registration_enabled', true) ? 'checked' : '' ?>>
            <span>Регистрация новых участников разрешена</span>
        </label>
        <label class="admin-check">
            <input type="checkbox" name="forum_closed" value="1" <?= setting('forum_closed', false) ? 'checked' : '' ?>>
            <span>Закрыть форум (чтение для всех, новые темы и ответы — только модерация)</span>
        </label>
        <div class="form-group">
            <label for="closedMsg">Сообщение о закрытии</label>
            <input type="text" id="closedMsg" name="forum_closed_message" class="form-control" maxlength="255"
                   value="<?= escape(setting('forum_closed_message', 'Форум временно закрыт для новых сообщений')) ?>">
            <small class="form-hint">Показывается вместо формы ответа и при попытке создать тему. До 255 символов.</small>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Сохранить</button>
    </div>
</form>

<?php
adminClose();
require_once __DIR__ . '/../includes/footer.php';