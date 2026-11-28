<?php
// auth/register.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [1.1] Перекрёстные проверки занятости: ник нового юзера сверяется и с чужими
//       EMAIL, а email — с чужими НИКАМИ. Сквоттинг «ник = чужой email» (который
//       ломал вход жертве) невозможен на этапе регистрации.
// [6.массовый 500] Ввод только через postStr(): массивы в полях не валят trim().
// [1.6-паттерн] Отклонённая регистрация (занято/валидация) возвращает списанную
//       попытку лимитера через rateLimitRefund() — опечатки не жгут квоту;
//       успешная и honeypot — нет.
// Honeypot и антиэнумерация сохранены.

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ratelimit.php';

if (!setting('registration_enabled', true)) {
    setFlashMessage('error', 'Регистрация новых участников временно отключена');
    redirect('/auth/login.php');
}

$pageTitle = 'Регистрация';
$errors = [];
$username = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken(postStr('csrf_token'))) {
        $errors[] = 'Недействительный запрос. Попробуйте снова.';
    } elseif (postStr('website_url') !== '') {
        // Honeypot: имитируем успех, ничего не создаём. Квоту не возвращаем.
        setFlashMessage('success', 'Регистрация успешна! Теперь вы можете войти.');
        redirect('/auth/login.php');
    } elseif (!rateLimitCheck('register', max(1, setting('rl_register_max', 3)), max(60, setting('rl_register_window', 3600)), true)) {
        $errors[] = 'Слишком много регистраций с вашего адреса. Попробуйте через час.';
    } else {
        $username = trim(postStr('username'));
        $email = trim(postStr('email'));
        $password = postStr('password');
        $passwordConfirm = postStr('password_confirm');

        if ($username === '') {
            $errors[] = 'Введите имя пользователя';
        } elseif (!isValidUsername($username)) {
            $errors[] = 'Имя пользователя должно содержать 3-50 символов (латиница, цифры, _)';
        }

        if ($email === '') {
            $errors[] = 'Введите email';
        } elseif (!isValidEmail($email) || mb_strlen($email) > 100) {
            $errors[] = 'Некорректный email';
        }

        if ($password === '') {
            $errors[] = 'Введите пароль';
        } elseif (mb_strlen($password) < setting('password_min', 6)) {
            $errors[] = 'Пароль должен содержать минимум ' . setting('password_min', 6) . ' символов';
        } elseif (strlen($password) > 4096) {
            $errors[] = 'Пароль слишком длинный (максимум 4096 байт)';
        }

        if ($password !== $passwordConfirm) {
            $errors[] = 'Пароли не совпадают';
        }

        if (empty($errors)) {
            $db = Database::getInstance()->getConnection();

            // Прямые совпадения + ПЕРЕКРЁСТНЫЕ: ник против чужих email,
            // email против чужих ников. Один запрос, четыре параметра.
            $stmt = $db->prepare("
                SELECT id FROM users
                WHERE username = ? OR email = ? OR email = ? OR username = ?
                LIMIT 1
            ");
            $stmt->execute([$username, $email, $username, $email]);

            if ($stmt->fetch()) {
                // Нейтрально: не раскрываем, что именно занято (антиэнумерация).
                $errors[] = 'Это имя пользователя или email недоступны для регистрации. Выберите другие или войдите.';
            }
        }

        if (empty($errors)) {
            $db = Database::getInstance()->getConnection();
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            try {
                $db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)")
                   ->execute([$username, $email, $passwordHash]);
                setFlashMessage('success', 'Регистрация успешна! Теперь вы можете войти.');
                redirect('/auth/login.php');
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    // Гонка: кто-то занял имя/email между проверкой и вставкой.
                    $errors[] = 'Это имя пользователя или email недоступны для регистрации. Выберите другие или войдите.';
                } else {
                    error_log("Registration error: " . $e->getMessage());
                    $errors[] = 'Ошибка при регистрации. Попробуйте позже.';
                }
            }
        }

        // Любая отклонённая попытка — возврат квоты лимитера.
        if (!empty($errors)) {
            rateLimitRefund('register', true);
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <h1 class="auth-title">Регистрация</h1>
        <p class="auth-subtitle">Создайте аккаунт для участия в обсуждениях</p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?= escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="auth-form" id="registerForm">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">

            <!-- Honeypot: люди это поле не видят и не заполняют -->
            <div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">
                <label for="website_url">Не заполняйте это поле</label>
                <input type="text" id="website_url" name="website_url" tabindex="-1" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="username">Имя пользователя</label>
                <input type="text"
                       id="username"
                       name="username"
                       class="form-control"
                       value="<?= escape($username) ?>"
                       placeholder="ivan_petrov"
                       maxlength="50"
                       required>
                <small class="form-hint">3-50 символов: латиница, цифры, _</small>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email"
                       id="email"
                       name="email"
                       class="form-control"
                       value="<?= escape($email) ?>"
                       placeholder="ivan@example.com"
                       maxlength="100"
                       required>
            </div>

            <div class="form-group">
                <label for="password">Пароль</label>
                <input type="password"
                       id="password"
                       name="password"
                       class="form-control"
                       placeholder="Минимум <?= setting('password_min', 6) ?> символов"
                       minlength="<?= setting('password_min', 6) ?>"
                       maxlength="4096"
                       required>
            </div>

            <div class="form-group">
                <label for="password_confirm">Подтверждение пароля</label>
                <input type="password"
                       id="password_confirm"
                       name="password_confirm"
                       class="form-control"
                       placeholder="Повторите пароль"
                       maxlength="4096"
                       required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Зарегистрироваться</button>
        </form>

        <div class="auth-footer">
            Уже есть аккаунт? <a href="/auth/login.php">Войти</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>