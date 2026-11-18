<?php
// includes/ratelimit.php
// Простой лимитер частоты действий на основе сессии и IP.
// Хранение — таблица rate_limits (см. SQL ниже). Без внешних зависимостей.

require_once __DIR__ . '/functions.php';

/**
 * Клиентский IP. REMOTE_ADDR — единственное доверенное значение;
 * X-Forwarded-For учитываем только если явно включено (за доверенным прокси).
 */
function rateLimitIp() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Проверка лимита: не более $max событий за $windowSeconds для ключа $action + идентификатор.
 * Возвращает true, если действие РАЗРЕШЕНО (и записывает попытку), false — если лимит превышен.
 * Идентификатор: user_id для залогиненных, иначе IP (для логина/регистрации — всегда IP).
 */
function rateLimitCheck($action, $max, $windowSeconds, $byIp = false) {
    $db = Database::getInstance()->getConnection();

    $uid = (!$byIp && isLoggedIn()) ? (int)$_SESSION['user_id'] : 0;
    $ip  = rateLimitIp();
    $key = $uid > 0 ? 'u' . $uid : 'ip' . $ip;

    // Чистим протухшие записи этого ключа
    $stmt = $db->prepare("DELETE FROM rate_limits WHERE action = ? AND actor_key = ? AND created_at < (NOW() - INTERVAL ? SECOND)");
    $stmt->execute([$action, $key, $windowSeconds]);

    $stmt = $db->prepare("SELECT COUNT(*) FROM rate_limits WHERE action = ? AND actor_key = ?");
    $stmt->execute([$action, $key]);
    if ((int)$stmt->fetchColumn() >= $max) {
        return false;
    }

    $stmt = $db->prepare("INSERT INTO rate_limits (action, actor_key, ip_address) VALUES (?, ?, ?)");
    $stmt->execute([$action, $key, $ip]);
    return true;
}

/**
 * Сколько секунд ждать до следующей попытки (для сообщения пользователю).
 */
function rateLimitRetryAfter($action, $windowSeconds, $byIp = false) {
    $db = Database::getInstance()->getConnection();
    $uid = (!$byIp && isLoggedIn()) ? (int)$_SESSION['user_id'] : 0;
    $key = $uid > 0 ? 'u' . $uid : 'ip' . rateLimitIp();

    $stmt = $db->prepare("
        SELECT TIMESTAMPDIFF(SECOND, NOW(), MIN(created_at) + INTERVAL ? SECOND)
        FROM rate_limits WHERE action = ? AND actor_key = ?
    ");
    $stmt->execute([$windowSeconds, $action, $key]);
    return max(1, (int)$stmt->fetchColumn());
}