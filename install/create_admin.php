<?php
// install/create_admin.php — создание первого администратора (только CLI).
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [2.10] Повышение существующего аккаунта требует ЯВНОГО флага --promote.
//       Знания username+email недостаточно, чтобы сбросить чужой пароль:
//       без флага скрипт откажется трогать существующую запись.
// [new] --promote НЕ меняет пароль по умолчанию (только роль); сброс пароля
//       существующего аккаунта — отдельный явный флаг --reset-password.
// [new] Защита «последнего админа» не нужна здесь (скрипт только повышает),
//       но добавлена проверка cross-коллизии username/email как в регистрации.
//
// Запуск:
//   php install/create_admin.php <username> <email>                 # создать нового
//   php install/create_admin.php <username> <email> --promote       # повысить существующий (пароль НЕ меняется)
//   php install/create_admin.php <username> <email> --promote --reset-password
// Пароль: env FORUM_ADMIN_PASS или интерактивный ввод (аргументом не рекомендуется).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';

$args = array_slice($argv, 1);
$flags = [];
$positional = [];
foreach ($args as $a) {
    if (strpos($a, '--') === 0) $flags[$a] = true;
    else $positional[] = $a;
}
$username = isset($positional[0]) ? $positional[0] : null;
$email    = isset($positional[1]) ? $positional[1] : null;
$promote = isset($flags['--promote']);
$resetPassword = isset($flags['--reset-password']);

if (!$username || !$email) {
    fwrite(STDERR, "Usage: php install/create_admin.php <username> <email> [--promote] [--reset-password]\n");
    fwrite(STDERR, "Password via FORUM_ADMIN_PASS env or interactive prompt.\n");
    exit(1);
}
if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
    fwrite(STDERR, "Username: 3-50 chars, latin/digits/_\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email\n");
    exit(1);
}
if ($resetPassword && !$promote) {
    fwrite(STDERR, "--reset-password requires --promote\n");
    exit(1);
}

$db = Database::getInstance()->getConnection();

$st = $db->prepare("SELECT id, username, email, role FROM users WHERE username = ?");
$st->execute([$username]);
$byName = $st->fetch();

$st = $db->prepare("SELECT id, username, email, role FROM users WHERE email = ?");
$st->execute([$email]);
$byEmail = $st->fetch();

if ($byName && $byEmail && (int)$byName['id'] !== (int)$byEmail['id']) {
    fwrite(STDERR, "Conflict: username belongs to user #{$byName['id']}, email to user #{$byEmail['id']}. Aborting.\n");
    exit(1);
}
$existing = $byName ?: $byEmail;

if ($existing && !$promote) {
    fwrite(STDERR, "Account already exists (user #{$existing['id']}).\n");
    fwrite(STDERR, "To promote it WITHOUT touching its password:  add --promote\n");
    fwrite(STDERR, "To also reset its password (dangerous):       add --promote --reset-password\n");
    exit(1);
}
if ($existing && ((int)$byName['id'] !== (int)$existing['id'] || !$byName || !$byEmail)) {
    // Оба поля должны ТОЧНО принадлежать одному аккаунту.
    if (!$byName || !$byEmail || (int)$byName['id'] !== (int)$byEmail['id']) {
        fwrite(STDERR, "For --promote both username AND email must match the SAME existing account exactly.\n");
        exit(1);
    }
}

// Пароль нужен: новому аккаунту всегда; существующему — только с --reset-password.
$needPassword = !$existing || $resetPassword;
$password = '';
if ($needPassword) {
    $password = getenv('FORUM_ADMIN_PASS') ?: '';
    if ($password === '') {
        fwrite(STDOUT, "Password (min 8 chars): ");
        $password = trim((string)fgets(STDIN));
    }
    if (strlen($password) < 8) {
        fwrite(STDERR, "Password: min 8 chars\n");
        exit(1);
    }
}

if ($existing) {
    if ($resetPassword) {
        $db->prepare("UPDATE users SET role = 'admin', is_active = 1, password_hash = ? WHERE id = ?")
           ->execute([password_hash($password, PASSWORD_DEFAULT), $existing['id']]);
        // Все сессии владельца гасим — старый пароль/украденная сессия не живут.
        $db->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$existing['id']]);
        echo "User #{$existing['id']} promoted to admin, password reset, sessions revoked.\n";
    } else {
        $db->prepare("UPDATE users SET role = 'admin', is_active = 1 WHERE id = ?")
           ->execute([$existing['id']]);
        // Роль изменилась — активные сессии всё равно гасим (права в payload устарели).
        $db->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$existing['id']]);
        echo "User #{$existing['id']} promoted to admin (password unchanged, sessions revoked).\n";
    }
} else {
    $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')")
       ->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
    echo "Admin created: id=" . $db->lastInsertId() . "\n";
}
echo "Done.\n";