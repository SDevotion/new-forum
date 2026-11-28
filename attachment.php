<?php
// attachment.php — выдача вложений с проверкой прав видимости.
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [2.4] Range: множественные диапазоны (bytes=a-b,c-d) корректно отклоняются 416
//       вместо тихой отдачи всего файла; переполнение чисел исключено (ctype_digit
//       + кап длины); bytes=-0 и start>=size дают честный 416.
// [2.4] Счётчик скачиваний: считается ТОЛЬКО полная GET-загрузка не-картинки
//       (или первый Range-запрос файла), превью картинок в списках больше не
//       дёргают UPDATE на каждый показ; для авторизованных отметка живёт в APCu
//       (не раздувает сессионный payload и не сериализует лок на каждую картинку).
// [2.5] Content-Disposition: ASCII-filename строго фильтруется (без ; и ctrl-символов),
//       UTF-8 имя — только через filename*=rawurlencode. Инъекция заголовка невозможна.
// [new] HEAD не увеличивает счётчик; отдача через fpassthru-чанки с flush и
//       проверкой connection_aborted сохранена.
// ACL/ETag/CSP-sandbox — без изменений (были корректны).

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/visibility.php';

$id = (int)($_GET['id'] ?? 0);
$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT * FROM attachments WHERE id = ?");
$stmt->execute([$id]);
$att = $stmt->fetch();
if (!$att) { http_response_code(404); exit('Файл не найден'); }

$visible = $att['target_type'] === 'topic'
    ? topicVisible((int)$att['target_id'])
    : postVisible((int)$att['target_id']);
if (!$visible) { http_response_code(404); exit('Файл не найден'); }

if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $att['stored_name'])) {
    http_response_code(404); exit('Файл не найден');
}
$path = __DIR__ . '/assets/uploads/attachments/' . $att['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('Файл отсутствует'); }

$size = filesize($path);
$mtime = filemtime($path);
$etag = '"' . $att['stored_name'] . '-' . $size . '-' . $mtime . '"';

$isImage = (bool)$att['is_image'];
$mime = $isImage ? $att['mime'] : 'application/octet-stream';

// RFC 6266: ASCII fallback без управляющих символов, кавычек и ';'.
$asciiName = preg_replace('/[^\x20-\x7E]/', '_', (string)$att['orig_name']);
$asciiName = str_replace(['"', ';', '\\'], '_', $asciiName);
$asciiName = trim($asciiName);
if ($asciiName === '') $asciiName = 'file.' . pathinfo($att['stored_name'], PATHINFO_EXTENSION);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($isImage ? 'inline' : 'attachment')
     . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode((string)$att['orig_name']));
header('Cache-Control: private, max-age=3600, must-revalidate');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Accept-Ranges: bytes');
header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; media-src 'self'");

$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
if ($ifNoneMatch !== '') {
    // Поддержка списка ETag и W/ префикса
    $tags = array_map('trim', explode(',', $ifNoneMatch));
    foreach ($tags as $t) {
        if ($t === '*' || $t === $etag || $t === 'W/' . $etag) { http_response_code(304); exit; }
    }
} elseif ($ifModifiedSince && @strtotime($ifModifiedSince) >= $mtime) {
    http_response_code(304);
    exit;
}

$start = 0;
$end = $size - 1;
$isRange = false;
$rangeHeader = trim($_SERVER['HTTP_RANGE'] ?? '');

if ($rangeHeader !== '') {
    // Множественные диапазоны не поддерживаем — честный 416 (RFC: сервер вправе игнорировать,
    // но тогда НЕ должен слать 206; мы выбираем явный отказ, плееры ретраят без Range).
    if (!preg_match('/^bytes=(\d{0,15})-(\d{0,15})$/', $rangeHeader, $m) || strpos($rangeHeader, ',') !== false) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    if ($m[1] === '' && $m[2] === '') {
        http_response_code(416); header('Content-Range: bytes */' . $size); exit;
    }
    if ($m[1] === '') {
        $suffix = (int)$m[2];
        if ($suffix === 0) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
        $start = max(0, $size - $suffix);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $isRange = true;
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

$length = $end - $start + 1;
header('Content-Length: ' . $length);

// Счётчик: полный GET не-картинки, либо первый Range-запрос (стартовая позиция 0).
// Превью картинок (обычный GET image/*) не считаются скачиванием.
$isHead = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
$countable = !$isHead && ((!$isImage && !$isRange) || ($isRange && $start === 0));
if ($countable) {
    $uid = currentUserId();
    $seen = false;
    if ($uid > 0 && function_exists('apcu_fetch') && (!function_exists('apcu_enabled') || apcu_enabled())) {
        $k = 'att_dl:' . $uid . ':' . $id;
        $hit = apcu_fetch($k, $ok);
        if ($ok && $hit) { $seen = true; }
        else { apcu_store($k, 1, 3600); }
    } else {
        $seen = !sessionMarkOnce('attach_dl', $id);
    }
    if (!$seen) {
        $db->prepare("UPDATE attachments SET downloads = downloads + 1 WHERE id = ?")->execute([$id]);
    }
}

if ($isHead) exit;

$fp = fopen($path, 'rb');
if ($fp === false) { http_response_code(500); exit('Ошибка чтения файла'); }
if ($start > 0) fseek($fp, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fp)) {
    $chunk = fread($fp, min(8192, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) break;
    flush();
}
fclose($fp);
exit;