<?php
// auth/login.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [1.1/приоритет#1] Коллизия username↔email: поиск ДВУМЯ отдельными запросами —
//       сначала точное совпадение username, затем email. fetch() первой попавшейся
//       строки из "OR" больше нет: владелец аккаунта всегда входит по своему нику,
//       даже если кто-то зарегистрировал ник, равный его email.
// [2.8] Парный лимит вынесен в атомарный loginPairCheck()/loginPairClear()
//       (includes/ratelimit.php) — дублирующий inline-код с GET_LOCK удалён.
// [6.массовый 500] Весь ввод через postStr()/verifyCSRFToken(string-safe):
//       username[]=x / password[]=x больше не валят страницу TypeError'ом.
// [2.9] CSRF-пул сбрасывается через csrfReset() (легаси unset csrf_token удалён).
// [new] При коллизии «ник одного = email другого» вход по спорной строке трактуется
//       как username-вход (детерминированно), жертва не блокируется.

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

    if (!verifyCSRFToken(postStr('csrf_token'))) {
        $errors[] = 'Недействительный запрос. Попробуйте снова.';
    } elseif (!rateLimitCheck('login', max(20, $loginMax * 10), $loginWindow, true)) {
        $errors[] = 'Слишком много попыток входа. Подождите ' . ceil(rateLimitRetryAfter('login', $loginWindow, true) / 60) . ' мин. и попробуйте снова.';
    } else {
        $username = trim(postStr('username'));
        $password = postStr('password');

        if ($username === '' || $password === '') {
            $errors[] = 'Заполните все поля';
        } elseif (strlen($password) > 4096 || mb_strlen($username) > 100) {
            $errors[] = 'Слишком длинные значения';
        } else {
            $pairKey = 'ip' . rateLimitIp() . ':' . mb_strtolower(mb_substr($username, 0, 100));
            $db = Database::getInstance()->getConnection();

            if (!loginPairCheck($pairKey, max(5, $loginMax), $loginWindow)) {
                $errors[] = 'Слишком много попыток входа для этого аккаунта. Подождите ' . ceil($loginWindow / 60) . ' мин.';
            } else {
                // Детерминированный поиск: username имеет приоритет над email.
                // Никакого "OR ... fetch()" — первая строка не выбирается случайно.
                $stmt = $db->prepare("SELECT id, username, password_hash, is_active FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if (!$user && isValidEmail($username)) {
                    $stmt = $db->prepare("SELECT id, username, password_hash, is_active FROM users WHERE email = ?");
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();
                }

                // Против тайминг-энумерации: verify выполняется всегда.
                $hash = $user ? $user['password_hash'] : DUMMY_PASSWORD_HASH;
                $ok = password_verify($password, $hash);

                if ($user && $ok && $user['is_active']) {
                    loginPairClear($pairKey);

                    session_regenerate_id(true);
                    unset($_SESSION['guest_seen'], $_SESSION['viewed_topics'], $_SESSION['attach_dl']);
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['username'] = $user['username'];
                    csrfReset();
                    generateCSRFToken();

                    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

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