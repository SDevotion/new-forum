<?php
require_once __DIR__ . '/../includes/functions.php';

// Если уже авторизован - редирект на главную
if (isLoggedIn()) {
    redirect('/index.php');
}

$pageTitle = 'Вход';
$errors = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Недействительный запрос. Попробуйте снова.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $errors[] = 'Заполните все поля';
        } else {
            $db = Database::getInstance()->getConnection();
            
            // Поиск по username или email
            $stmt = $db->prepare("SELECT id, username, password_hash, is_active FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                if (!$user['is_active']) {
                    $errors[] = 'Ваш аккаунт заблокирован';
                } else {
                    // Успешный вход
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    
                    // Обновление времени последнего входа
                    $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    
                    setFlashMessage('success', 'Добро пожаловать, ' . $user['username'] . '!');
                    redirect('/index.php');
                }
            } else {
                $errors[] = 'Неверное имя пользователя или пароль';
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
                       required>
            </div>
            
            <div class="form-group">
                <label for="password">Пароль</label>
                <input type="password" 
                       id="password" 
                       name="password" 
                       class="form-control" 
                       placeholder="Введите пароль"
                       required>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block">Войти</button>
        </form>
        
        <div class="auth-footer">
            Нет аккаунта? <a href="/auth/register.php">Зарегистрироваться</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>