<?php
// includes/avatar.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [2.3] Единый строгий regex пути аватара для avatarUrl()/deleteAvatarFile():
//       /assets/uploads/avatars/u{id}_{16hex}.{jpg|png|gif|webp} — разные правила
//       валидации (is_file vs префикс) устранены; произвольная строка из БД
//       никогда не попадёт в src.
// [6.avatarReencode] Лимит декомпрессионной бомбы снижен: 4000×4000 и 8 Мпикс
//       (для аватара достаточно; ~32 МБ RAM worst case вместо ~200 МБ).
// [new] tmp-файл перекодирования получает расширение .tmp и гарантированно
//       удаляется при любом исходе (try/finally-паттерн).
// EXIF-поворот и fail-closed без GD — сохранены.

/** Строгий формат пути аватара. */
function avatarPathValid($path) {
    return is_string($path)
        && preg_match('#^/assets/uploads/avatars/u\d+_[a-f0-9]{16}\.(jpg|png|gif|webp)$#', $path) === 1;
}

function avatarUrl($path) {
    if (avatarPathValid($path) && is_file(__DIR__ . '/../' . ltrim($path, '/'))) {
        return escape($path);
    }
    return '/assets/images/default-avatar.svg';
}

function avatarReencode($srcPath, $mime, $dstPath) {
    if (!function_exists('imagecreatefromstring')) {
        error_log('avatarReencode: GD недоступен');
        return null;
    }
    $data = @file_get_contents($srcPath);
    if ($data === false) return null;
    $img = @imagecreatefromstring($data);
    unset($data);
    if ($img === false) return null;

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
 * @return array [success(bool), message(string), path(?string)]
 */
function handleAvatarUpload($file, $userId) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [true, '', null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Ошибка загрузки файла (код ' . (int)$file['error'] . ')', null];
    }

    $avatarMaxMb = max(1, setting('avatar_max_size_mb', 2));
    if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        return [false, 'Некорректная загрузка файла', null];
    }
    $realSize = @filesize($file['tmp_name']);
    if ($realSize === false || $realSize <= 0 || $realSize > $avatarMaxMb * 1024 * 1024) {
        return [false, 'Файл слишком большой. Максимум ' . $avatarMaxMb . ' МБ', null];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        return [false, 'Допустимы только JPG, PNG, GIF, WEBP', null];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return [false, 'Файл не является изображением', null];
    }
    if ($info[0] > 4000 || $info[1] > 4000 || ($info[0] * $info[1]) > 8000000) {
        return [false, 'Слишком большое разрешение (максимум 4000×4000, 8 Мпикс)', null];
    }

    if (!function_exists('imagecreatefromstring')) {
        return [false, 'Загрузка аватаров временно недоступна (нет GD на сервере)', null];
    }

    $dir = __DIR__ . '/../assets/uploads/avatars';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return [false, 'Не удалось создать папку для загрузок', null];
    }

    $ext = $allowed[$mime];
    $name = 'u' . (int)$userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target = $dir . '/' . $name;
    $tmpClean = $dir . '/tmp_' . bin2hex(random_bytes(8)) . '.tmp';

    try {
        $clean = avatarReencode($file['tmp_name'], $mime, $tmpClean);
        if ($clean === null) {
            return [false, 'Не удалось обработать изображение. Попробуйте другой файл.', null];
        }
        if (!rename($tmpClean, $target)) {
            return [false, 'Не удалось сохранить файл', null];
        }
    } finally {
        if (is_file($tmpClean)) @unlink($tmpClean);
    }
    @unlink($file['tmp_name']);

    return [true, '', '/assets/uploads/avatars/' . $name];
}

function deleteAvatarFile($path) {
    if (!avatarPathValid($path)) return;
    $full = __DIR__ . '/../' . ltrim($path, '/');
    if (is_file($full)) @unlink($full);
}