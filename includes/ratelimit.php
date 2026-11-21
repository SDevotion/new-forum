<?php
// includes/ratelimit.php
// Лимитер частоты действий: атомарный (GET_LOCK), с поддержкой доверенных прокси.

require_once __DIR__ . '/functions.php';

/**
 * Клиентский IP. X-Forwarded-For учитывается ТОЛЬКО если REMOTE_ADDR входит
 * в TRUSTED_PROXIES (config/database.php). Берём самый правый недоверенный адрес.
 */
function rateLimitIp() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trusted = defined('TRUSTED_PROXIES') ? TRUSTED_PROXIES : [];
    if (!$trusted) return $remote;

    $isTrusted = function ($ip) use ($trusted) {
        foreach ($trusted as $t) {
            if ($ip === $t || strpos($ip, $t) === 0) return true;
        }
        return false;
    };
    if (!$isTrusted($remote)) return $remote;

    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $chain = array_values(array_filter(array_map('trim', explode(',', $xff))));
    // Идём справа налево: первый адрес, не являющийся доверенным прокси, — клиент.
    for ($i = count($chain) - 1; $i >= 0; $i--) {
        $ip = $chain[$i];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return $remote;
        if (!$isTrusted($ip)) return $ip;
    }
    return $remote;
}

/**
 * Ключ лимитера: user_id для залогиненных, иначе IP; $byIp — всегда IP.
 */
function rateLimitKey($byIp = false) {
    $uid = $byIp ? 0 : currentUserId();
    return $uid > 0 ? 'u' . $uid : 'ip' . rateLimitIp();
}

/**
 * Проверка лимита: не более $max событий за $windowSeconds.
 * Атомарно: DELETE+COUNT+INSERT под именованным локом MySQL, параллельные
 * запросы одного ключа сериализуются и не могут «проскочить» лимит.
 * Возвращает true, если действие РАЗРЕШЕНО (и записывает попытку).
 */
function rateLimitCheck($action, $max, $windowSeconds, $byIp = false) {
    $db = Database::getInstance()->getConnection();
    $key = rateLimitKey($byIp);
    $ip  = rateLimitIp();

    $lockName = 'rl:' . md5($action . '|' . $key);
    $got = $db->prepare("SELECT GET_LOCK(?, 3)");
    $got->execute([$lockName]);
    if ((int)$got->fetchColumn() !== 1) {
        // Лок не получен (перегрузка) — fail closed для чувствительных действий.
        return false;
    }

    try {
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
    } finally {
        $rel = $db->prepare("SELECT RELEASE_LOCK(?)");
        $rel->execute([$lockName]);
    }
}

/**
 * Сброс попыток конкретного ключа (например, после успешного входа).
 * ВАЖНО: не сбрасывайте весь IP-ключ — это позволяло обходить брутфорс-лимит,
 * логинясь в свой аккаунт между попытками перебора чужого.
 */
function rateLimitClear($action, $key) {
    $db = Database::getInstance()->getConnection();
    $db->prepare("DELETE FROM rate_limits WHERE action = ? AND actor_key = ?")->execute([$action, $key]);
}

/**
 * Сколько секунд ждать до следующей попытки.
 */
function rateLimitRetryAfter($action, $windowSeconds, $byIp = false) {
    $db = Database::getInstance()->getConnection();
    $key = rateLimitKey($byIp);

    $stmt = $db->prepare("
        SELECT TIMESTAMPDIFF(SECOND, NOW(), MIN(created_at) + INTERVAL ? SECOND)
        FROM rate_limits WHERE action = ? AND actor_key = ?
    ");
    $stmt->execute([$windowSeconds, $action, $key]);
    return max(1, (int)$stmt->fetchColumn());
}
