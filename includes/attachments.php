<?php
// includes/attachments.php
// Вложения к темам и сообщениям: загрузка, вывод, удаление.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/permissions.php';

define('ATTACH_DIR', __DIR__ . '/../assets/uploads/attachments');
define('ATTACH_URL_BASE', '/attachment.php?id=');

function attachMaxFiles() { return max(0, setting('attach_max_files', 5)); }
function attachMaxSize()  { return max(1, setting('attach_max_size_mb', 5)) * 1024 * 1024; }

/**
 * Полный каталог типов: группа => [mime..., расширения для accept, подпись].
 * SVG/HTML/PHP сознательно отсутствуют.
 */
function attachTypeCatalog() {
    return [
        'jpg'  => [['image/jpeg'], '.jpg,.jpeg', 'JPG'],
        'png'  => [['image/png'], '.png', 'PNG'],
        'gif'  => [['image/gif'], '.gif', 'GIF'],
        'webp' => [['image/webp'], '.webp', 'WEBP'],
        'pdf'  => [['application/pdf'], '.pdf', 'PDF'],
        'txt'  => [['text/plain'], '.txt', 'TXT'],
        'zip'  => [['application/zip', 'application/x-zip-compressed'], '.zip', 'ZIP'],
        'doc'  => [['application/msword'], '.doc', 'DOC'],
        'docx' => [['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], '.docx', 'DOCX'],
        'xls'  => [['application/vnd.ms-excel'], '.xls', 'XLS'],
        'xlsx' => [['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], '.xlsx', 'XLSX'],
        'mp3'  => [['audio/mpeg'], '.mp3', 'MP3'],
        'mp4'  => [['video/mp4'], '.mp4', 'MP4'],
    ];
}

/**
 * Разрешённые группы типов (настройка attach_types). Пустой список = все запрещены.
 */
function attachEnabledGroups() {
    $enabled = setting('attach_types', array_keys(attachTypeCatalog()));
    if (!is_array($enabled)) $enabled = [];
    return array_values(array_intersect(array_keys(attachTypeCatalog()), $enabled));
}

/**
 * Whitelist: реальный MIME (finfo) -> расширение, с учётом настройки.
 */
function attachAllowedTypes() {
    $map = [];
    $catalog = attachTypeCatalog();
    foreach (attachEnabledGroups() as $group) {
        foreach ($catalog[$group][0] as $mime) $map[$mime] = $group;
    }
    return $map;
}

/**
 * accept-атрибут и человекочитаемая подпись — из того же каталога.
 */
function attachAcceptAttr() {
    $parts = [];
    $catalog = attachTypeCatalog();
    foreach (attachEnabledGroups() as $g) $parts[] = $catalog[$g][1];
    return implode(',', $parts);
}

function attachTypesLabel() {
    $parts = [];
    $catalog = attachTypeCatalog();
    foreach (attachEnabledGroups() as $g) $parts[] = $catalog[$g][2];
    return $parts ? implode(', ', $parts) : 'загрузка отключена';
}

function attachIsImageMime($mime) {
    return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
}

/**
 * Нормализация $_FILES['attachments'] (multiple) в плоский список.
 */
function attachNormalizeFiles($field = 'attachments') {
    $out = [];
    if (empty($_FILES[$field])) return $out;
    if (!is_array($_FILES[$field]['name'])) {
        if (($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $out[] = $_FILES[$field];
        }
        return $out;
    }
    $n = count($_FILES[$field]['name']);
    for ($i = 0; $i < $n; $i++) {
        if ($_FILES[$field]['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $out[] = [
            'name'     => $_FILES[$field]['name'][$i],
            'type'     => $_FILES[$field]['type'][$i],
            'tmp_name' => $_FILES[$field]['tmp_name'][$i],
            'error'    => $_FILES[$field]['error'][$i],
            'size'     => $_FILES[$field]['size'][$i],
        ];
    }
    return $out;
}

/**
 * Валидация списка файлов ДО сохранения. Возвращает массив ошибок (пустой = всё ок).
 * Проверенные файлы получают ключи 'ext' и 'mime'.
 * $existingCount — сколько вложений уже есть у объекта и останется после правки.
 */
function attachValidate(array &$files, $existingCount = 0) {
    $errors = [];
    $maxFiles = attachMaxFiles();
    if ($files && $maxFiles === 0) {
        $errors[] = 'Загрузка вложений отключена администратором';
        return $errors;
    }
    $slots = max(0, $maxFiles - (int)$existingCount);
    if (count($files) > $slots) {
        $errors[] = 'Можно прикрепить не более ' . $maxFiles . ' файлов на сообщение'
                  . ((int)$existingCount > 0 ? ' (уже прикреплено: ' . (int)$existingCount . ')' : '');
        return $errors;
    }
    $allowed = attachAllowedTypes();
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    foreach ($files as &$f) {
        $label = mb_strimwidth((string)$f['name'], 0, 60, '…');
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = '«' . $label . '»: ошибка загрузки (код ' . (int)$f['error'] . ')';
            continue;
        }
        $maxSize = attachMaxSize();
        if ($f['size'] <= 0 || $f['size'] > $maxSize) {
            $errors[] = '«' . $label . '»: размер должен быть от 1 байта до ' . (int)($maxSize / 1024 / 1024) . ' МБ';
            continue;
        }
        if (!is_uploaded_file($f['tmp_name'])) {
            $errors[] = '«' . $label . '»: некорректная загрузка';
            continue;
        }
        $mime = $finfo->file($f['tmp_name']);
        if (!isset($allowed[$mime])) {
            $errors[] = '«' . $label . '»: тип файла запрещён';
            continue;
        }
        // Текстовые вложения — до 1 МБ: мегабайтные «txt» почти всегда мусор/абьюз
        if ($mime === 'text/plain' && $f['size'] > 1048576) {
            $errors[] = '«' . $label . '»: текстовый файл не должен превышать 1 МБ';
            continue;
        }
        if (attachIsImageMime($mime) && @getimagesize($f['tmp_name']) === false) {
            $errors[] = '«' . $label . '»: файл не является изображением';
            continue;
        }
        $f['mime'] = $mime;
        $f['ext']  = $allowed[$mime];
    }
    unset($f);
    return $errors;
}

/**
 * Сохранение провалидированных файлов и запись строк в БД.
 * Вызывать ПОСЛЕ успешного INSERT темы/поста. Бросает RuntimeException при ошибке.
 */
function attachStore(array $files, $targetType, $targetId, $userId) {
    if (!$files) return;
    if (!is_dir(ATTACH_DIR) && !mkdir(ATTACH_DIR, 0755, true)) {
        throw new RuntimeException('Не удалось создать папку вложений');
    }
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        INSERT INTO attachments (target_type, target_id, user_id, orig_name, stored_name, mime, size_bytes, is_image)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($files as $f) {
        if (empty($f['ext'])) continue; // не прошёл валидацию — пропускаем
        $stored = bin2hex(random_bytes(16)) . '.' . $f['ext'];
        if (!move_uploaded_file($f['tmp_name'], ATTACH_DIR . '/' . $stored)) {
            throw new RuntimeException('Не удалось сохранить файл на диск');
        }
        $orig = mb_substr(basename(str_replace('\\', '/', (string)$f['name'])), 0, 255);
        $stmt->execute([$targetType, $targetId, $userId, $orig, $stored, $f['mime'], (int)$f['size'], attachIsImageMime($f['mime']) ? 1 : 0]);
    }
}

/**
 * Вложения одного объекта.
 */
function attachForObject($targetType, $targetId) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM attachments WHERE target_type = ? AND target_id = ? ORDER BY id ASC");
    $stmt->execute([$targetType, $targetId]);
    return $stmt->fetchAll();
}

/**
 * Вложения сразу для нескольких объектов: [target_id => [rows...]] (без N+1).
 */
function attachForTargets($targetType, array $ids) {
    if (!$ids) return [];
    $db = Database::getInstance()->getConnection();
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT * FROM attachments WHERE target_type = ? AND target_id IN ($in) ORDER BY id ASC");
    $stmt->execute(array_merge([$targetType], $ids));
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['target_id']][] = $row;
    }
    return $map;
}

/**
 * Имена файлов всех вложений темы (первое сообщение + ответы). Для удаления с диска ПОСЛЕ DELETE.
 */
function attachCollectTopicTree($topicId) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT a.stored_name FROM attachments a
        WHERE (a.target_type = 'topic' AND a.target_id = ?)
           OR (a.target_type = 'post' AND a.target_id IN (SELECT id FROM posts WHERE topic_id = ?))
    ");
    $stmt->execute([$topicId, $topicId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Удаление строк вложений темы (до DELETE темы, пока живы посты для подзапроса).
 */
function attachPurgeTopicTree($topicId) {
    $db = Database::getInstance()->getConnection();
    $db->prepare("
        DELETE a FROM attachments a
        LEFT JOIN posts p ON a.target_type = 'post' AND a.target_id = p.id
        WHERE (a.target_type = 'topic' AND a.target_id = ?) OR p.topic_id = ?
    ")->execute([$topicId, $topicId]);
}

function attachUnlink($storedName) {
    // только наше имя формата hex.ext — защита от path traversal
    if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', (string)$storedName)) return;
    $full = ATTACH_DIR . '/' . $storedName;
    if (is_file($full)) @unlink($full);
}

/**
 * Человекочитаемый размер.
 */
function attachFormatSize($bytes) {
    $bytes = (int)$bytes;
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' МБ';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' КБ';
    return $bytes . ' Б';
}

/**
 * HTML-блок вложений под сообщением. Картинки — сеткой превью, файлы — списком.
 * Все ссылки ведут на контроллер /attachment.php (проверка прав при скачивании).
 */
function attachRender(array $rows) {
    if (!$rows) return '';
    $images = array_filter($rows, fn($r) => $r['is_image']);
    $files  = array_filter($rows, fn($r) => !$r['is_image']);
    $html = '<div class="post-attachments">';
    if ($images) {
        $html .= '<div class="attach-images">';
        foreach ($images as $r) {
            $url = ATTACH_URL_BASE . (int)$r['id'];
            $html .= '<a href="' . $url . '" target="_blank" rel="noopener" class="attach-image-link" title="' . escape($r['orig_name']) . ' · скачиваний: ' . (int)$r['downloads'] . '">'
                   . '<img src="' . $url . '" alt="' . escape($r['orig_name']) . '" class="attach-image" loading="lazy">'
                   . '</a>';
        }
        $html .= '</div>';
    }
    if ($files) {
        $html .= '<div class="attach-files">';
        foreach ($files as $r) {
            $html .= '<a href="' . ATTACH_URL_BASE . (int)$r['id'] . '" class="attach-file">'
                   . '<span class="attach-file-icon">📎</span>'
                   . '<span class="attach-file-name">' . escape($r['orig_name']) . '</span>'
                   . '<span class="attach-file-size">' . attachFormatSize($r['size_bytes']) . '</span>'
                   . '<span class="attach-file-dl" title="Скачиваний">⬇ ' . (int)$r['downloads'] . '</span>'
                   . '</a>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}
