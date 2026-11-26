<?php
/**
 * Мини-парсер Markdown -> безопасный HTML.
 * Текст экранируется ДО разбора; URL ссылок дополнительно ограничены
 * whitelist-символами, не содержащими & и кавычки — инъекция атрибутов невозможна.
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

/**
 * Убирает код-блоки и inline-код из текста. Для парсинга упоминаний:
 * @ник внутри кода — пример кода, а не упоминание (совпадает с поведением рендера).
 */
function mdStripCode($text) {
    $t = preg_replace('/```.*?```/s', ' ', (string)$text);
    return preg_replace('/`[^`\n]+`/', ' ', $t);
}

/**
 * Безопасный URL для вставки в href уже экранированного HTML.
 * Разрешены только символы, которые htmlspecialchars НЕ преобразует,
 * поэтому HTML-сущности (&quot; и т.п.) в атрибут попасть не могут.
 */
function mdSafeUrl($url) {
    $url = trim((string)$url);
    if (!preg_match('#^https?://#i', $url)) return null;
    if (!preg_match('#^[a-zA-Z0-9:/._~%\-+?=\#@!\$*,;()\[\]]+$#', $url)) return null;
    if (strlen($url) > 500) return null;
    return $url;
}

function mdRender($text) {
    $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
    // \x00 и \x02 — служебные байты плейсхолдеров; из пользовательского ввода вырезаем оба
    $text = str_replace(["\x00", "\x02"], '', $text);
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

    // @упоминания — ДО построения HTML: здесь ещё нет тегов, и lookbehind не блокирует
    // ник после <p>, <br>, <strong>, <li> и т.п. Маркер на \x02 (вырезан из ввода выше),
    // '/' в lookbehind — чтобы не трогать @ник внутри URL автоссылок.
    $mentions = [];
    $text = preg_replace_callback('/(?<![\w>\/])@([a-zA-Z0-9_]{3,50})/', function ($m) use (&$mentions) {
        $id = mdMentionId($m[1]);
        if (!$id) return $m[0];
        $mentions[] = '<a href="/profile.php?id=' . $id . '" class="mention">@' . $m[1] . '</a>';
        return "\x02MN" . (count($mentions) - 1) . "\x02";
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

    // [текст](url): URL проходит whitelist; небезопасный URL — ссылка не создаётся
    $html = preg_replace_callback('/\[([^\]\n]{1,200})\]\((https?:\/\/[^\s)]{1,500})\)/', function ($m) {
        $url = mdSafeUrl($m[2]);
        if ($url === null) return $m[0];
        return '<a href="' . $url . '" class="md-link" target="_blank" rel="nofollow noopener noreferrer">' . $m[1] . '</a>';
    }, $html);

    // Автоссылки: захватываем только whitelist-символы (без &, кавычек, <, >),
    // поэтому HTML-сущности не могут «разорвать» атрибут href.
    $html = preg_replace_callback('#(?<![">=\w/])(https?://[a-zA-Z0-9:/._~%\-+?=\#@!\$*,;()\[\]]{1,500})#', function ($m) {
        $url = mdSafeUrl($m[1]);
        if ($url === null) return $m[0];
        // хвостовая пунктуация не входит в ссылку
        $trail = '';
        while ($url !== '' && strpos('.,;:!)]', substr($url, -1)) !== false) {
            $trail = substr($url, -1) . $trail;
            $url = substr($url, 0, -1);
        }
        if ($url === '' || !preg_match('#^https?://.+#i', $url)) return $m[0];
        return '<a href="' . $url . '" class="md-link" target="_blank" rel="nofollow noopener noreferrer">' . $url . '</a>' . $trail;
    }, $html);

    // Восстановление ссылок-упоминаний из плейсхолдеров
    $html = preg_replace_callback('/\x02MN(\d+)\x02/', function ($m) use ($mentions) {
        return $mentions[(int)$m[1]];
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