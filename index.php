<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';

$pageTitle = 'Главная';
$db = Database::getInstance()->getConnection();

// Получаем категории с разделами
$categories = $db->query("
    SELECT * FROM categories 
    WHERE is_active = 1 
    ORDER BY sort_order ASC, id ASC
")->fetchAll();

$staffFlag = isModerator() ? 1 : 0;

$forumsStmt = $db->prepare("
    SELECT f.*,
           CASE WHEN ? = 1 THEN f.topics_count
                ELSE (SELECT COUNT(*) FROM topics vt WHERE vt.forum_id = f.id AND vt.is_hidden = 0)
           END AS visible_topics_count,
           CASE WHEN ? = 1 THEN f.posts_count
                ELSE (SELECT COUNT(*) FROM topics vt WHERE vt.forum_id = f.id AND vt.is_hidden = 0)
                   + (SELECT COUNT(*) FROM posts vp JOIN topics vt ON vt.id = vp.topic_id
                      WHERE vt.forum_id = f.id AND vt.is_hidden = 0 AND vp.is_hidden = 0)
           END AS visible_posts_count,
           la.last_date,
           la.last_username,
           la.last_topic_id,
           la.last_topic_title
    FROM forums f
    LEFT JOIN LATERAL (
        SELECT x.created_at AS last_date, x.username AS last_username,
               x.topic_id AS last_topic_id, x.title AS last_topic_title
        FROM (
            SELECT p.created_at, u.username, t.id AS topic_id, t.title, 1 AS prio
            FROM posts p
            JOIN topics t ON t.id = p.topic_id
            JOIN users u ON u.id = p.user_id
            WHERE t.forum_id = f.id AND (p.is_hidden = 0 OR ? = 1) AND (t.is_hidden = 0 OR ? = 1)
            UNION ALL
            SELECT t.created_at, u.username, t.id, t.title, 2 AS prio
            FROM topics t
            JOIN users u ON u.id = t.user_id
            WHERE t.forum_id = f.id AND (t.is_hidden = 0 OR ? = 1)
              AND NOT EXISTS (SELECT 1 FROM posts p WHERE p.topic_id = t.id
                              AND (p.is_hidden = 0 OR ? = 1))
        ) x
        ORDER BY x.created_at DESC, x.prio ASC, x.topic_id DESC
        LIMIT 1
    ) la ON TRUE
    WHERE f.category_id = ? AND f.is_active = 1
    ORDER BY f.sort_order ASC, f.id ASC
");

// Кэш собранной разметки списков для гостей: LATERAL-запрос дорогой,
// а для не-стаффа результат одинаков. Стафф всегда видит живые данные.
$indexCacheKey = 'forum_index_html_v1';
$indexCached = false;
$indexHtml = '';
if (!$staffFlag && function_exists('apcu_fetch') && (!function_exists('apcu_enabled') || apcu_enabled())) {
    $hit = apcu_fetch($indexCacheKey, $ok);
    if ($ok && is_string($hit)) { $indexHtml = $hit; $indexCached = true; }
}

require_once __DIR__ . '/includes/header.php';

if ($indexCached) {
    echo $indexHtml;
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
ob_start();
?>

<div class="page-header">
    <h1>Форум</h1>
    <p class="page-subtitle">Выберите раздел для просмотра тем</p>
</div>

<?php if (empty($categories)): ?>
    <div class="empty-state">
        <div class="empty-icon">📭</div>
        <h3>Разделы пока не созданы</h3>
        <p>Администратор ещё не добавил категории форума.</p>
    </div>
<?php else: ?>
    <?php foreach ($categories as $category): ?>
        <?php
        $forumsStmt->execute([$staffFlag, $staffFlag, $staffFlag, $staffFlag, $staffFlag, $staffFlag, $category['id']]);
        $forums = $forumsStmt->fetchAll();
        if (empty($forums)) continue;
        ?>
        <div class="category-block">
            <div class="category-header">
                <h2><?= escape($category['title']) ?></h2>
                <?php if ($category['description']): ?>
                    <p><?= escape($category['description']) ?></p>
                <?php endif; ?>
            </div>

            <div class="forum-list">
                <?php foreach ($forums as $forum): ?>
                    <a href="/section.php?id=<?= (int)$forum['id'] ?>" class="forum-row">
                        <div class="forum-icon"><?= escape($forum['icon']) ?></div>
                        <div class="forum-info">
                            <div class="forum-title"><?= escape($forum['title']) ?></div>
                            <?php if ($forum['description']): ?>
                                <div class="forum-desc"><?= escape($forum['description']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="forum-stats">
                            <div class="stat-item">
                                <span class="stat-value"><?= (int)$forum['visible_topics_count'] ?></span>
                                <span class="stat-name">тем</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?= (int)$forum['visible_posts_count'] ?></span>
                                <span class="stat-name">сообщений</span>
                            </div>
                        </div>
                        <div class="forum-last-post">
                            <?php if ($forum['last_topic_id']): ?>
                                <div class="last-topic-title"><?= escape(mb_strimwidth($forum['last_topic_title'], 0, 32, '…')) ?></div>
                                <div class="last-topic-meta">
                                    <?= escape($forum['last_username']) ?>
                                    · <?= date('d.m.Y H:i', strtotime($forum['last_date'])) ?>
                                </div>
                            <?php else: ?>
                                <span class="no-posts">Нет сообщений</span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
$indexHtml = ob_get_flush();
if (!$staffFlag && function_exists('apcu_store') && (!function_exists('apcu_enabled') || apcu_enabled())) {
    apcu_store($indexCacheKey, $indexHtml, 30);
}
require_once __DIR__ . '/includes/footer.php';
?>
