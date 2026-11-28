<?php
/**
 * Мини-парсер Markdown -> безопасный HTML.
 * Текст экранируется ДО разбора; URL ограничены whitelist-символами без & и кавычек.
 *
 * РЕШЕНО В ЭТОМ ФАЙЛЕ:
 * [2.1] Вложенные [quote]...[/quote]: рекурсивный разбор со счётчиком глубины
 *       (максимум 3) вместо одного нежадного preg_replace — несбалансированный
 *       HTML (<div> внутри <p>/<li>) больше не ломает DOM страницы.
 *       Незакрытые [/quote] и лишние открывающие теги остаются текстом.
 * [2.1] Восстановление плейсхолдеров MN/IC/CODE проверяет isset() ключа —
 *       обращение к несуществующему индексу (undefined array key warning на PHP 8)
 *       невозможно; чужой маркер возвращается как есть.
 * [2.2] mdSafeUrl(): запрещён userinfo (user:pass@host) — фишинг-ссылки вида
 *       https://good.com%[email protected]/ отклоняются; хвостовая пунктуация
 *       и несбалансированные скобки аккуратно выносятся из автоссылки.
 * [4.4] mdMentionId(): fallback-lookup помечен в лог — контракт «вызови
 *       mdPrimeMentions перед циклом» теперь наблюдаем, а не молчалив.
 * Плейсхолдеры \x00/\x02 по-прежнему вырезаются из пользовательского ввода.
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

    foreach ($missing as $n) $cache[$n] = 0;

    try {
        $db = Database::getInstance()->getConnection();
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

    // Fallback: одиночный lookup. Если срабатывает часто — где-то забыли prime.
    static $fallbackHits = 0;
    if (++$fallbackHits === 10) {
        error_log('mdMentionId: 10+ unprimed lookups in one request — check mdPrimeMentions call sites');
    }
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

function mdStripCode($text) {
    $t = preg_replace('/```.*?```/s', ' ', (string)$text);
    return preg_replace('/`[^`\n]+`/', ' ', $t);
}

/**
 * Безопасный URL для href уже экранированного HTML.
 * Whitelist символов + запрет userinfo (антифишинг).
 */
function mdSafeUrl($url) {
    $url = trim((string)$url);
    if (!preg_match('#^https?://#i', $url)) return null;
    if (!preg_match('#^[a-zA-Z0-9:/._~%\-+?=\#@!\$*,;()\[\]]+$#', $url)) return null;
    if (strlen($url) > 500) return null;

    // Authority = всё между схемой и первым / ? #
    $authority = preg_replace('#^https?://#i', '', $url);
    $authority = preg_split('#[/?\#]#', $authority)[0];
    // userinfo запрещён полностью: '@' в authority (в т.ч. %40) — отказ.
    if (strpos($authority, '@') !== false) return null;
    if (stripos($authority, '%40') !== false) return null;
    // Пустой хост (https:///path) — отказ.
    if ($authority === '') return null;
    return $url;
}

/**
 * Рекурсивный разбор [quote=автор]...[/quote] с ограничением глубины.
 * Работает по экранированному тексту; несбалансированные теги остаются текстом.
 */
function mdParseQuotes($text, $depth = 0) {
    if ($depth >= 3) return $text; // глубже 3 — теги остаются литеральным текстом

    $pattern = '/\[quote=([^\]\n]{1,60})\]((?:(?!\[quote=)[\s\S])*?)\[\/quote\]/';
    // (?:(?!\[quote=)...) — внутренний контент не содержит новых открывающих тегов,
    // поэтому матчится САМЫЙ ВНУТРЕННИЙ quote; повторяем до стабилизации.
    for ($i = 0; $i < 10; $i++) {
        $new = preg_replace_callback($pattern, function ($m) use ($depth) {
            $inner = mdParseQuotes($m[2], $depth + 1);
            return '<div class="post-quote"><div class="post-quote-author">'
                 . trim($m[1]) . ' писал(а):</div>' . trim($inner) . '</div>';
        }, $text);
        if ($new === null || $new === $text) break;
        $text = $new;
    }
    return $text;
}

function mdRender($text) {
    $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
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

    $mentions = [];
    $text = preg_replace_callback('/(?<![\w>\/])@([a-zA-Z0-9_]{3,50})/', function ($m) use (&$mentions) {
        $id = mdMentionId($m[1]);
        if (!$id) return $m[0];
        $mentions[] = '<a href="/profile.php?id=' . $id . '" class="mention">@' . $m[1] . '</a>';
        return "\x02MN" . (count($mentions) - 1) . "\x02";
    }, $text);

    // Вложенные цитаты: рекурсивно, самый внутренний первым.
    $text = mdParseQuotes($text);

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
        // Блочная цитата [quote] целиком на строке — не оборачиваем в <p>
        if (preg_match('/^<div class="post-quote">.*<\/div>$/s', $line)) { $flush(); $out[] = $line; continue; }
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
        $url = mdSafeUrl($m[2]);
        if ($url === null) return $m[0];
        return '<a href="' . $url . '" class="md-link" target="_blank" rel="nofollow noopener noreferrer">' . $m[1] . '</a>';
    }, $html);

    $html = preg_replace_callback('#(?<![">=\w/])(https?://[a-zA-Z0-9:/._~%\-+?=\#@!\$*,;()\[\]]{1,500})#', function ($m) {
        $url = $m[1];
        // Хвостовая пунктуация и несбалансированные закрывающие скобки — вне ссылки.
        $trail = '';
        while ($url !== '') {
            $last = substr($url, -1);
            if (strpos('.,;:!', $last) !== false) {
                $trail = $last . $trail; $url = substr($url, 0, -1); continue;
            }
            if (($last === ')' && substr_count($url, '(') < substr_count($url, ')'))
             || ($last === ']' && substr_count($url, '[') < substr_count($url, ']'))) {
                $trail = $last . $trail; $url = substr($url, 0, -1); continue;
            }
            break;
        }
        $safe = mdSafeUrl($url);
        if ($safe === null || !preg_match('#^https?://.+#i', $safe)) return $m[0];
        return '<a href="' . $safe . '" class="md-link" target="_blank" rel="nofollow noopener noreferrer">' . $safe . '</a>' . $trail;
    }, $html);

    // Восстановление плейсхолдеров: isset-проверка каждого индекса.
    $html = preg_replace_callback('/\x02MN(\d+)\x02/', function ($m) use ($mentions) {
        $i = (int)$m[1];
        return isset($mentions[$i]) ? $mentions[$i] : $m[0];
    }, $html);
    $html = preg_replace_callback('/\x00IC(\d+)\x00/', function ($m) use ($inlineCodes) {
        $i = (int)$m[1];
        return isset($inlineCodes[$i]) ? $inlineCodes[$i] : $m[0];
    }, $html);
    $html = preg_replace_callback('/\x00CODE(\d+)\x00/', function ($m) use ($codeBlocks) {
        $i = (int)$m[1];
        return isset($codeBlocks[$i]) ? $codeBlocks[$i] : $m[0];
    }, $html);

    return $html;
}

function mdExcerpt($text, $len = 180) {
    $t = preg_replace('/```.*?```/s', ' [код] ', (string)$text);
    // Вложенные quote: убираем итеративно, пока пары исчезают
    for ($i = 0; $i < 5; $i++) {
        $n = preg_replace('/\[quote=[^\]]*\]((?:(?!\[quote=)[\s\S])*?)\[\/quote\]/', ' [цитата] ', $t);
        if ($n === null || $n === $t) break;
        $t = $n;
    }
    $t = preg_replace('/\[(\/)?quote[^\]]*\]/', ' ', $t); // остатки несбалансированных тегов
    $t = preg_replace('/[#*`>~_\[\]()!]/u', '', $t);
    $t = preg_replace('/\s+/u', ' ', $t);
    return mb_strimwidth(trim($t), 0, $len, '…');
}