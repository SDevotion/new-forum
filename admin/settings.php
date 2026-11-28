<?php
// admin/settings.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [6.ranges] Диапазоны берутся из settingsRanges() (includes/settings.php) —
//       локальный дубль удалён; неизвестный int-ключ не фаталит ($ranges[$key]).
// [6.clamped] $clamped инициализируется всегда (не только в else-ветке).
// [5.5] settingsSet() атомарен (часть 1) — ошибка записи показывает алерт,
//       а не полу-сохранённый конфиг.
// [6.массовый 500] Ввод через postStr()/getStr(); attach_types фильтруется is_string.

require_once __DIR__ . '/../includes/permissions.php';
requireAdmin();
require_once __DIR__ . '/_layout.php';

$pageTitle = 'Настройки';

$tabs = ['general' => 'Основные', 'content' => 'Контент', 'pagination' => 'Пагинация', 'uploads' => 'Вложения', 'limits' => 'Лимиты'];
$tab = getStr('tab', 'general');
if (!isset($tabs[$tab])) $tab = 'general';

$ranges = settingsRanges();
$clamped = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken(postStr('csrf_token'))) {
        setFlashMessage('error', 'Недействительный запрос');
        redirect('/admin/settings.php?tab=' . $tab);
    }

    $kv = [];
    if ($tab === 'general') {
        $msg = trim(postStr('forum_closed_message'));
        if (mb_strlen($msg) > 255) $msg = mb_substr($msg, 0, 255);
        if ($msg === '') $msg = 'Форум временно закрыт для новых сообщений';
        $kv = [
            'registration_enabled' => isset($_POST['registration_enabled']),
            'forum_closed'         => isset($_POST['forum_closed']),
            'forum_closed_message' => $msg,
            'email_notifications'  => isset($_POST['email_notifications']),
        ];
    } else {
        if ($tab === 'uploads') {
            require_once __DIR__ . '/../includes/attachments.php';
            $allowedGroups = array_keys(attachTypeCatalog());
            $picked = $_POST['attach_types'] ?? [];
            if (!is_array($picked)) $picked = [];
            $picked = array_filter($picked, 'is_string');
            $kv['attach_types'] = array_values(array_intersect($allowedGroups, $picked));
        }
        $keys = array_keys(array_filter(settingsDefaults(), fn($d) => $d[2] === $tab && $d[0] === 'int'));
        foreach ($keys as $key) {
            if (!isset($_POST[$key]) || !is_scalar($_POST[$key])) continue;
            $val = (int)$_POST[$key];
            [$min, $max] = settingsRange($key);
            $fixed = max($min, min($max, $val));
            if ($fixed !== $val) $clamped[] = $key . ' → ' . $fixed . ' (допустимо ' . $min . '–' . $max . ')';
            $kv[$key] = $fixed;
        }
        if (isset($kv['title_min'], $kv['title_max']) && $kv['title_min'] > $kv['title_max']) $kv['title_min'] = $kv['title_max'];
        if (isset($kv['report_min'], $kv['report_max']) && $kv['report_min'] > $kv['report_max']) $kv['report_min'] = $kv['report_max'];
        if (isset($kv['search_min'], $kv['search_max']) && $kv['search_min'] > $kv['search_max']) $kv['search_min'] = $kv['search_max'];
    }

    try {
        $changed = settingsSet($kv);
    } catch (Throwable $e) {
        setFlashMessage('error', 'Не удалось сохранить настройки (изменения откатаны). Попробуйте снова.');
        redirect('/admin/settings.php?tab=' . $tab);
    }

    $clampNote = $clamped ? ' Значения вне диапазона скорректированы: ' . implode('; ', $clamped) . '.' : '';
    if ($changed) {
        $labelsMap = settingLabels();
        $changedLabels = array_map(fn($k) => $labelsMap[$k] ?? $k, $changed);
        logModeration('settings_update', 'system', 0, implode(', ', $changedLabels));
        setFlashMessage($clamped ? 'info' : 'success', 'Настройки сохранены.' . $clampNote);
    } else {
        setFlashMessage('info', 'Изменений нет.' . $clampNote);
    }
    redirect('/admin/settings.php?tab=' . $tab);
}

$labels = [
    'title_min' => ['Заголовок темы: минимум', 'символов'],
    'title_max' => ['Заголовок темы: максимум', 'символов (лимит БД — 200)'],
    'topic_min' => ['Первое сообщение темы: минимум', 'символов'],
    'reply_min' => ['Ответ: минимум', 'символов'],
    'message_max' => ['Сообщение: максимум', 'символов (колонки MEDIUMTEXT; проверьте post_max_size в php.ini)'],
    'report_min' => ['Жалоба: минимум', 'символов'],
    'report_max' => ['Жалоба: максимум', 'символов (лимит БД — 500)'],
    'password_min' => ['Пароль: минимум', 'символов'],
    'bio_max' => ['«О себе»: максимум', 'символов'],
    'location_max' => ['Город: максимум', 'символов (лимит БД — 100)'],
    'website_max' => ['Сайт: максимум', 'символов (лимит БД — 255)'],
    'signature_max' => ['Подпись: максимум', 'символов (лимит БД — 500)'],
    'search_min' => ['Поисковый запрос: минимум', 'символов'],
    'search_max' => ['Поисковый запрос: максимум', 'символов (лимит БД — 200)'],
    'edit_window_hours' => ['Окно редактирования автором', 'часов; 0 — только сразу, модератор редактирует всегда'],
    'per_page_topics' => ['Тем в разделе', 'на странице'],
    'per_page_posts' => ['Постов в теме', 'на странице (также профиль и переход после ответа)'],
    'per_page_search' => ['Результатов поиска', 'на странице'],
    'per_page_members' => ['Участников', 'на странице'],
    'per_page_notifications' => ['Уведомлений', 'на странице'],
    'per_page_autocomplete' => ['Автодополнение @имён', 'вариантов'],
    'per_page_staff' => ['Внутренние страницы модерации/админки', 'элементов (жалобы, журнал, пользователи)'],
    'attach_max_files' => ['Вложений на сообщение', 'файлов; 0 — загрузка запрещена'],
    'attach_max_size_mb' => ['Размер вложения', 'МБ на файл (не выше upload_max_filesize в php.ini)'],
    'avatar_max_size_mb' => ['Размер аватара', 'МБ'],
    'rl_login_max' => ['Вход: попыток', 'за окно (по IP)'],
    'rl_login_window' => ['Вход: окно', 'секунд'],
    'rl_register_max' => ['Регистрация: попыток', 'за окно (по IP)'],
    'rl_register_window' => ['Регистрация: окно', 'секунд'],
    'rl_topic_max' => ['Создание тем: штук', 'за окно (по участнику)'],
    'rl_topic_window' => ['Создание тем: окно', 'секунд'],
    'rl_reply_max' => ['Ответы: штук', 'за окно (по участнику)'],
    'rl_reply_window' => ['Ответы: окно', 'секунд'],
    'rl_report_max' => ['Жалобы: штук', 'за окно (по участнику)'],
    'rl_report_window' => ['Жалобы: окно', 'секунд'],
    'rl_edit_max' => ['Правки сообщений: штук', 'за окно (по участнику, антифлуд ревизиями)'],
    'rl_edit_window' => ['Правки сообщений: окно', 'секунд'],
    'online_window' => ['Окно «онлайн»', 'секунд (участники и гости)'],
    'rl_forgot_max' => ['Восстановление пароля: запросов', 'за окно (по IP)'],
    'rl_forgot_window' => ['Восстановление пароля: окно', 'секунд'],
];

require_once __DIR__ . '/../includes/header.php';
adminOpen('general');
?>

<div class="page-header"><h1>Настройки</h1></div>

<div class="admin-tabs">
    <?php foreach ($tabs as $k => $label): ?>
        <a href="/admin/settings.php?tab=<?= $k ?>" class="admin-tab <?= $k === $tab ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<form method="POST" action="/admin/settings.php?tab=<?= $tab ?>" class="admin-form">
    <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">

    <?php if ($tab === 'general'): ?>
        <div class="admin-card">
            <h3>Доступ</h3>
            <label class="admin-check">
                <input type="checkbox" name="registration_enabled" value="1" <?= setting('registration_enabled', true) ? 'checked' : '' ?>>
                <span>Регистрация новых участников разрешена</span>
            </label>
            <label class="admin-check">
                <input type="checkbox" name="forum_closed" value="1" <?= setting('forum_closed', false) ? 'checked' : '' ?>>
                <span>Закрыть форум (чтение для всех, новые темы и ответы — только модерация)</span>
            </label>
            <label class="admin-check">
                <input type="checkbox" name="email_notifications" value="1" <?= setting('email_notifications', false) ? 'checked' : '' ?>>
                <span>Email-уведомления об ответах и упоминаниях (доставка через cron: install/cron.php)</span>
            </label>
            <div class="form-group">
                <label for="closedMsg">Сообщение о закрытии</label>
                <input type="text" id="closedMsg" name="forum_closed_message" class="form-control" maxlength="255"
                       value="<?= escape(setting('forum_closed_message', 'Форум временно закрыт для новых сообщений')) ?>">
                <small class="form-hint">Показывается вместо формы ответа и при попытке создать тему. До 255 символов.</small>
            </div>
        </div>
    <?php else: ?>
        <?php if ($tab === 'uploads'): require_once __DIR__ . '/../includes/attachments.php'; ?>
            <div class="admin-card">
                <h3>Разрешённые типы вложений</h3>
                <div class="admin-types">
                    <?php $enabledGroups = attachEnabledGroups(); foreach (attachTypeCatalog() as $g => $def): ?>
                        <label class="admin-check admin-type-item">
                            <input type="checkbox" name="attach_types[]" value="<?= $g ?>" <?= in_array($g, $enabledGroups, true) ? 'checked' : '' ?>>
                            <span><?= $def[2] ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <small class="form-hint">Список accept и подсказка в формах генерируются из этих флажков. SVG/HTML/PHP запрещены всегда.</small>
            </div>
        <?php endif; ?>
        <div class="admin-card">
            <h3><?= $tabs[$tab] ?></h3>
            <div class="admin-grid">
                <?php foreach ($labels as $key => [$label, $hint]): ?>
                    <?php if (settingsDefaults()[$key][2] !== $tab) continue; [$min, $max] = settingsRange($key); ?>
                    <div class="form-group">
                        <label for="set_<?= $key ?>"><?= $label ?></label>
                        <input type="number" id="set_<?= $key ?>" name="<?= $key ?>" class="form-control"
                               min="<?= $min ?>" max="<?= $max ?>" step="1" required
                               value="<?= (int)setting($key) ?>">
                        <small class="form-hint"><?= $hint ?> · <?= $min ?>–<?= $max ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Сохранить</button>
    </div>
</form>

<?php
adminClose();
require_once __DIR__ . '/../includes/footer.php';