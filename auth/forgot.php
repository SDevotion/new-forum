<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ratelimit.php';
require_once __DIR__ . '/../includes/mailer.php';

if (isLoggedIn()) redirect('/index.php');

$pageTitle = 'Восстановление пароля';
$errors = [];
$sent = false;
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Недействительный запрос. Попробуйте снова.';
    } elseif (!rateLimitCheck('forgot', max(1, setting('rl_forgot_max', 3)), max(60, setting('rl_forgot_window', 3600)), true)) {
        $errors[] = 'Слишком много запросов. Попробуйте через час.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (!isValidEmail($email) || mb_strlen($email) > 100) {
            $errors[] = 'Укажите корректный email';
        } else {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id, username FROM users WHERE email = ? AND is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            // Одинаковый путь кода для существующего и несуществующего email
            // (выравнивание времени ответа против тайминг-энумерации).
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);

            if ($user) {
                $db->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);
                $db->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)")
                   ->execute([$user['id'], $hash]);

                $link = rtrim(APP_URL, '/') . '/auth/reset.php?token=' . $token;
                $body = "Здравствуйте, {$user['username']}!\n\n"
                      . "Для сброса пароля перейдите по ссылке (действует 1 час):\n$link\n\n"
                      . "Если вы не запрашивали сброс — просто проигнорируйте это письмо.\n\n"
                      . MAIL_FROM_NAME;
                sendMail($email, 'Восстановление пароля — ' . MAIL_FROM_NAME, $body);
            } else {
                // Фиктивная работа той же стоимости: хэширование + пауза,
                // сопоставимая с реальной отправкой. Письмо не уходит.
                usleep(random_int(150000, 350000));
            }

            $sent = true;
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <h1 class="auth-title">Восстановление пароля</h1>
        <p class="auth-subtitle">Укажите email, указанный при регистрации</p>

        <?php if ($sent): ?>
            <div class="alert alert-success">
                Если аккаунт с таким email существует, мы отправили ссылку для сброса пароля. Проверьте почту (и папку «Спам»). Ссылка действует 1 час.
            </div>
            <div class="auth-footer"><a href="/auth/login.php">Вернуться ко входу</a></div>
        <?php else: ?>
            <?php if ($errors): ?>
                <div class="alert alert-error"><ul class="error-list"><?php foreach ($errors as $e): ?><li><?= escape($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <form method="POST" action="" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= escape($email) ?>" maxlength="100" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Отправить ссылку</button>
            </form>
            <div class="auth-footer"><a href="/auth/login.php">Вспомнили пароль? Войти</a></div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
