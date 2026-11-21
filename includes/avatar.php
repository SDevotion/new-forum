<?php
/**
 * URL аватара пользователя
 */
function avatarUrl($path) {
    if (!empty($path) && is_file(__DIR__ . '/../' . ltrim($path, '/'))) {
        return escape($path);
    }
    return '/assets/images/default-avatar.svg';
}

/**
 * Перекодирование изображения через GD: убирает EXIF/GPS и любые
 * не-пиксельные данные. Возвращает путь к очищенному файлу или null.
 */
function avatarReencode($srcPath, $mime, $dstPath) {
    if (!function_exists('imagecreatefromstring')) {
        error_log('avatarReencode: GD недоступен, EXIF не вычищается');
        return null;
    }
    $data = @file_get_contents($srcPath);
    if ($data === false) return null;
    $img = @imagecreatefromstring($data);
    if ($img === false) return null;

    // Поворот по EXIF Orientation ДО его удаления (JPEG с телефонов)
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($srcPath);
        if (!empty($exif['Orientation'])) {
            switch ((int)$exif['Orientation']) {
                case 3: $img = imagerotate($img, 180, 0); break;
                case 6: $img = imagerotate($img, -90, 0); break;
                case 8: $img = imagerotate($img, 90, 0); break;
            }
        }
    }

    imagesavealpha($img, true);
    imagealphablending($img, false);

    $ok = false;
    switch ($mime) {
        case 'image/jpeg': $ok = imagejpeg($img, $dstPath, 88); break;
        case 'image/png':  $ok = imagepng($img, $dstPath, 8); break;
        case 'image/gif':  $ok = imagegif($img, $dstPath); break;
        case 'image/webp': $ok = function_exists('imagewebp') ? imagewebp($img, $dstPath, 88) : imagepng($img, $dstPath, 8); break;
    }
    imagedestroy($img);
    return $ok ? $dstPath : null;
}

/**
 * Обработка загрузки аватара
 * @return array [success(bool), message(string), path(?string)]
 */
function handleAvatarUpload($file, $userId) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [true, '', null]; // файл не выбран — не ошибка
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Ошибка загрузки файла (код ' . (int)$file['error'] . ')', null];
    }

    $avatarMaxMb = max(1, setting('avatar_max_size_mb', 2));
    if ($file['size'] > $avatarMaxMb * 1024 * 1024) {
        return [false, 'Файл слишком большой. Максимум ' . $avatarMaxMb . ' МБ', null];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return [false, 'Некорректная загрузка файла', null];
    }

    // Реальный тип файла, а не расширение
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        return [false, 'Допустимы только JPG, PNG, GIF, WEBP', null];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return [false, 'Файл не является изображением', null];
    }
    // Защита от декомпрессионных бомб: не более 5000x5000 и 25 Мпикс
    if ($info[0] > 5000 || $info[1] > 5000 || ($info[0] * $info[1]) > 25000000) {
        return [false, 'Слишком большое разрешение (максимум 5000×5000)', null];
    }

    $dir = __DIR__ . '/../assets/uploads/avatars';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return [false, 'Не удалось создать папку для загрузок', null];
    }

    $ext = $allowed[$mime];
    $name = 'u' . (int)$userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target = $dir . '/' . $name;

    // Сначала перекодируем во временный файл (чистые пиксели, без EXIF),
    // затем перемещаем. Если GD нет — fallback на исходный файл.
    $tmpClean = $dir . '/tmp_' . bin2hex(random_bytes(8));
    $clean = avatarReencode($file['tmp_name'], $mime, $tmpClean);

    if ($clean !== null) {
        if (!rename($tmpClean, $target)) {
            @unlink($tmpClean);
            return [false, 'Не удалось сохранить файл', null];
        }
        @unlink($file['tmp_name']);
    } else {
        @unlink($tmpClean);
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            return [false, 'Не удалось сохранить файл', null];
        }
    }

    return [true, '', '/assets/uploads/avatars/' . $name];
}

/**
 * Удаление старого файла аватара
 */
function deleteAvatarFile($path) {
    if (empty($path)) return;
    if (strpos($path, '/assets/uploads/avatars/') !== 0) return;

    $full = __DIR__ . '/../' . ltrim($path, '/');
    if (is_file($full)) {
        @unlink($full);
    }
}
