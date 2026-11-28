<?php
// includes/settings.php — единый источник настроек форума.
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [5.5] settingsSet() атомарен: вся пачка UPSERT в одной транзакции — сбой
//       посередине больше не оставляет полу-обновлённый конфиг (рассогласование
//       пар title_min/title_max и т.п.).
// [6.ranges] Диапазоны валидации переехали сюда (settingsRanges()) — единый
//       источник для админки; неизвестный int-ключ без диапазона не фаталит,
//       а получает безопасный [0, PHP_INT_MAX] и warning в лог.
// Кэш APCu/static — без изменений.

function settingsDefaults() {
    static $defaults = null;
    if ($defaults === null) {
        $defaults = [
            'forum_closed'         => ['bool', false, 'general'],
            'forum_closed_message' => ['string', 'Форум временно закрыт для новых сообщений', 'general'],
            'registration_enabled' => ['bool', true, 'general'],
            'email_notifications'  => ['bool', false, 'general'],
            'title_min'            => ['int', 5,     'content'],
            'title_max'            => ['int', 200,   'content'],
            'topic_min'            => ['int', 10,    'content'],
            'reply_min'            => ['int', 2,     'content'],
            'message_max'          => ['int', 20000, 'content'],
            'report_min'           => ['int', 5,     'content'],
            'report_max'           => ['int', 500,   'content'],
            'password_min'         => ['int', 6,     'content'],
            'bio_max'              => ['int', 1000,  'content'],
            'location_max'         => ['int', 100,   'content'],
            'website_max'          => ['int', 255,   'content'],
            'signature_max'        => ['int', 500,   'content'],
            'search_min'           => ['int', 2,     'content'],
            'search_max'           => ['int', 100,   'content'],
            'edit_window_hours'    => ['int', 24,    'content'],
            'per_page_topics'        => ['int', 15, 'pagination'],
            'per_page_posts'         => ['int', 15, 'pagination'],
            'per_page_search'        => ['int', 15, 'pagination'],
            'per_page_members'       => ['int', 15, 'pagination'],
            'per_page_notifications' => ['int', 15, 'pagination'],
            'per_page_autocomplete'  => ['int', 5,  'pagination'],
            'per_page_staff'         => ['int', 15, 'pagination'],
            'attach_max_files'   => ['int', 5, 'uploads'],
            'attach_max_size_mb' => ['int', 5, 'uploads'],
            'attach_types'       => ['json', ['jpg','png','gif','webp','pdf','txt','zip','doc','docx','xls','xlsx','mp3','mp4'], 'uploads'],
            'avatar_max_size_mb' => ['int', 2, 'uploads'],
            'rl_login_max'      => ['int', 5,    'limits'],
            'rl_login_window'   => ['int', 900,  'limits'],
            'rl_register_max'   => ['int', 3,    'limits'],
            'rl_register_window'=> ['int', 3600, 'limits'],
            'rl_topic_max'      => ['int', 5,    'limits'],
            'rl_topic_window'   => ['int', 600,  'limits'],
            'rl_reply_max'      => ['int', 10,   'limits'],
            'rl_reply_window'   => ['int', 300,  'limits'],
            'rl_report_max'     => ['int', 5,    'limits'],
            'rl_report_window'  => ['int', 3600, 'limits'],
            'rl_edit_max'       => ['int', 20,   'limits'],
            'rl_edit_window'    => ['int', 600,  'limits'],
            'online_window'     => ['int', 900,  'limits'],
            'rl_forgot_max'     => ['int', 3,    'limits'],
            'rl_forgot_window'  => ['int', 3600, 'limits'],
        ];
    }
    return $defaults;
}

/** Единые диапазоны числовых настроек: key => [min, max]. */
function settingsRanges() {
    static $ranges = null;
    if ($ranges === null) {
        $ranges = [
            'title_min' => [1, 200],   'title_max' => [5, 200],
            'topic_min' => [1, 20000], 'reply_min' => [1, 20000],
            'message_max' => [100, 100000],
            'report_min' => [1, 500],  'report_max' => [10, 500],
            'password_min' => [4, 128],
            'bio_max' => [50, 5000],   'location_max' => [10, 100],
            'website_max' => [20, 255], 'signature_max' => [20, 500],
            'search_min' => [1, 10],   'search_max' => [10, 200],
            'edit_window_hours' => [0, 8760],
            'per_page_topics' => [1, 100], 'per_page_posts' => [1, 100],
            'per_page_search' => [1, 100], 'per_page_members' => [1, 100],
            'per_page_notifications' => [1, 100], 'per_page_autocomplete' => [1, 20],
            'per_page_staff' => [1, 100],
            'attach_max_files' => [0, 20], 'attach_max_size_mb' => [1, 64],
            'avatar_max_size_mb' => [1, 16],
            'rl_login_max' => [1, 100],    'rl_login_window' => [60, 86400],
            'rl_register_max' => [1, 100], 'rl_register_window' => [60, 86400],
            'rl_topic_max' => [1, 100],    'rl_topic_window' => [60, 86400],
            'rl_reply_max' => [1, 100],    'rl_reply_window' => [60, 86400],
            'rl_report_max' => [1, 100],   'rl_report_window' => [60, 86400],
            'rl_edit_max' => [1, 200],     'rl_edit_window' => [60, 86400],
            'online_window' => [60, 7200],
            'rl_forgot_max' => [1, 20], 'rl_forgot_window' => [60, 86400],
        ];
    }
    return $ranges;
}

function settingsRange($key) {
    $r = settingsRanges();
    if (isset($r[$key])) return $r[$key];
    error_log('settingsRange: no range for key ' . $key);
    return [0, PHP_INT_MAX];
}

function settingsApcuAvailable() {
    return function_exists('apcu_fetch') && function_exists('apcu_store')
        && (!function_exists('apcu_enabled') || apcu_enabled());
}

function settingsAll() {
    static $cache = null;
    if (!empty($GLOBALS['__settings_dirty'])) {
        $cache = null;
        unset($GLOBALS['__settings_dirty']);
        if (settingsApcuAvailable()) apcu_delete('forum_settings_all');
    }
    if ($cache !== null) return $cache;

    if (settingsApcuAvailable()) {
        $hit = apcu_fetch('forum_settings_all', $ok);
        if ($ok && is_array($hit)) return $cache = $hit;
    }

    $cache = [];
    foreach (settingsDefaults() as $key => $def) $cache[$key] = $def[1];
    try {
        $db = Database::getInstance()->getConnection();
        foreach ($db->query('SELECT `key`, `value`, `type` FROM settings') as $row) {
            $cache[$row['key']] = settingsCast($row['value'], $row['type']);
        }
    } catch (Throwable $e) {
        error_log('settingsAll: ' . $e->getMessage());
    }

    if (settingsApcuAvailable()) apcu_store('forum_settings_all', $cache, 60);
    return $cache;
}

function settingsCast($value, $type) {
    switch ($type) {
        case 'int':  return (int)$value;
        case 'bool': return (bool)(int)$value;
        case 'json': return json_decode($value, true) ?: [];
        default:     return (string)$value;
    }
}

function setting($key, $default = null) {
    $all = settingsAll();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function settingsCacheReset() {
    $GLOBALS['__settings_dirty'] = true;
    if (settingsApcuAvailable()) apcu_delete('forum_settings_all');
}

/**
 * Атомарная запись пачки настроек. Любая ошибка — полный rollback,
 * конфиг никогда не остаётся полу-обновлённым.
 */
function settingsSet(array $kv) {
    $db = Database::getInstance()->getConnection();
    $defs = settingsDefaults();
    $old = settingsAll();
    $changed = [];
    $stmt = $db->prepare('
        INSERT INTO settings (`key`, `value`, `type`, `group`) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ');

    $db->beginTransaction();
    try {
        foreach ($kv as $key => $value) {
            if (!isset($defs[$key])) continue;
            [$type, , $group] = $defs[$key];
            if ($type === 'bool') $value = $value ? '1' : '0';
            elseif ($type === 'int') $value = (string)(int)$value;
            elseif ($type === 'json') $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            else $value = (string)$value;

            if ($type === 'bool') $oldNorm = (int)$old[$key] ? '1' : '0';
            elseif ($type === 'json') $oldNorm = json_encode($old[$key], JSON_UNESCAPED_UNICODE);
            else $oldNorm = (string)$old[$key];
            if ($oldNorm === $value) continue;

            $stmt->execute([$key, $value, $type, $group]);
            $changed[] = $key;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('settingsSet: ' . $e->getMessage());
        throw $e;
    }
    if ($changed) settingsCacheReset();
    return $changed;
}