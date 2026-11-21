<?php
// includes/settings.php — единый источник настроек форума.
// Кэш: APCu (между запросами, TTL 60с) -> static (в рамках запроса) -> дефолты.

function settingsDefaults() {
    static $defaults = null;
    if ($defaults === null) {
        $defaults = [
            // general
            'forum_closed'         => ['bool', false, 'general'],
            'forum_closed_message' => ['string', 'Форум временно закрыт для новых сообщений', 'general'],
            'registration_enabled' => ['bool', true, 'general'],
            // content limits
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
            // pagination
            'per_page_topics'        => ['int', 15, 'pagination'],
            'per_page_posts'         => ['int', 15, 'pagination'],
            'per_page_search'        => ['int', 15, 'pagination'],
            'per_page_members'       => ['int', 15, 'pagination'],
            'per_page_notifications' => ['int', 15, 'pagination'],
            'per_page_autocomplete'  => ['int', 5,  'pagination'],
            'per_page_staff'         => ['int', 15, 'pagination'],
            // uploads
            'attach_max_files'   => ['int', 5, 'uploads'],
            'attach_max_size_mb' => ['int', 5, 'uploads'],
            'attach_types'       => ['json', ['jpg','png','gif','webp','pdf','txt','zip','doc','docx','xls','xlsx','mp3','mp4'], 'uploads'],
            'avatar_max_size_mb' => ['int', 2, 'uploads'],
            // limits
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
        ];
    }
    return $defaults;
}

function settingsApcuAvailable() {
    return function_exists('apcu_fetch') && function_exists('apcu_store')
        && (!function_exists('apcu_enabled') || apcu_enabled());
}

/**
 * Все настройки: APCu -> static -> БД -> дефолты.
 */
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
        if ($ok && is_array($hit)) {
            return $cache = $hit;
        }
    }

    $cache = [];
    foreach (settingsDefaults() as $key => $def) {
        $cache[$key] = $def[1];
    }
    try {
        $db = Database::getInstance()->getConnection();
        foreach ($db->query("SELECT `key`, `value`, `type` FROM settings") as $row) {
            $cache[$row['key']] = settingsCast($row['value'], $row['type']);
        }
    } catch (Throwable $e) {
        error_log('settingsAll: ' . $e->getMessage());
    }

    if (settingsApcuAvailable()) {
        apcu_store('forum_settings_all', $cache, 60);
    }
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
    if (array_key_exists($key, $all)) return $all[$key];
    return $default;
}

function settingsCacheReset() {
    $GLOBALS['__settings_dirty'] = true;
    if (settingsApcuAvailable()) apcu_delete('forum_settings_all');
}

function settingsSet(array $kv) {
    $db = Database::getInstance()->getConnection();
    $defs = settingsDefaults();
    $old = settingsAll();
    $changed = [];
    $stmt = $db->prepare("
        INSERT INTO settings (`key`, `value`, `type`, `group`) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");
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
    if ($changed) settingsCacheReset();
    return $changed;
}
