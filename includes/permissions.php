<?php
require_once __DIR__ . '/functions.php';

function isModerator() {
    $u = getCurrentUser();
    return $u && in_array($u['role'], ['moderator', 'admin'], true);
}

function isAdmin() {
    $u = getCurrentUser();
    return $u && $u['role'] === 'admin';
}

function requireModerator() {
    if (!isLoggedIn()) { setFlashMessage('error', 'Необходимо войти'); redirect('/auth/login.php'); }
    if (!isModerator()) { http_response_code(403); setFlashMessage('error', 'Недостаточно прав'); redirect('/index.php'); }
}

function requireAdmin() {
    if (!isLoggedIn()) { setFlashMessage('error', 'Необходимо войти'); redirect('/auth/login.php'); }
    if (!isAdmin()) { http_response_code(403); setFlashMessage('error', 'Только для администраторов'); redirect('/index.php'); }
}

function logModeration($action, $targetType, $targetId, $reason = null) {
    $u = getCurrentUser();
    if (!$u) return;
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("INSERT INTO moderation_log (user_id, action, target_type, target_id, reason) VALUES (?,?,?,?,?)");
    $stmt->execute([$u['id'], $action, $targetType, $targetId, $reason ? mb_substr($reason, 0, 255) : null]);
}

/**
 * Человекочитаемые названия действий модерации (единый словарь).
 */
function moderationActionNames() {
    return [
        'topic_pin_on' => 'Закрепление темы', 'topic_pin_off' => 'Открепление темы',
        'topic_close_on' => 'Закрытие темы', 'topic_close_off' => 'Открытие темы',
        'topic_hide_on' => 'Скрытие темы', 'topic_hide_off' => 'Показ темы',
        'topic_move' => 'Перенос темы', 'topic_delete' => 'Удаление темы',
        'post_hide' => 'Скрытие сообщения', 'post_unhide' => 'Показ сообщения',
        'report_resolve' => 'Жалоба принята', 'report_reject' => 'Жалоба отклонена',
        'user_ban' => 'Блокировка пользователя', 'user_unban' => 'Разблокировка',
        'user_role' => 'Смена роли', 'restore_revision' => 'Откат версии',
        'category_add' => 'Создание категории', 'category_toggle' => 'Видимость категории',
        'category_delete' => 'Удаление категории', 'forum_add' => 'Создание раздела',
        'forum_toggle' => 'Видимость раздела', 'forum_delete' => 'Удаление раздела',
        'settings_update' => 'Изменение настроек',
        'maint_recount' => 'Пересчёт счётчиков', 'maint_clean' => 'Очистка данных',
    ];
}

/**
 * Причина записи журнала в читаемом виде.
 * Переводит и новые записи, и легаси с техническими ключами.
 */
function moderationReasonLabel($reason) {
    $reason = trim((string)$reason);
    if ($reason === '') return '';

    // Легаси-форматы обслуживания: "rate_limits: 5", "orphan avatars: 1", ...
    $legacy = [
        'rate_limits'        => 'Лимитеры частоты: удалено записей — ',
        'search_log'         => 'Журнал поиска: удалено записей — ',
        'notifications'      => 'Прочитанные уведомления: удалено — ',
        'orphan attachments' => 'Вложения без записей: удалено файлов — ',
        'orphan avatars'     => 'Аватары без ссылок: удалено файлов — ',
    ];
    foreach ($legacy as $key => $label) {
        if (preg_match('/^' . preg_quote($key, '/') . ':\s*(\d+)$/u', $reason, $m)) {
            return $label . $m[1];
        }
    }
    if (preg_match('/^orphan records: edits=(\d+), reports=(\d+)$/u', $reason, $m)) {
        return "Записи без целей: правок — {$m[1]}, жалоб — {$m[2]}";
    }
    if (preg_match('/^из #(\d+) в #(\d+)$/u', $reason, $m)) {
        return "Из раздела #{$m[1]} в раздел #{$m[2]}";
    }
    if (preg_match('/^версия #(\d+)$/u', $reason, $m)) {
        return "Откат к версии #{$m[1]}";
    }
    if (in_array($reason, ['user', 'moderator', 'admin'], true)) {
        return 'Новая роль: ' . ['user' => 'Участник', 'moderator' => 'Модератор', 'admin' => 'Администратор'][$reason];
    }

    // settings_update: список ключей -> подписи (легаси)
    if (preg_match('/^[a-z0-9_]+(, [a-z0-9_]+)*$/u', $reason)) {
        $labels = settingLabels();
        $parts = array_map('trim', explode(',', $reason));
        $named = [];
        foreach ($parts as $key) {
            if (!isset($labels[$key])) { $named = []; break; }
            $named[] = $labels[$key];
        }
        if ($named) return implode(', ', $named);
    }

    return $reason;
}

/**
 * Подписи ключей настроек для журнала.
 */
function settingLabels() {
    return [
        'forum_closed' => 'Форум закрыт', 'forum_closed_message' => 'Сообщение о закрытии',
        'registration_enabled' => 'Регистрация',
        'title_min' => 'Заголовок: минимум', 'title_max' => 'Заголовок: максимум',
        'topic_min' => 'Первое сообщение: минимум', 'reply_min' => 'Ответ: минимум',
        'message_max' => 'Сообщение: максимум',
        'report_min' => 'Жалоба: минимум', 'report_max' => 'Жалоба: максимум',
        'password_min' => 'Пароль: минимум', 'bio_max' => '«О себе»: максимум',
        'location_max' => 'Город: максимум', 'website_max' => 'Сайт: максимум',
        'signature_max' => 'Подпись: максимум',
        'search_min' => 'Поиск: минимум', 'search_max' => 'Поиск: максимум',
        'edit_window_hours' => 'Окно редактирования',
        'per_page_topics' => 'Тем на странице', 'per_page_posts' => 'Постов на странице',
        'per_page_search' => 'Результатов поиска', 'per_page_members' => 'Участников на странице',
        'per_page_notifications' => 'Уведомлений на странице', 'per_page_autocomplete' => 'Автодополнение @имён',
        'per_page_staff' => 'Элементов в админке',
        'attach_max_files' => 'Вложений на сообщение', 'attach_max_size_mb' => 'Размер вложения',
        'attach_types' => 'Типы вложений', 'avatar_max_size_mb' => 'Размер аватара',
        'rl_login_max' => 'Вход: попыток', 'rl_login_window' => 'Вход: окно',
        'rl_register_max' => 'Регистрация: попыток', 'rl_register_window' => 'Регистрация: окно',
        'rl_topic_max' => 'Темы: штук', 'rl_topic_window' => 'Темы: окно',
        'rl_reply_max' => 'Ответы: штук', 'rl_reply_window' => 'Ответы: окно',
        'rl_report_max' => 'Жалобы: штук', 'rl_report_window' => 'Жалобы: окно',
        'online_window' => 'Окно «онлайн»',
    ];
}

function newReportsCount() {
    static $c = null;
    if ($c === null) {
        $db = Database::getInstance()->getConnection();
        $c = (int)$db->query("SELECT COUNT(*) FROM reports WHERE status = 'new'")->fetchColumn();
    }
    return $c;
}