<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/notifications.php';
$currentUser = getCurrentUser();

// Онлайн-трекинг
touchActivity();
trackGuest();
$flashMessage = getFlashMessage();

// CSP: свой origin + data:-картинки; инлайн-скрипт конфигурации — по nonce.
$cspNonce = base64_encode(random_bytes(16));
if (!headers_sent()) {
    // style-src-elem: только свои файлы + nonce (инлайн <style> из JS получает nonce).
    // style-src-attr: инлайн-атрибуты style="" в шаблонах (динамические ширины графиков).
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; script-src 'self' 'nonce-" . $cspNonce . "'; style-src 'self' 'unsafe-inline'; style-src-elem 'self' 'nonce-" . $cspNonce . "'; style-src-attr 'unsafe-inline'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? escape($pageTitle) . ' - ' : '' ?>Форум</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: 1 ?>">
    <script nonce="<?= $cspNonce ?>">
    window.FG_NONCE = <?= json_encode($cspNonce) ?>;
    window.FG_CONFIG = <?= json_encode([
        'titleMin'    => setting('title_min', 5),
        'titleMax'    => setting('title_max', 200),
        'topicMin'    => setting('topic_min', 10),
        'replyMin'    => setting('reply_min', 2),
        'messageMax'  => setting('message_max', 20000),
        'reportMin'   => setting('report_min', 5),
        'reportMax'   => setting('report_max', 500),
        'passwordMin' => setting('password_min', 6),
        'avatarMaxMb' => max(1, setting('avatar_max_size_mb', 2)),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
</head>
<body>
    <header class="header">
        <div class="container">
            <div class="header-content">
                <div class="logo">
                    <a href="/index.php">
                        <span class="logo-icon">💬</span>
                        <span class="logo-text">ForumHub</span>
                    </a>
                </div>

                <nav class="nav">
                    <a href="/index.php" class="nav-link">Главная</a>
                    <a href="/members.php" class="nav-link">Участники</a>
                    <a href="/stats.php" class="nav-link">Статистика</a>
                    <a href="/search.php" class="nav-link">Поиск</a>
                    <?php if ($currentUser): ?>
                        <a href="/notifications.php" class="nav-link nav-link-notif" title="Уведомления">
                            <span class="notif-bell">🔔</span>
                            <?php $nc = unreadCount(); if ($nc): ?><span class="notif-badge"><?= $nc > 99 ? '99+' : $nc ?></span><?php endif; ?>
                        </a>
                        <a href="/profile.php?id=<?= (int)$currentUser['id'] ?>" class="nav-link">Профиль</a>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                        <a href="/admin/index.php" class="nav-link nav-link-mod">Админка</a>
                    <?php endif; ?>
                    <?php if (isModerator()): ?>
                        <a href="/moderation/index.php" class="nav-link nav-link-mod">
                            Модерация
                            <?php $rc = newReportsCount(); if ($rc): ?><span class="nav-badge"><?= $rc ?></span><?php endif; ?>
                        </a>
                    <?php endif; ?>
                </nav>

                <form method="GET" action="/search.php" class="header-search">
                    <input type="text" name="q" placeholder="Поиск..." maxlength="<?= setting('search_max', 100) ?>" value="<?= escape($_GET['q'] ?? '') ?>">
                    <button type="submit" title="Найти">🔍</button>
                </form>

                <div class="user-menu">
                    <?php if ($currentUser): ?>
                        <a href="/profile.php?id=<?= (int)$currentUser['id'] ?>" class="user-info">
                            <img src="<?= avatarUrl($currentUser['avatar']) ?>"
                                 alt="Avatar" class="user-avatar">
                            <span class="username"><?= escape($currentUser['username']) ?></span>
                        </a>
                        <form method="POST" action="/auth/logout.php" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                            <button type="submit" class="btn btn-outline">Выйти</button>
                        </form>
                    <?php else: ?>
                        <a href="/auth/login.php" class="btn btn-outline">Войти</a>
                        <?php if (setting('registration_enabled', true)): ?>
                            <a href="/auth/register.php" class="btn btn-primary">Регистрация</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <main class="main">
        <div class="container">
            <?php if ($flashMessage): ?>
                <div class="alert alert-<?= escape($flashMessage['type']) ?>">
                    <?= escape($flashMessage['message']) ?>
                    <button class="alert-close">&times;</button>
                </div>
            <?php endif; ?>