<?php
require_once __DIR__ . '/functions.php';

/**
 * Подготовка запроса для FULLTEXT в boolean mode.
 * Слова требуем обязательно (+), фразы в кавычках ищем целиком.
 */
function ftQuery($q) {
    $q = trim(preg_replace('/\s+/u', ' ', (string)$q));
    if ($q === '') return '';

    $parts = [];

    // Фразы в кавычках
    if (preg_match_all('/"([^"]{1,100})"/u', $q, $m)) {
        foreach ($m[1] as $phrase) {
            $clean = preg_replace('/[^\p{L}\p{N}\s]/u', '', $phrase);
            $clean = trim(preg_replace('/\s+/u', ' ', $clean));
            if (mb_strlen($clean) >= 2) $parts[] = '+"' . $clean . '"';
        }
        $q = preg_replace('/"[^"]{0,100}"/u', ' ', $q);
    }

    foreach (preg_split('/\s+/u', trim($q)) as $word) {
        $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
        if (mb_strlen($clean) < 2) continue;

        // С ngram усечение (*) работает некорректно — длинные слова ищем как фразу
        if (mb_strlen($clean) >= 3) {
            $parts[] = '+"' . $clean . '"';
        } else {
            $parts[] = '+' . $clean;
        }
    }

    return implode(' ', $parts);
}

/**
 * Подсветка найденных слов. Принимает СЫРОЙ текст и сама экранирует вывод:
 * подсветка по уже экранированному HTML ломала сущности (&amp; -> &<mark>amp</mark>;).
 * Возвращает безопасный HTML с <mark class="search-mark">.
 */
function highlightWords($rawText, $q) {
    $rawText = (string)$rawText;

    $words = [];
    foreach (preg_split('/\s+/u', (string)$q) as $w) {
        $w = trim(preg_replace('/[^\p{L}\p{N}]/u', '', $w));
        if (mb_strlen($w) >= 2) $words[] = preg_quote($w, '/');
    }
    if (!$words) return escape($rawText);

    // Длинные слова подсвечиваются первыми
    $words = array_values(array_unique($words));
    usort($words, function ($a, $b) { return mb_strlen($b) - mb_strlen($a); });

    $re = '/(' . implode('|', $words) . ')/iu';
    $parts = preg_split($re, $rawText, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return escape($rawText);

    $html = '';
    foreach ($parts as $i => $part) {
        if ($part === '') continue;
        if ($i % 2 === 1) {
            $html .= '<mark class="search-mark">' . escape($part) . '</mark>';
        } else {
            $html .= escape($part);
        }
    }
    return $html;
}

/**
 * Фрагмент текста вокруг первого совпадения
 */
function searchSnippet($text, $q, $len = 260) {
    $plain = function_exists('mdExcerpt') ? mdExcerpt($text, 100000) : strip_tags((string)$text);
    $plain = trim(preg_replace('/\s+/u', ' ', $plain));

    $words = [];
    foreach (preg_split('/\s+/u', (string)$q) as $w) {
        $w = trim(preg_replace('/[^\p{L}\p{N}]/u', '', $w));
        if (mb_strlen($w) >= 2) $words[] = $w;
    }

    $pos = 0;
    foreach ($words as $w) {
        $p = mb_stripos($plain, $w);
        if ($p !== false) { $pos = $p; break; }
    }

    $start = max(0, $pos - 80);
    $snippet = mb_substr($plain, $start, $len);
    if ($start > 0) $snippet = '…' . $snippet;
    if ($start + $len < mb_strlen($plain)) $snippet .= '…';

    return $snippet;
}
