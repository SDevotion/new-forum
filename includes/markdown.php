<?php
/**
 * Мини-парсер Markdown -> безопасный HTML.
 * Весь текст экранируется ДО разбора, поэтому XSS невозможен.
 */

function mdRender($text) {
    $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
    $text = str_replace("\x00", '', $text); // нельзя подделать служебные плейсхолдеры кода
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    // Блоки кода ``` ... ```
    $codeBlocks = [];
    $text = preg_replace_callback('/```([a-zA-Z0-9_+-]*)\n(.*?)\n```/s', function ($m) use (&$codeBlocks) {
        $lang = $m[1] !== '' ? ' data-lang="' . $m[1] . '"' : '';
        $codeBlocks[] = '<pre class="md-pre"' . $lang . '><code>' . rtrim($m[2]) . '</code></pre>';
        return "\x00CODE" . (count($codeBlocks) - 1) . "\x00";
    }, $text);

    // Инлайн-код `...`
    $inlineCodes = [];
    $text = preg_replace_callback('/`([^`\n]+)`/', function ($m) use (&$inlineCodes) {
        $inlineCodes[] = '<code class="md-code">' . $m[1] . '</code>';
        return "\x00IC" . (count($inlineCodes) - 1) . "\x00";
    }, $text);

    // Цитаты [quote=Автор]...[/quote]
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
            $lvl = strlen($m[1]) + 2; // h3..h6
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

    // Инлайн-форматирование
    $html = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/(?<![*\w])\*(?=\S)([^*\n]+?)(?<=\S)\*(?!\*)/', '<em>$1</em>', $html);
    $html = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $html);

    // Ссылки [текст](url) — только http/https
    $html = preg_replace_callback('/\[([^\]\n]{1,200})\]\((https?:\/\/[^\s)]{1,500})\)/', function ($m) {
        return '<a href="' . $m[2] . '" class="md-link" target="_blank" rel="nofollow noopener noreferrer">' . $m[1] . '</a>';
    }, $html);

    // Автоссылки
    $html = preg_replace_callback('/(?<![">=\w])(https?:\/\/[^\s<>"\']{1,500})/', function ($m) {
        return '<a href="' . $m[1] . '" class="md-link" target="_blank" rel="nofollow noopener noreferrer">' . $m[1] . '</a>';
    }, $html);

    // @упоминания -> ссылка на профиль
    $html = preg_replace_callback('/(?<![\w>])@([a-zA-Z0-9_]{3,50})/', function ($m) {
        static $cache = [];
        $name = $m[1];
        if (!isset($cache[$name])) {
            $db = Database::getInstance()->getConnection();
            $st = $db->prepare("SELECT id FROM users WHERE username = ? AND is_active = 1");
            $st->execute([$name]);
            $cache[$name] = (int)$st->fetchColumn();
        }
        if (!$cache[$name]) return $m[0];
        return '<a href="/profile.php?id=' . $cache[$name] . '" class="mention">@' . $name . '</a>';
    }, $html);

    // Возврат защищённых фрагментов кода
    $html = preg_replace_callback('/\x00IC(\d+)\x00/', function ($m) use ($inlineCodes) {
        return $inlineCodes[(int)$m[1]];
    }, $html);
    $html = preg_replace_callback('/\x00CODE(\d+)\x00/', function ($m) use ($codeBlocks) {
        return $codeBlocks[(int)$m[1]];
    }, $html);

    return $html;
}

/**
 * Plain-text превью для списков и профилей
 */
function mdExcerpt($text, $len = 180) {
    $t = preg_replace('/```.*?```/s', ' [код] ', (string)$text);
    $t = preg_replace('/\[quote=[^\]]*\].*?\[\/quote\]/s', ' [цитата] ', $t);
    $t = preg_replace('/[#*`>~_\[\]()!]/u', '', $t);
    $t = preg_replace('/\s+/u', ' ', $t);
    return mb_strimwidth(trim($t), 0, $len, '…');
}