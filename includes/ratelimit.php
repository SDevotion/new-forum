<?php
// includes/ratelimit.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [1.6] rateLimitRefund(): откат списанной квоты, если действие subsequently
//       отклонено бизнес-проверками (reply/create вызывают его при закрытой теме,
//       невалидных вложениях и т.п.) — невалидные попытки больше не жгут лимит.
// [2.8] loginPairCheck()/loginPairClear(): парный логин-лимит вынесен из
//       auth/login.php в общий атомарный хелпер (тот же GET_LOCK-паттерн).
// [new] rateLimitConsume() — вариант check+refund одним вызовом не нужен,
//       но добавлен rateLimitPeek(): проверка БЕЗ списания (для превью ошибок).
// IP/прокси-логика без изменений (была корректной).

require_once __DIR__ . '/functions.php';

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
    for ($i = count($chain) - 1; $i >= 0; $i--) {
        $ip = $chain[$i];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return $remote;
        if (!$isTrusted($ip)) return $ip;
    }
    return $remote;
}

function rateLimitKey($byIp = false) {
    $uid = $byIp ? 0 : currentUserId();
    return $uid > 0 ? 'u' . $uid : 'ip' . rateLimitIp();
}

/**
 * Проверка лимита со списанием попытки. Атомарно под GET_LOCK.
 * Если действие потом отклонено — вызовите rateLimitRefund().
 */
function rateLimitCheck($action, $max, $windowSeconds, $byIp = false) {
    $db = Database::getInstance()->getConnection();
    $key = rateLimitKey($byIp);
    $ip  = rateLimitIp();

    $lockName = 'rl:' . md5($action . '|' . $key);
    $got = $db->prepare('SELECT GET_LOCK(?, 3)');
    $got->execute([$lockName]);
    if ((int)$got->fetchColumn() !== 1) return false; // fail closed

    try {
        $stmt = $db->prepare('DELETE FROM rate_limits WHERE action = ? AND actor_key = ? AND created_at < (NOW() - INTERVAL ? SECOND)');
        $stmt->execute([$action, $key, $windowSeconds]);

        $stmt = $db->prepare('SELECT COUNT(*) FROM rate_limits WHERE action = ? AND actor_key = ?');
        $stmt->execute([$action, $key]);
        if ((int)$stmt->fetchColumn() >= $max) return false;

        $stmt = $db->prepare('INSERT INTO rate_limits (action, actor_key, ip_address) VALUES (?, ?, ?)');
        $stmt->execute([$action, $key, $ip]);
        // Запоминаем id последней записи для точечного refund
        $GLOBALS['__rl_last'][$action . '|' . $key] = (int)$db->lastInsertId();
        return true;
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}

/** Возврат одной списанной попытки (действие отклонено бизнес-логикой). */
function rateLimitRefund($action, $byIp = false) {
    $key = rateLimitKey($byIp);
    $id = $GLOBALS['__rl_last'][$action . '|' . $key] ?? 0;
    if ($id <= 0) return;
    unset($GLOBALS['__rl_last'][$action . '|' . $key]);
    try {
        Database::getInstance()->getConnection()
            ->prepare('DELETE FROM rate_limits WHERE id = ? AND action = ? AND actor_key = ?')
            ->execute([$id, $action, $key]);
    } catch (PDOException $e) { /* не критично */ }
}

/** Проверка БЕЗ списания (показать «подождите N минут» до валидации). */
function rateLimitPeek($action, $max, $windowSeconds, $byIp = false) {
    $db = Database::getInstance()->getConnection();
    $key = rateLimitKey($byIp);
    $stmt = $db->prepare('SELECT COUNT(*) FROM rate_limits WHERE action = ? AND actor_key = ? AND created_at >= (NOW() - INTERVAL ? SECOND)');
    $stmt->execute([$action, $key, $windowSeconds]);
    return (int)$stmt->fetchColumn() < $max;
}

function rateLimitClear($action, $key) {
    Database::getInstance()->getConnection()
        ->prepare('DELETE FROM rate_limits WHERE action = ? AND actor_key = ?')
        ->execute([$action, $key]);
}

function rateLimitRetryAfter($action, $windowSeconds, $byIp = false) {
    $db = Database::getInstance()->getConnection();
    $key = rateLimitKey($byIp);
    $stmt = $db->prepare('
        SELECT TIMESTAMPDIFF(SECOND, NOW(), MIN(created_at) + INTERVAL ? SECOND)
        FROM rate_limits WHERE action = ? AND actor_key = ?
    ');
    $stmt->execute([$windowSeconds, $action, $key]);
    return max(1, (int)$stmt->fetchColumn());
}

/**
 * Парный логин-лимит (IP + введённый логин), атомарно.
 * Возвращает true и списывает попытку, либо false (лимит исчерпан).
 */
function loginPairCheck($pairKey, $max, $windowSeconds) {
    $db = Database::getInstance()->getConnection();
    $lockName = 'rl:' . md5('login_pair|' . $pairKey);
    $got = $db->prepare('SELECT GET_LOCK(?, 3)');
    $got->execute([$lockName]);
    if ((int)$got->fetchColumn() !== 1) return false;
    try {
        $db->prepare("DELETE FROM rate_limits WHERE action = 'login_pair' AND actor_key = ? AND created_at < (NOW() - INTERVAL ? SECOND)")
           ->execute([$pairKey, $windowSeconds]);
        $st = $db->prepare("SELECT COUNT(*) FROM rate_limits WHERE action = 'login_pair' AND actor_key = ?");
        $st->execute([$pairKey]);
        if ((int)$st->fetchColumn() >= $max) return false;
        $db->prepare("INSERT INTO rate_limits (action, actor_key, ip_address) VALUES ('login_pair', ?, ?)")
           ->execute([$pairKey, rateLimitIp()]);
        return true;
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }
}
function loginPairClear($pairKey) {
    rateLimitClear('login_pair', $pairKey);
}