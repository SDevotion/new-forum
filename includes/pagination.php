<?php
/**
 * Рендер пагинации
 * @param int $currentPage
 * @param int $totalPages
 * @param string $baseUrl URL с параметрами, к которому добавится &page=N
 */
function renderPagination($currentPage, $totalPages, $baseUrl) {
    if ($totalPages <= 1) return;

    $separator = (strpos($baseUrl, '?') === false) ? '?' : '&';
    $link = function($page) use ($baseUrl, $separator) {
        return escape($baseUrl . $separator . 'page=' . $page);
    };

    echo '<div class="pagination">';

    if ($currentPage > 1) {
        echo '<a href="' . $link($currentPage - 1) . '" class="page-link">‹ Назад</a>';
    }

    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        echo '<a href="' . $link(1) . '" class="page-link">1</a>';
        if ($start > 2) echo '<span class="page-dots">…</span>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = ($i === $currentPage) ? ' active' : '';
        echo '<a href="' . $link($i) . '" class="page-link' . $active . '">' . $i . '</a>';
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) echo '<span class="page-dots">…</span>';
        echo '<a href="' . $link($totalPages) . '" class="page-link">' . $totalPages . '</a>';
    }

    if ($currentPage < $totalPages) {
        echo '<a href="' . $link($currentPage + 1) . '" class="page-link">Вперёд ›</a>';
    }

    echo '</div>';
}