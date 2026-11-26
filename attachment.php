<?php
// attachment.php — выдача вложений с проверкой прав видимости.
// Поддержка ETag/Last-Modified/Range (перемотка видео, докачка).
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/visibility.php';

$id = (int)($_GET['id'] ?? 0);
$db = Database::getInstance()->getConnection();

$stmt = $db->prepare("SELECT * FROM attachments WHERE id = ?");
$stmt->execute([$id]);
$att = $stmt->fetch();
if (!$att) { http_response_code(404); exit('Файл не найден'); }

// Видимость родителя — единые правила из visibility.php
$visible = $att['target_type'] === 'topic'
    ? topicVisible((int)$att['target_id'])
    : postVisible((int)$att['target_id']);
if (!$visible) { http_response_code(404); exit('Файл не найден'); }

// Файл на диске: имя строго нашего формата
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
$downloadName = str_replace(['"', "\r", "\n"], '', $att['orig_name']);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . ($isImage ? 'inline' : 'attachment') . '; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . rawurlencode($att['orig_name']));
header('Cache-Control: private, max-age=3600, must-revalidate');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Accept-Ranges: bytes');
// Sandbox для всех типов: polyglot-картинка не сможет выполнить активное содержимое
header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; media-src 'self'");

$ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
$ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
// По RFC 7232: если есть If-None-Match, If-Modified-Since игнорируется
if ($ifNoneMatch !== '') {
    if (trim($ifNoneMatch) === $etag) { http_response_code(304); exit; }
} elseif ($ifModifiedSince && @strtotime($ifModifiedSince) >= $mtime) {
    http_response_code(304);
    exit;
}

$start = 0;
$end = $size - 1;
$isRange = false;
if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
    if ($m[1] === '' && $m[2] !== '') {
        $start = max(0, $size - (int)$m[2]);
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

if (!$isRange && sessionMarkOnce('attach_dl', $id)) {
    $db->prepare("UPDATE attachments SET downloads = downloads + 1 WHERE id = ?")->execute([$id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

$fp = fopen($path, 'rb');
if ($fp === false) { http_response_code(500); exit('Ошибка чтения файла'); }
if ($start > 0) fseek($fp, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fp)) {
    $chunk = fread($fp, min(8192, $remaining));
    if ($chunk === false) break;
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) break;
}
fclose($fp);
exit;
