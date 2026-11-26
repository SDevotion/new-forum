<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ratelimit.php';

if (isLoggedIn()) {
    redirect('/index.php');
}

$pageTitle = 'Вход';
$errors = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginMax    = max(1, setting('rl_login_max', 5));
    $loginWindow = max(60, setting('rl_login_window', 900));

    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Недействительный запрос. Попробуйте снова.';
    } elseif (!rateLimitCheck('login', max(20, $loginMax * 10), $loginWindow, true)) {
        $errors[] = 'Слишком много попыток входа. Подождите ' . ceil(rateLimitRetryAfter('login', $loginWindow, true) / 60) . ' мин. и попробуйте снова.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $errors[] = 'Заполните все поля';
        } elseif (!is_string($password) || strlen($password) > 4096) {
            $errors[] = 'Пароль слишком длинный';
        } else {
            $pairKey = 'ip' . rateLimitIp() . ':' . mb_strtolower(mb_substr($username, 0, 50));
            $db = Database::getInstance()->getConnection();

            $lockName = 'rl:' . md5('login_pair|' . $pairKey);
            $got = $db->prepare("SELECT GET_LOCK(?, 3)");
            $got->execute([$lockName]);
            $pairAllowed = false;
            if ((int)$got->fetchColumn() === 1) {
                $db->prepare("DELETE FROM rate_limits WHERE action = 'login_pair' AND actor_key = ? AND created_at < (NOW() - INTERVAL ? SECOND)")
                   ->execute([$pairKey, $loginWindow]);
                $st = $db->prepare("SELECT COUNT(*) FROM rate_limits WHERE action = 'login_pair' AND actor_key = ?");
                $st->execute([$pairKey]);
                if ((int)$st->fetchColumn() < max(5, $loginMax)) {
                    $db->prepare("INSERT INTO rate_limits (action, actor_key, ip_address) VALUES ('login_pair', ?, ?)")
                       ->execute([$pairKey, rateLimitIp()]);
                    $pairAllowed = true;
                }
                $rel = $db->prepare("SELECT RELEASE_LOCK(?)");
                $rel->execute([$lockName]);
            }

            if (!$pairAllowed) {
                $errors[] = 'Слишком много попыток входа для этого аккаунта. Подождите ' . ceil($loginWindow / 60) . ' мин.';
            } else {
                $stmt = $db->prepare("SELECT id, username, password_hash, is_active FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $username]);
                $user = $stmt->fetch();

                // Против тайминг-энумерации: verify выполняется всегда,
                // даже если пользователя нет (валидный фиктивный bcrypt-хэш).
                $hash = $user ? $user['password_hash'] : DUMMY_PASSWORD_HASH;
                $ok = password_verify($password, $hash);

                if ($user && $ok && $user['is_active']) {
                    rateLimitClear('login_pair', $pairKey);

                    session_regenerate_id(true);
                    unset($_SESSION['guest_seen'], $_SESSION['viewed_topics'], $_SESSION['attach_dl']);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    unset($_SESSION['csrf_token']);
                    generateCSRFToken();

                    $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $stmt->execute([$user['id']]);

                    setFlashMessage('success', 'Добро пожаловать, ' . $user['username'] . '!');
                    redirect('/index.php');
                } else {
                    $errors[] = 'Неверное имя пользователя или пароль';
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <h1 class="auth-title">Вход</h1>
        <p class="auth-subtitle">Войдите в свой аккаунт</p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?= escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="auth-form" id="loginForm">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">

            <div class="form-group">
                <label for="username">Имя пользователя или Email</label>
                <input type="text"
                       id="username"
                       name="username"
                       class="form-control"
                       value="<?= escape($username) ?>"
                       placeholder="ivan_petrov или ivan@example.com"
                       maxlength="100"
                       required>
            </div>

            <div class="form-group">
                <label for="password">Пароль</label>
                <input type="password"
                       id="password"
                       name="password"
                       class="form-control"
                       placeholder="Введите пароль"
                       maxlength="4096"
                       required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Войти</button>
        </form>

        <div class="auth-footer">
            <a href="/auth/forgot.php">Забыли пароль?</a>
            <?php if (setting('registration_enabled', true)): ?> · Нет аккаунта? <a href="/auth/register.php">Зарегистрироваться</a><?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
