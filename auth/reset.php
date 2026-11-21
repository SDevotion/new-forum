<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ratelimit.php';

if (isLoggedIn()) redirect('/index.php');

$pageTitle = 'Новый пароль';
$errors = [];
$done = isset($_GET['done']);
$validToken = false;
$userId = 0;
$token = '';

$db = Database::getInstance()->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Токен приходит только в теле POST (не в URL) — не утекает в Referer/логи.
    $token = $_POST['token'] ?? '';
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
        $stmt = $db->prepare("
            SELECT r.user_id FROM password_resets r
            JOIN users u ON u.id = r.user_id AND u.is_active = 1
            WHERE r.token_hash = ? AND r.expires_at > NOW()
        ");
        $stmt->execute([hash('sha256', $token)]);
        $userId = (int)$stmt->fetchColumn();
        $validToken = $userId > 0;
    }

    if (!$validToken) {
        $errors[] = 'Ссылка недействительна или истекла. Запросите восстановление заново.';
    } elseif (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Недействительный запрос. Обновите страницу.';
    } elseif (!rateLimitCheck('reset', 5, 900, true)) {
        $errors[] = 'Слишком много попыток. Подождите 15 минут.';
    } else {
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['new_password_confirm'] ?? '';

        if (!is_string($new) || mb_strlen($new) < setting('password_min', 6)) {
            $errors[] = 'Пароль должен содержать минимум ' . setting('password_min', 6) . ' символов';
        } elseif (strlen($new) > 4096) {
            $errors[] = 'Пароль слишком длинный';
        } elseif ($new !== $confirm) {
            $errors[] = 'Пароли не совпадают';
        }

        if (!$errors) {
            try {
                $db->beginTransaction();
                $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                   ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
                $db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$userId]);
                $db->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$userId]);
                $db->commit();
                // PRG: уходим с URL, содержащего токен; результат показываем на чистом URL.
                redirect('/auth/reset.php?done=1');
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Reset error: ' . $e->getMessage());
                $errors[] = 'Ошибка при сохранении. Попробуйте позже.';
            }
        }
    }
} else {
    // GET: токен в URL только для первичного перехода из письма.
    $token = $_GET['token'] ?? '';
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
        $stmt = $db->prepare("
            SELECT r.user_id FROM password_resets r
            JOIN users u ON u.id = r.user_id AND u.is_active = 1
            WHERE r.token_hash = ? AND r.expires_at > NOW()
        ");
        $stmt->execute([hash('sha256', $token)]);
        $userId = (int)$stmt->fetchColumn();
        $validToken = $userId > 0;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <h1 class="auth-title">Новый пароль</h1>

        <?php if ($done): ?>
            <div class="alert alert-success">Пароль изменён. Теперь вы можете войти с новым паролем.</div>
            <div class="auth-footer"><a href="/auth/login.php">Войти</a></div>
        <?php elseif (!$validToken && !$errors): ?>
            <div class="alert alert-error">Ссылка недействительна или истекла. Запросите восстановление заново.</div>
            <div class="auth-footer"><a href="/auth/forgot.php">Запросить новую ссылку</a></div>
        <?php else: ?>
            <p class="auth-subtitle">Придумайте новый пароль для вашего аккаунта</p>
            <?php if ($errors): ?>
                <div class="alert alert-error"><ul class="error-list"><?php foreach ($errors as $e): ?><li><?= escape($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <?php if ($validToken): ?>
            <!-- Форма POSTит на чистый URL: токен только в hidden-поле, не в query -->
            <form method="POST" action="/auth/reset.php" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                <input type="hidden" name="token" value="<?= escape($token) ?>">
                <div class="form-group">
                    <label for="new_password">Новый пароль</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" minlength="<?= setting('password_min', 6) ?>" maxlength="4096" required autofocus>
                </div>
                <div class="form-group">
                    <label for="new_password_confirm">Повторите пароль</label>
                    <input type="password" id="new_password_confirm" name="new_password_confirm" class="form-control" minlength="<?= setting('password_min', 6) ?>" maxlength="4096" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Сохранить пароль</button>
            </form>
            <?php else: ?>
                <div class="auth-footer"><a href="/auth/forgot.php">Запросить новую ссылку</a></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
