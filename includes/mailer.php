<?php
// includes/mailer.php — отправка писем (mail() или простой SMTP).
// Для прода рекомендуется PHPMailer/Symfony Mailer; этот клиент — fallback без зависимостей.
require_once __DIR__ . '/functions.php';

function sendMail($toEmail, $subject, $bodyText) {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

    $from = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@localhost';
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('sendMail: invalid MAIL_FROM');
        return false;
    }
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

    // Envelope-from экранируем: защита от аргумент-инъекции в sendmail.
    $ok = @mail($toEmail, $encodedSubject, $bodyText, $headerStr, '-f ' . escapeshellarg($from));
    if (!$ok) error_log("sendMail(mail) failed to $toEmail");
    return $ok;
}

function mailEncodeHeader($text) {
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * Минимальный SMTP-клиент с проверкой кодов ответа на каждом шаге.
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

    // Чтение полного ответа (multiline: последняя строка имеет пробел после кода).
    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 512)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $code = function ($resp) { return substr(trim($resp), 0, 3); };
    $cmd = function ($c) use ($fp, $read) { fputs($fp, $c . "\r\n"); return $read(); };
    $expect = function ($resp, $want, $step) use ($code, $fp) {
        $c = $code($resp);
        if (!in_array($c, (array)$want, true)) {
            error_log("SMTP $step failed ($c): " . trim($resp));
            fclose($fp);
            return false;
        }
        return true;
    };

    $greeting = $read();
    if (!$expect($greeting, '220', 'greeting')) return false;

    $ehlo = $cmd('EHLO localhost');
    if (!$expect($ehlo, '250', 'EHLO')) return false;

    if ($secure === 'tls') {
        $st = $cmd('STARTTLS');
        if (!$expect($st, '220', 'STARTTLS')) return false;
        // Явная проверка сертификата: AUTH LOGIN (base64) не должен уходить по MITM-каналу.
        stream_context_set_option($fp, 'ssl', 'verify_peer', true);
        stream_context_set_option($fp, 'ssl', 'verify_peer_name', true);
        stream_context_set_option($fp, 'ssl', 'peer_name', $host);
        stream_context_set_option($fp, 'ssl', 'allow_self_signed', false);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            error_log('SMTP STARTTLS crypto failed'); fclose($fp); return false;
        }
        $ehlo = $cmd('EHLO localhost');
        if (!$expect($ehlo, '250', 'EHLO after TLS')) return false;
    }

    if ($user !== '') {
        $a = $cmd('AUTH LOGIN');
        if (!$expect($a, '334', 'AUTH')) return false;
        $u = $cmd(base64_encode($user));
        if (!$expect($u, '334', 'AUTH user')) return false;
        $p = $cmd(base64_encode($pass));
        if (!$expect($p, '235', 'AUTH pass')) return false;
    }

    $mf = $cmd('MAIL FROM:<' . $from . '>');
    if (!$expect($mf, '250', 'MAIL FROM')) return false;
    $rc = $cmd('RCPT TO:<' . $to . '>');
    if (!$expect($rc, ['250', '251'], 'RCPT TO')) return false;
    $dt = $cmd('DATA');
    if (!$expect($dt, '354', 'DATA')) return false;

    // Тело: нормализуем переводы строк в CRLF, затем dot-stuffing по RFC 5321
    // (каждая строка, начинающаяся с точки, получает вторую точку).
    $bodyNorm = str_replace(["\r\n", "\r"], "\n", (string)$body);
    $bodyCrlf = str_replace("\n", "\r\n", $bodyNorm);
    $bodyStuffed = preg_replace('/^\./m', '..', $bodyCrlf);

    $msg = '';
    foreach ($headers as $k => $v) $msg .= "$k: $v\r\n";
    $msg .= 'To: <' . $to . ">\r\n";
    $msg .= 'Subject: ' . mailEncodeHeader($subject) . "\r\n";
    $msg .= "\r\n" . $bodyStuffed;
    // Терминатор DATA строго <CRLF>.<CRLF>
    if (substr($msg, -2) !== "\r\n") $msg .= "\r\n";
    $end = $cmd($msg . '.');
    $cmd('QUIT');
    fclose($fp);

    if (!$expect($end, '250', 'DATA end')) return false;
    return true;
}

function topicUrl($topicId, $postId = null) {
    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $url = $base . '/topic.php?id=' . (int)$topicId;
    if ($postId) $url .= '#post-' . (int)$postId;
    return $url;
}
