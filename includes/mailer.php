<?php
// includes/mailer.php — отправка писем (mail() или простой SMTP).
require_once __DIR__ . '/functions.php';

/**
 * Отправка письма. Возвращает true/false; ошибки пишет в error_log.
 */
function sendMail($toEmail, $subject, $bodyText) {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

    $from = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@localhost';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Forum';
    $transport = defined('MAIL_TRANSPORT') ? MAIL_TRANSPORT : 'mail';

    $headers = [
        'From' => mailEncodeHeader($fromName) . ' <' . $from . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
    ];

    if ($transport === 'smtp') {
        return smtpSend($from, $toEmail, $subject, $bodyText, $headers);
    }

    $headerStr = '';
    foreach ($headers as $k => $v) $headerStr .= "$k: $v\r\n";
    $encodedSubject = mailEncodeHeader($subject);

    $ok = @mail($toEmail, $encodedSubject, $bodyText, $headerStr, '-f' . $from);
    if (!$ok) error_log("sendMail(mail) failed to $toEmail");
    return $ok;
}

function mailEncodeHeader($text) {
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * Минимальный SMTP-клиент (AUTH LOGIN, STARTTLS/SSL). Без внешних библиотек.
 */
function smtpSend($from, $to, $subject, $body, $headers) {
    $host = defined('SMTP_HOST') ? SMTP_HOST : 'localhost';
    $port = defined('SMTP_PORT') ? SMTP_PORT : 587;
    $user = defined('SMTP_USER') ? SMTP_USER : '';
    $pass = defined('SMTP_PASS') ? SMTP_PASS : '';
    $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';

    $prefix = ($secure === 'ssl') ? 'ssl://' : '';
    $fp = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
    if (!$fp) { error_log("SMTP connect failed: $errstr"); return false; }
    stream_set_timeout($fp, 10);

    $read = function () use ($fp) {
        $data = '';
        while ($line = fgets($fp, 512)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function ($c) use ($fp, $read) { fputs($fp, $c . "\r\n"); return $read(); };

    $read();
    $ehlo = $cmd('EHLO localhost');
    if ($secure === 'tls') {
        $cmd('STARTTLS');
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('SMTP STARTTLS failed'); fclose($fp); return false;
        }
        $cmd('EHLO localhost');
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($user));
        $resp = $cmd(base64_encode($pass));
        if (strpos($resp, '235') !== 0) { error_log('SMTP auth failed'); fclose($fp); return false; }
    }
    $cmd('MAIL FROM:<' . $from . '>');
    $cmd('RCPT TO:<' . $to . '>');
    $cmd('DATA');

    $msg = '';
    foreach ($headers as $k => $v) $msg .= "$k: $v\r\n";
    $msg .= 'To: <' . $to . ">\r\n";
    $msg .= 'Subject: ' . mailEncodeHeader($subject) . "\r\n";
    $msg .= "\r\n" . str_replace("\n.", "\n..", $body) . "\r\n.";
    $resp = $cmd($msg);
    $cmd('QUIT');
    fclose($fp);

    $ok = strpos($resp, '250') === 0;
    if (!$ok) error_log('SMTP DATA failed: ' . trim($resp));
    return $ok;
}

/**
 * Ссылка на тему с учётом APP_URL.
 */
function topicUrl($topicId, $postId = null) {
    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $url = $base . '/topic.php?id=' . (int)$topicId;
    if ($postId) $url .= '#post-' . (int)$postId;
    return $url;
}
