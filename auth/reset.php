<?php
// auth/reset.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [6.массовый 500] Ввод через postStr()/getStr(): массивы в token/password не фаталят.
// [2.9] После смены пароля пул CSRF текущей (гостевой) сессии тоже сбрасывается —
//       старые токены страницы сброса не переживают смену пароля.
// [new] Атомарное «погашение» токена: DELETE ... и проверка rowCount вместо
//       SELECT-then-UPDATE — два параллельных POST с одним токеном не могут оба
//       сменить пароль (второй получает «ссылка недействительна»).
// Токен только в POST-теле, PRG, удаление всех сессий пользователя — сохранены.

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

/** Проверка токена -> userId (0 если невалиден). */
$resolveToken = function ($t) use ($db) {
    if (!is_string($t) || !preg_match('/^[a-f0-9]{64}$/', $t)) return 0;
    $stmt = $db->prepare("
        SELECT r.user_id FROM password_resets r
        JOIN users u ON u.id = r.user_id AND u.is_active = 1
        WHERE r.token_hash = ? AND r.expires_at > NOW()
    ");
    $stmt->execute([hash('sha256', $t)]);
    return (int)$stmt->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = postStr('token');
    $userId = $resolveToken($token);
    $validToken = $userId > 0;

    if (!$validToken) {
        $errors[] = 'Ссылка недействительна или истекла. Запросите восстановление заново.';
    } elseif (!verifyCSRFToken(postStr('csrf_token'))) {
        $errors[] = 'Недействительный запрос. Обновите страницу.';
    } elseif (!rateLimitCheck('reset', 30, 900, true)) {
        $errors[] = 'Слишком много попыток. Подождите 15 минут.';
    } else {
        $new = postStr('new_password');
        $confirm = postStr('new_password_confirm');

        if (mb_strlen($new) < setting('password_min', 6)) {
            $errors[] = 'Пароль должен содержать минимум ' . setting('password_min', 6) . ' символов';
        } elseif (strlen($new) > 4096) {
            $errors[] = 'Пароль слишком длинный';
        } elseif ($new !== $confirm) {
            $errors[] = 'Пароли не совпадают';
        }

        if (!$errors) {
            try {
                $db->beginTransaction();

                // Атомарное погашение: ровно один параллельный POST выиграет.
                $st = $db->prepare("DELETE FROM password_resets WHERE token_hash = ? AND expires_at > NOW()");
                $st->execute([hash('sha256', $token)]);
                if ($st->rowCount() !== 1) {
                    $db->rollBack();
                    $errors[] = 'Ссылка уже использована. Запросите восстановление заново.';
                } else {
                    $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                       ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
                    $db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$userId]);
                    $db->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$userId]);
                    $db->commit();

                    csrfReset(); // токены страницы сброса больше не действуют
                    redirect('/auth/reset.php?done=1');
                }
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Reset error: ' . $e->getMessage());
                $errors[] = 'Ошибка при сохранении. Попробуйте позже.';
            }
        }
        if ($errors) rateLimitRefund('reset', true);
    }
} else {
    $token = getStr('token');
    $userId = $resolveToken($token);
    $validToken = $userId > 0;
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