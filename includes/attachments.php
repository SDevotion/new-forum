<?php
// includes/attachments.php
// Вложения к темам и сообщениям: загрузка, вывод, удаление.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/permissions.php';

define('ATTACH_MAX_FILES', 5);              // максимум файлов на одно сообщение
define('ATTACH_MAX_SIZE', 5 * 1024 * 1024); // 5 МБ на файл
define('ATTACH_DIR', __DIR__ . '/../assets/uploads/attachments');
define('ATTACH_URL_BASE', '/attachment.php?id=');

/**
 * Whitelist: реальный MIME (finfo) -> расширение. SVG/HTML/PHP сознательно запрещены.
 */
function attachAllowedTypes() {
    return [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
        'text/plain'      => 'txt',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'audio/mpeg'      => 'mp3',
        'video/mp4'       => 'mp4',
    ];
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
 */
function attachValidate(array &$files) {
    $errors = [];
    if (count($files) > ATTACH_MAX_FILES) {
        $errors[] = 'Можно прикрепить не более ' . ATTACH_MAX_FILES . ' файлов';
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
        if ($f['size'] <= 0 || $f['size'] > ATTACH_MAX_SIZE) {
            $errors[] = '«' . $label . '»: размер должен быть от 1 байта до ' . (int)(ATTACH_MAX_SIZE / 1024 / 1024) . ' МБ';
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
            $html .= '<a href="' . $url . '" target="_blank" rel="noopener" class="attach-image-link" title="' . escape($r['orig_name']) . '">'
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
                   . '</a>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}