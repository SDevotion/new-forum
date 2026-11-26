<?php
// install/create_admin.php — создание первого администратора (только CLI).
// Запуск: php install/create_admin.php <username> <email> [password]
// Без третьего аргумента пароль читается из env FORUM_ADMIN_PASS или интерактивно
// (аргумент командной строки виден в ps/history — не рекомендуется).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';

[$script, $username, $email, $password] = array_pad($argv, 4, null);
if (!$username || !$email) {
    fwrite(STDERR, "Usage: php install/create_admin.php <username> <email> [password]\n");
    fwrite(STDERR, "Better: FORUM_ADMIN_PASS='...' php install/create_admin.php <username> <email>\n");
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

if ($password === null || $password === '') {
    $password = getenv('FORUM_ADMIN_PASS') ?: '';
}
if ($password === '') {
    fwrite(STDOUT, "Password (min 8 chars): ");
    $password = trim((string)fgets(STDIN));
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password: min 8 chars\n");
    exit(1);
}

$db = Database::getInstance()->getConnection();

// Раздельные проверки: совпадение только по username ИЛИ только по email —
// это, скорее всего, опечатка; молча захватывать чужой аккаунт нельзя.
$st = $db->prepare("SELECT id, username, email FROM users WHERE username = ?");
$st->execute([$username]);
$byName = $st->fetch();

$st = $db->prepare("SELECT id, username, email FROM users WHERE email = ?");
$st->execute([$email]);
$byEmail = $st->fetch();

if ($byName && $byEmail && (int)$byName['id'] !== (int)$byEmail['id']) {
    fwrite(STDERR, "Conflict: username belongs to user #{$byName['id']}, email to user #{$byEmail['id']}. Aborting.\n");
    exit(1);
}
if ($byName && !$byEmail) {
    fwrite(STDERR, "Username '{$username}' already taken by user #{$byName['id']} ({$byName['email']}).\n");
    fwrite(STDERR, "If this is YOUR account and you want to promote it, pass its exact email.\n");
    exit(1);
}
if ($byEmail && !$byName) {
    fwrite(STDERR, "Email '{$email}' already used by user #{$byEmail['id']} ({$byEmail['username']}).\n");
    fwrite(STDERR, "If this is YOUR account and you want to promote it, pass its exact username.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = $byName ?: $byEmail;

if ($existing) {
    // Оба поля совпали с одним аккаунтом — осознанное повышение.
    $db->prepare("UPDATE users SET role = 'admin', is_active = 1, password_hash = ? WHERE id = ?")
       ->execute([$hash, $existing['id']]);
    echo "User #{$existing['id']} promoted to admin, password reset.\n";
} else {
    $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')")
       ->execute([$username, $email, $hash]);
    echo "Admin created: id=" . $db->lastInsertId() . "\n";
}
echo "Done. Login and change the password immediately.\n";
