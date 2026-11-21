<?php
// install/create_admin.php — создание первого администратора (только CLI).
// Запуск: php install/create_admin.php <username> <email> <password>
// Пример: php install/create_admin.php admin admin@example.com 'ChangeMe_123!'

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/database.php';

[$script, $username, $email, $password] = array_pad($argv, 4, null);
if (!$username || !$email || !$password) {
    fwrite(STDERR, "Usage: php install/create_admin.php <username> <email> <password>\n");
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
if (strlen($password) < 8) {
    fwrite(STDERR, "Password: min 8 chars\n");
    exit(1);
}

$db = Database::getInstance()->getConnection();
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
$stmt->execute([$username, $email]);
$existing = $stmt->fetchColumn();

if ($existing) {
    $db->prepare("UPDATE users SET role = 'admin', is_active = 1, password_hash = ? WHERE id = ?")
       ->execute([$hash, $existing]);
    echo "User #$existing promoted to admin, password reset.\n";
} else {
    $db->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')")
       ->execute([$username, $email, $hash]);
    echo "Admin created: id=" . $db->lastInsertId() . "\n";
}
echo "Done. Login and change the password immediately.\n";
