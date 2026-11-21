<?php
/**
 * Мини-парсер Markdown -> безопасный HTML.
 * Весь текст экранируется ДО разбора, поэтому XSS невозможен.
 */

/**
 * Предзагрузка id пользователей для упоминаний (батч, один запрос на страницу).
 * Вызывать перед циклом рендера постов: mdPrimeMentions([$content1, $content2, ...]).
 */
function mdPrimeMentions(array $texts) {
    static $cache = [];
    $names = [];
    foreach ($texts as $t) {
        if (preg_match_all('/(?<![\w>])@([a-zA-Z0-9_]{3,50})/', (string)$t, $m)) {
            foreach ($m[1] as $n) $names[$n] = true;
        }
    }
    $missing = array_keys(array_diff_key($names, $cache));
    if (!$missing) return;

    // Помечаем отсутствующих нулём, чтобы не ходить в БД повторно
    foreach ($missing as $n) $cache[$n] = 0;

    try {
        $db = Database::getInstance()->getConnection();
        // Чанки по 200 имён (лимит плейсхолдеров)
        foreach (array_chunk($missing, 200) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $db->prepare("SELECT id, username FROM users WHERE username IN ($in) AND is_active = 1");
            $st->execute($chunk);
            foreach ($st->fetchAll() as $row) {
                $cache[$row['username']] = (int)$row['id'];
            }
        }
    } catch (PDOException $e) {
        error_log('mdPrimeMentions: ' . $e->getMessage());
    }
    $GLOBALS['__md_mention_cache'] = $cache;
}

function mdMentionId($name) {
    $cache = $GLOBALS['__md_mention_cache'] ?? [];
    if (isset($cache[$name])) return $cache[$name];
    // Fallback: одиночный lookup (если prime не вызывали)
    try {
        $db = Database::getInstance()->getConnection();
        $st = $db->prepare("SELECT id FROM users WHERE username = ? AND is_active = 1");
        $st->execute([$name]);
        $id = (int)$st->fetchColumn();
    } catch (PDOException $e) {
        $id = 0;
    }
    $cache[$name] = $id;
    $GLOBALS['__md_mention_cache'] = $cache;
    return $id;
}

function mdRender($text) {
    $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
    $text = str_replace("\x00", '', $text);
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    $codeBlocks = [];
    $text = preg_replace_callback('/```([a-zA-Z0-9_+-]*)\n(.*?)\n```/s', function ($m) use (&$codeBlocks) {
        $lang = $m[1] !== '' ? ' data-lang="' . $m[1] . '"' : '';
        $codeBlocks[] = '<pre class="md-pre"' . $lang . '><code>' . rtrim($m[2]) . '</code></pre>';
        return "\x00CODE" . (count($codeBlocks) - 1) . "\x00";
    }, $text);

    $inlineCodes = [];
    $text = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$inlineCodes) {
        $inlineCodes[] = '<code class="md-code">' . $m[1] . '</code>';
        return "\x00IC" . (count($inlineCodes) - 1) . "\x00";
    }, $text);

    $text = preg_replace_callback('/\[quote=([^\]\n]{1,60})\](.*?)\[\/quote\]/s', function ($m) {
        return '<div class="post-quote"><div class="post-quote-author">' . trim($m[1]) . ' писал(а):</div>' . trim($m[2]) . '</div>';
    }, $text);

    $lines = explode("\n", $text);
    $out = [];
    $para = [];
    $inList = false;
    $listType = '';
    $inQuote = false;

    $flush = function () use (&$para, &$out, &$inList, &$listType, &$inQuote) {
        if ($para) { $out[] = '<p>' . implode('<br>', $para) . '</p>'; $para = []; }
        if ($inList) { $out[] = '</' . $listType . '>'; $inList = false; $listType = ''; }
        if ($inQuote) { $out[] = '</blockquote>'; $inQuote = false; }
    };

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') { $flush(); continue; }
        if (preg_match('/^\x00CODE(\d+)\x00$/', $line)) { $flush(); $out[] = $line; continue; }
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $line)) { $flush(); $out[] = '<hr class="md-hr">'; continue; }
        if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m)) {
            $flush();
            $lvl = strlen($m[1]) + 2;
            $out[] = '<h' . $lvl . ' class="md-h">' . $m[2] . '</h' . $lvl . '>';
            continue;
        }
        if (preg_match('/^&gt;\s?(.*)$/', $line, $m)) {
            if ($para) { $out[] = '<p>' . implode('<br>', $para) . '</p>'; $para = []; }
            if ($inList) { $out[] = '</' . $listType . '>'; $inList = false; $listType = ''; }
            if (!$inQuote) { $out[] = '<blockquote class="md-quote">'; $inQuote = true; }
            $out[] = $m[1] . '<br>';
            continue;
        }
        if ($inQuote) { $out[] = '</blockquote>'; $inQuote = false; }
        if (preg_match('/^[-*+]\s+(.+)$/', $line, $m)) {
            if ($para) { $out[] = '<p>' . implode('<br>', $para) . '</p>'; $para = []; }
            if (!$inList || $listType !== 'ul') {
                if ($inList) $out[] = '</' . $listType . '>';
                $out[] = '<ul class="md-list">'; $inList = true; $listType = 'ul';
            }
            $out[] = '<li>' . $m[1] . '</li>';
            continue;
        }
        if (preg_match('/^\d+[.)]\s+(.+)$/', $line, $m)) {
            if ($para) { $out[] = '<p>' . implode('<br>', $para) . '</p>'; $para = []; }
            if (!$inList || $listType !== 'ol') {
                if ($inList) $out[] = '</' . $listType . '>';
                $out[] = '<ol class="md-list">'; $inList = true; $listType = 'ol';
            }
            $out[] = '<li>' . $m[1] . '</li>';
            continue;
        }
        if ($inList) { $out[] = '</' . $listType . '>'; $inList = false; $listType = ''; }
        $para[] = $line;
    }
    $flush();

    $html = implode("\n", $out);

    $html = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/(?<![*\w])\*(?=\S)([^*\n]+?)(?<=\S)\*(?!\*)/', '<em>$1</em>', $html);
    $html = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $html);

    $html = preg_replace_callback('/\[([^\]\n]{1,200})\]\((https?:\/\/[^\s)]{1,500})\)/', function ($m) {
        return '<a href="' . $m[2] . '" class="md-link" target="_blank" rel="nofollow noopener noreferrer">' . $m[1] . '</a>';
    }, $html);

    $html = preg_replace_callback('/(?<![">=\w])(https?:\/\/[^\s<>"\']{1,500})/', function ($m) {
        return '<a href="' . $m[1] . '" class="md-link" target="_blank" rel="nofollow noopener noreferrer">' . $m[1] . '</a>';
    }, $html);

    // @упоминания: id берутся из предзагруженного кэша (без запроса на имя)
    $html = preg_replace_callback('/(?<![\w>])@([a-zA-Z0-9_]{3,50})/', function ($m) {
        $id = mdMentionId($m[1]);
        if (!$id) return $m[0];
        return '<a href="/profile.php?id=' . $id . '" class="mention">@' . $m[1] . '</a>';
    }, $html);

    $html = preg_replace_callback('/\x00IC(\d+)\x00/', function ($m) use ($inlineCodes) {
        return $inlineCodes[(int)$m[1]];
    }, $html);
    $html = preg_replace_callback('/\x00CODE(\d+)\x00/', function ($m) use ($codeBlocks) {
        return $codeBlocks[(int)$m[1]];
    }, $html);

    return $html;
}

function mdExcerpt($text, $len = 180) {
    $t = preg_replace('/```.*?```/s', ' [код] ', (string)$text);
    $t = preg_replace('/\[quote=[^\]]*\].*?\[\/quote\]/s', ' [цитата] ', $t);
    $t = preg_replace('/[#*`>~_\[\]()!]/u', '', $t);
    $t = preg_replace('/\s+/u', ' ', $t);
    return mb_strimwidth(trim($t), 0, $len, '…');
}
