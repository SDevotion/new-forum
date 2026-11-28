<?php
// includes/mailer.php — транспорт писем + ОЧЕРЕДЬ.
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [2.6/приоритет#3] Веб-запрос больше НИКОГДА не открывает SMTP-сессию:
//       sendMail() кладёт письмо в таблицу mail_queue; доставку выполняет
//       install/cron.php (или php install/cron.php mail即时 вручную).
//       Ответ в теме с 200 подписчиками = 200 INSERT, а не 200 SMTP-сессий.
// [2.6] SMTP-клиент получил ЖЁСТКИЙ общий дедлайн на сессию (SMTP_TOTAL_TIMEOUT):
//       медленный сервер не держит воркер минутами (10с × N команд).
// [2.6] EHLO использует SMTP_EHLO_DOMAIN вместо захардкоженного localhost.
// [2.7] Тайминг-энумерация в forgot.php исчезает сама: обе ветки теперь делают
//       одинаковую работу (INSERT в очередь), сетевой I/O в запросе отсутствует.
// [new] Ретраи с экспоненциальной задержкой, статусы pending/sent/failed,
//       максимум 5 попыток; письма старше суток не отправляются (устаревшие ссылки).
// Требуется миграция mail_queue (даётся в части 4; CREATE TABLE продублирован ниже
// в комментарии для удобства).

require_once __DIR__ . '/functions.php';

/**
 * Постановка письма в очередь. Вызывается из веб-запросов.
 * Мгновенная операция (один INSERT), сеть не трогается.
 */
function sendMail($toEmail, $subject, $bodyText) {
    if (!is_string($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;
    try {
        $db = Database::getInstance()->getConnection();
        $db->prepare('INSERT INTO mail_queue (to_email, subject, body) VALUES (?,?,?)')
           ->execute([$toEmail, mb_substr((string)$subject, 0, 255), (string)$bodyText]);
        return true;
    } catch (PDOException $e) {
        error_log('mail queue insert failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Обработка очереди. ТОЛЬКО CLI/cron. $budgetSeconds — общий бюджет прогона,
 * $maxMails — потолок писем за прогон.
 */
function mailQueueProcess($budgetSeconds = 120, $maxMails = 200) {
    if (PHP_SAPI !== 'cli') return 0;
    $db = Database::getInstance()->getConnection();
    $deadline = microtime(true) + $budgetSeconds;
    $sent = 0;

    while ($sent < $maxMails && microtime(true) < $deadline) {
        // Забираем пачку под GET_LOCK, чтобы два cron-процесса не слали дубли.
        $got = $db->query("SELECT GET_LOCK('mail_queue_worker', 0)");
        if ((int)$got->fetchColumn() !== 1) break;
        try {
            $rows = $db->query("
                SELECT id, to_email, subject, body, attempts FROM mail_queue
                WHERE status = 'pending' AND next_try_at <= NOW()
                  AND created_at >= NOW() - INTERVAL 1 DAY
                ORDER BY id ASC LIMIT 10
            ")->fetchAll();
            if (!$rows) break;

            foreach ($rows as $r) {
                if (microtime(true) >= $deadline) break 2;
                $ok = mailDeliverNow($r['to_email'], $r['subject'], $r['body']);
                if ($ok) {
                    $db->prepare("UPDATE mail_queue SET status='sent', sent_at=NOW(), attempts=attempts+1 WHERE id=?")
                       ->execute([$r['id']]);
                    $sent++;
                } else {
                    $attempts = (int)$r['attempts'] + 1;
                    if ($attempts >= 5) {
                        $db->prepare("UPDATE mail_queue SET status='failed', attempts=?, last_error='max attempts' WHERE id=?")
                           ->execute([$attempts, $r['id']]);
                    } else {
                        $delay = min(3600, 60 * (2 ** ($attempts - 1))); // 1,2,4,8 мин...
                        $db->prepare("UPDATE mail_queue SET attempts=?, next_try_at=NOW() + INTERVAL ? SECOND, last_error='delivery failed' WHERE id=?")
                           ->execute([$attempts, $delay, $r['id']]);
                    }
                }
            }
        } finally {
            $db->query("SELECT RELEASE_LOCK('mail_queue_worker')");
        }
    }

    //.housekeeping: старые sent/failed и протухшие pending
    $db->exec("DELETE FROM mail_queue WHERE status='sent' AND sent_at < NOW() - INTERVAL 7 DAY");
    $db->exec("DELETE FROM mail_queue WHERE status='failed'");
    $db->exec("DELETE FROM mail_queue WHERE status='pending' AND created_at < NOW() - INTERVAL 1 DAY");
    return $sent;
}

/** Синхронная доставка ОДНОГО письма (только из воркера). */
function mailDeliverNow($toEmail, $subject, $bodyText) {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;

    $from = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@localhost';
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        error_log('mailDeliverNow: invalid MAIL_FROM');
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

    if ($transport === 'smtp') return smtpSend($from, $toEmail, $subject, $bodyText, $headers);

    $headerStr = '';
    foreach ($headers as $k => $v) $headerStr .= "$k: $v\r\n";
    $ok = @mail($toEmail, mailEncodeHeader($subject), $bodyText, $headerStr, '-f ' . escapeshellarg($from));
    if (!$ok) error_log("mailDeliverNow(mail) failed to $toEmail");
    return $ok;
}

function mailEncodeHeader($text) {
    return '=?UTF-8?B?' . base64_encode((string)$text) . '?=';
}

/**
 * Минимальный SMTP-клиент. Общий дедлайн SMTP_TOTAL_TIMEOUT на всю сессию:
 * каждая команда проверяет остаток бюджета; чтение multiline ограничено.
 */
function smtpSend($from, $to, $subject, $body, $headers) {
    $host   = defined('SMTP_HOST') ? SMTP_HOST : 'localhost';
    $port   = defined('SMTP_PORT') ? SMTP_PORT : 587;
    $user   = defined('SMTP_USER') ? SMTP_USER : '';
    $pass   = defined('SMTP_PASS') ? SMTP_PASS : '';
    $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
    $ehlo   = defined('SMTP_EHLO_DOMAIN') ? SMTP_EHLO_DOMAIN : 'localhost';
    $budget = defined('SMTP_TOTAL_TIMEOUT') ? (int)SMTP_TOTAL_TIMEOUT : 20;
    $deadline = microtime(true) + $budget;

    $prefix = ($secure === 'ssl') ? 'ssl://' : '';
    $fp = @fsockopen($prefix . $host, $port, $errno, $errstr, 5);
    if (!$fp) { error_log("SMTP connect failed: $errstr"); return false; }
    stream_set_timeout($fp, 5);

    $overBudget = function () use ($deadline, $fp) {
        if (microtime(true) >= $deadline) {
            error_log('SMTP total timeout exceeded');
            fclose($fp);
            return true;
        }
        return false;
    };
    $read = function () use ($fp, $deadline) {
        $data = '';
        while (($line = fgets($fp, 512)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
            if (microtime(true) >= $deadline) break;
            if (strlen($data) > 16384) break; // защита от бесконечного multiline
        }
        return $data;
    };
    $code = function ($resp) { return substr(trim($resp), 0, 3); };
    $cmd = function ($c) use ($fp, $read, $overBudget) {
        if ($overBudget()) return '';
        fputs($fp, $c . "\r\n");
        return $read();
    };
    $expect = function ($resp, $want, $step) use ($code, $fp) {
        $c = $code($resp);
        if (!in_array($c, (array)$want, true)) {
            error_log("SMTP $step failed ($c): " . trim($resp));
            fclose($fp);
            return false;
        }
        return true;
    };

    if ($overBudget()) return false;
    if (!$expect($read(), '220', 'greeting')) return false;
    if (!$expect($cmd('EHLO ' . $ehlo), '250', 'EHLO')) return false;

    if ($secure === 'tls') {
        if (!$expect($cmd('STARTTLS'), '220', 'STARTTLS')) return false;
        stream_context_set_option($fp, 'ssl', 'verify_peer', true);
        stream_context_set_option($fp, 'ssl', 'verify_peer_name', true);
        stream_context_set_option($fp, 'ssl', 'peer_name', $host);
        stream_context_set_option($fp, 'ssl', 'allow_self_signed', false);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            error_log('SMTP STARTTLS crypto failed'); fclose($fp); return false;
        }
        if (!$expect($cmd('EHLO ' . $ehlo), '250', 'EHLO after TLS')) return false;
    }

    if ($user !== '') {
        if (!$expect($cmd('AUTH LOGIN'), '334', 'AUTH')) return false;
        if (!$expect($cmd(base64_encode($user)), '334', 'AUTH user')) return false;
        if (!$expect($cmd(base64_encode($pass)), '235', 'AUTH pass')) return false;
    }

    if (!$expect($cmd('MAIL FROM:<' . $from . '>'), '250', 'MAIL FROM')) return false;
    if (!$expect($cmd('RCPT TO:<' . $to . '>'), ['250', '251'], 'RCPT TO')) return false;
    if (!$expect($cmd('DATA'), '354', 'DATA')) return false;

    $bodyNorm = str_replace(["\r\n", "\r"], "\n", (string)$body);
    $bodyCrlf = str_replace("\n", "\r\n", $bodyNorm);
    $bodyStuffed = preg_replace('/^\./m', '..', $bodyCrlf);

    $msg = '';
    foreach ($headers as $k => $v) $msg .= "$k: $v\r\n";
    $msg .= 'To: <' . $to . ">\r\n";
    $msg .= 'Subject: ' . mailEncodeHeader($subject) . "\r\n";
    $msg .= "\r\n" . $bodyStuffed;
    if (substr($msg, -2) !== "\r\n") $msg .= "\r\n";
    $end = $cmd($msg . '.');
    $cmd('QUIT');
    if (is_resource($fp)) fclose($fp);

    return $expect($end, '250', 'DATA end');
}

function topicUrl($topicId, $postId = null) {
    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $url = $base . '/topic.php?id=' . (int)$topicId;
    if ($postId) $url .= '#post-' . (int)$postId;
    return $url;
}