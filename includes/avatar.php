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

    if (@getimagesize($file['tmp_name']) === false) {
        return [false, 'Файл не является изображением', null];
    }

    $dir = __DIR__ . '/../assets/uploads/avatars';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return [false, 'Не удалось создать папку для загрузок', null];
    }

    $name = 'u' . (int)$userId . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $target = $dir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return [false, 'Не удалось сохранить файл', null];
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