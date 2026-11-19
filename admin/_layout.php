<?php
// admin/_layout.php — общий каркас страниц админки.
require_once __DIR__ . '/../includes/permissions.php';
requireAdmin();

$adminMenu = [
    'dashboard' => ['📊 Дашборд',            '/admin/index.php'],
    'general'   => ['⚙️ Основные настройки', '/admin/settings.php'],
    'structure' => ['🗂 Структура форума',   '/admin/structure.php'],
    'users'     => ['👥 Пользователи',       '/admin/users.php'],
];

function adminSidebar($active) {
    global $adminMenu;
    echo '<aside class="admin-sidebar"><nav class="admin-nav">';
    foreach ($adminMenu as $key => [$label, $url]) {
        $cls = $key === $active ? 'admin-nav-link active' : 'admin-nav-link';
        echo '<a href="' . $url . '" class="' . $cls . '">' . $label . '</a>';
    }
    echo '<a href="/moderation/index.php" class="admin-nav-link admin-nav-sep">🛡 Модерация</a>';
    echo '</nav></aside>';
}

function adminOpen($active) {
    echo '<div class="admin-wrap">';
    adminSidebar($active);
    echo '<div class="admin-content">';
}

function adminClose() {
    echo '</div></div>';
}