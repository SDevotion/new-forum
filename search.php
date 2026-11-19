<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/search.php';
require_once __DIR__ . '/includes/markdown.php';
require_once __DIR__ . '/includes/avatar.php';
require_once __DIR__ . '/includes/pagination.php';

$pageTitle = 'Поиск';
$db = Database::getInstance()->getConnection();
$me = getCurrentUser();
$staff = isModerator();

$q       = trim($_GET['q'] ?? '');
$scope   = $_GET['scope'] ?? 'all';
$forumId = (int)($_GET['forum_id'] ?? 0);
$author  = trim($_GET['author'] ?? '');
$sort    = $_GET['sort'] ?? 'relevance';
$period  = $_GET['period'] ?? 'any';

if (!in_array($scope, ['all', 'topics', 'posts'], true)) $scope = 'all';
if (!in_array($sort, ['relevance', 'new', 'old'], true)) $sort = 'relevance';
if (!in_array($period, ['any', 'week', 'month', 'year'], true)) $period = 'any';

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$results = [];
$total = 0;
$totalPages = 1;
$error = '';
$ft = ftQuery($q);

$dateSql = [
    'any'   => '',
    'week'  => ' AND %s.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
    'month' => ' AND %s.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
    'year'  => ' AND %s.created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)',
][$period];

$authorId = 0;
if ($author !== '') {
    $st = $db->prepare("SELECT id FROM users WHERE username = ?");
    $st->execute([$author]);
    $authorId = (int)$st->fetchColumn();
}

if (mb_strlen($q) > 0 && mb_strlen($q) < 2) {
    $error = 'Запрос слишком короткий. Минимум 2 символа.';
} elseif (mb_strlen($q) > 100) {
    $error = 'Запрос слишком длинный. Максимум 100 символов.';
} elseif ($ft !== '') {

    $hiddenT = $staff ? '' : ' AND t.is_hidden = 0';
    $hiddenP = $staff ? '' : ' AND p.is_hidden = 0';
    $forumT  = $forumId ? ' AND t.forum_id = ' . $forumId : '';
    $forumP  = $forumId ? ' AND t.forum_id = ' . $forumId : '';
    $authT   = $authorId ? ' AND t.user_id = ' . $authorId : '';
    $authP   = $authorId ? ' AND p.user_id = ' . $authorId : '';
    $dateT   = $dateSql ? sprintf($dateSql, 't') : '';
    $dateP   = $dateSql ? sprintf($dateSql, 'p') : '';

    $sqlTopics = "
        SELECT 'topic' AS kind, t.id, t.id AS topic_id, t.title, t.content,
               t.created_at,
               (SELECT COUNT(*) FROM posts vp WHERE vp.topic_id = t.id AND (vp.is_hidden = 0 OR ? = 1)) AS replies_count,
               t.views_count,
               u.username, u.avatar, f.title AS forum_title,
               MATCH(t.title, t.content) AGAINST (? IN BOOLEAN MODE) AS score
        FROM topics t
        JOIN users u ON u.id = t.user_id
        JOIN forums f ON f.id = t.forum_id
        JOIN categories c ON c.id = f.category_id
        WHERE MATCH(t.title, t.content) AGAINST (? IN BOOLEAN MODE)
          AND f.is_active = 1 AND c.is_active = 1
          $hiddenT $forumT $authT $dateT
    ";

    $sqlPosts = "
        SELECT 'post' AS kind, p.id, p.topic_id, t.title, p.content,
               p.created_at, 0 AS replies_count, 0 AS views_count,
               u.username, u.avatar, f.title AS forum_title,
               MATCH(p.content) AGAINST (? IN BOOLEAN MODE) AS score
        FROM posts p
        JOIN topics t ON t.id = p.topic_id
        JOIN users u ON u.id = p.user_id
        JOIN forums f ON f.id = t.forum_id
        JOIN categories c ON c.id = f.category_id
        WHERE MATCH(p.content) AGAINST (? IN BOOLEAN MODE)
          AND f.is_active = 1 AND c.is_active = 1$hiddenT
          $hiddenP $forumP $authP $dateP
    ";

    $order = $sort === 'relevance' ? 'score DESC, created_at DESC, kind ASC, id DESC'
           : ($sort === 'new' ? 'created_at DESC, kind ASC, id DESC' : 'created_at ASC, kind ASC, id ASC');

    try {
        if ($scope === 'topics') {
            $cnt = $db->prepare("SELECT COUNT(*) FROM ($sqlTopics) x");
            $cnt->execute([$staff ? 1 : 0, $ft, $ft]);
            $total = (int)$cnt->fetchColumn();
            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

            $st = $db->prepare("SELECT * FROM ($sqlTopics) x ORDER BY $order LIMIT $perPage OFFSET $offset");
            $st->execute([$staff ? 1 : 0, $ft, $ft]);
            $results = $st->fetchAll();

        } elseif ($scope === 'posts') {
            $cnt = $db->prepare("SELECT COUNT(*) FROM ($sqlPosts) x");
            $cnt->execute([$ft, $ft]);
            $total = (int)$cnt->fetchColumn();
            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

            $st = $db->prepare("SELECT * FROM ($sqlPosts) x ORDER BY $order LIMIT $perPage OFFSET $offset");
            $st->execute([$ft, $ft]);
            $results = $st->fetchAll();

        } else {
            $union = "$sqlTopics UNION ALL $sqlPosts";

            $cnt = $db->prepare("SELECT COUNT(*) FROM ($union) x");
            $cnt->execute([$staff ? 1 : 0, $ft, $ft, $ft, $ft]);
            $total = (int)$cnt->fetchColumn();
            $totalPages = max(1, (int)ceil($total / $perPage));
            if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

            $st = $db->prepare("SELECT * FROM ($union) x ORDER BY $order LIMIT $perPage OFFSET $offset");
            $st->execute([$staff ? 1 : 0, $ft, $ft, $ft, $ft]);
            $results = $st->fetchAll();
        }

        // Лог поиска — только первая страница
        if ($page === 1 && mb_strlen($q) >= 2) {
            try {
                $db->prepare("INSERT INTO search_log (user_id, query, results) VALUES (?,?,?)")
                   ->execute([$me ? $me['id'] : null, mb_substr($q, 0, 200), $total]);
            } catch (PDOException $e) { /* лог не критичен */ }
        }
    } catch (PDOException $e) {
        error_log('Search error: ' . $e->getMessage());
        $error = 'Ошибка поиска. Проверьте, что FULLTEXT-индексы ft_topic и ft_post созданы (database.sql).';
    }
}

$forumsList = $db->query("
    SELECT f.id, f.title FROM forums f
    JOIN categories c ON c.id = f.category_id
    WHERE f.is_active = 1 AND c.is_active = 1
    ORDER BY c.sort_order, f.sort_order
")->fetchAll();

try {
    $popular = $db->query("
        SELECT query, COUNT(*) AS cnt FROM search_log
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND results > 0
        GROUP BY query ORDER BY cnt DESC LIMIT 10
    ")->fetchAll();
} catch (PDOException $e) {
    $popular = [];
}

$queryBase = '/search.php?q=' . urlencode($q) . '&scope=' . $scope . '&forum_id=' . $forumId
           . '&author=' . urlencode($author) . '&sort=' . $sort . '&period=' . $period;

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>Поиск по форуму</h1>
    <p class="page-subtitle">Полнотекстовый поиск по темам и сообщениям</p>
</div>

<form method="GET" action="/search.php" class="search-panel">
    <div class="search-main">
        <input type="text" name="q" class="form-control search-input" value="<?= escape($q) ?>"
               placeholder="Что ищем? Можно фразу в кавычках" maxlength="100" autofocus>
        <button class="btn btn-primary">Найти</button>
    </div>

    <div class="search-filters">
        <select name="scope" class="form-control form-control-sm">
            <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>Везде</option>
            <option value="topics" <?= $scope === 'topics' ? 'selected' : '' ?>>Только темы</option>
            <option value="posts" <?= $scope === 'posts' ? 'selected' : '' ?>>Только сообщения</option>
        </select>

        <select name="forum_id" class="form-control form-control-sm">
            <option value="0">Все разделы</option>
            <?php foreach ($forumsList as $f): ?>
                <option value="<?= (int)$f['id'] ?>" <?= $forumId === (int)$f['id'] ? 'selected' : '' ?>><?= escape($f['title']) ?></option>
            <?php endforeach; ?>
        </select>

        <input type="text" name="author" class="form-control form-control-sm" value="<?= escape($author) ?>" placeholder="Автор (ник)">

        <select name="period" class="form-control form-control-sm">
            <option value="any" <?= $period === 'any' ? 'selected' : '' ?>>За всё время</option>
            <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>За неделю</option>
            <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>За месяц</option>
            <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>За год</option>
        </select>

        <select name="sort" class="form-control form-control-sm">
            <option value="relevance" <?= $sort === 'relevance' ? 'selected' : '' ?>>По релевантности</option>
            <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>Сначала новые</option>
            <option value="old" <?= $sort === 'old' ? 'selected' : '' ?>>Сначала старые</option>
        </select>

        <a href="/search.php" class="btn btn-outline btn-sm">Сброс</a>
    </div>
</form>

<?php if ($error): ?>
    <div class="alert alert-error"><?= escape($error) ?></div>
<?php endif; ?>

<?php if ($ft === '' && !$error): ?>
    <div class="empty-state">
        <div class="empty-icon">🔍</div>
        <h3>Введите запрос</h3>
        <p>Поддерживаются фразы в кавычках: "точное совпадение"</p>
    </div>

    <?php if ($popular): ?>
        <h2 class="section-title">Часто ищут</h2>
        <div class="search-tags">
            <?php foreach ($popular as $p): ?>
                <a href="/search.php?q=<?= urlencode($p['query']) ?>" class="search-tag">
                    <?= escape($p['query']) ?> <span><?= (int)$p['cnt'] ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif (!$error): ?>
    <div class="search-summary">
        Найдено: <strong><?= $total ?></strong>
        <?php if ($totalPages > 1): ?><span class="muted">· страница <?= $page ?> из <?= $totalPages ?></span><?php endif; ?>
    </div>

    <?php if (empty($results)): ?>
        <div class="empty-state empty-state-sm">
            <div class="empty-icon">🤷</div>
            <h3>Ничего не найдено</h3>
            <p>Попробуйте изменить запрос или снять фильтры.</p>
        </div>
    <?php else: ?>
        <div class="search-results">
            <?php foreach ($results as $r): ?>
                <?php
                $url = '/topic.php?id=' . (int)$r['topic_id'];
                if ($r['kind'] === 'post') $url .= '#post-' . (int)$r['id'];
                $snippet = searchSnippet($r['content'], $q);
                ?>
                <a href="<?= escape($url) ?>" class="search-row">
                    <div class="search-row-head">
                        <span class="badge <?= $r['kind'] === 'topic' ? 'badge-pin' : 'badge-closed' ?>">
                            <?= $r['kind'] === 'topic' ? 'Тема' : 'Сообщение' ?>
                        </span>
                        <span class="search-title"><?= highlightWords(escape($r['title']), $q) ?></span>
                    </div>
                    <div class="search-snippet"><?= highlightWords(escape($snippet), $q) ?></div>
                    <div class="search-meta">
                        <img src="<?= avatarUrl($r['avatar']) ?>" alt="" class="search-avatar">
                        <?= escape($r['username']) ?>
                        · <?= escape($r['forum_title']) ?>
                        · <?= date('d.m.Y H:i', strtotime($r['created_at'])) ?>
                        <?php if ($r['kind'] === 'topic'): ?>
                            · <?= (int)$r['replies_count'] ?> отв.
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php renderPagination($page, $totalPages, $queryBase); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>