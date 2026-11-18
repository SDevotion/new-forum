<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';

$pageTitle = 'Форум';
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
           lp.content   AS last_content,
           lp.created_at AS last_date,
           lu.username  AS last_username,
           lt.id        AS last_topic_id,
           lt.title     AS last_topic_title
    FROM forums f
    LEFT JOIN posts lp ON lp.id = f.last_post_id AND (lp.is_hidden = 0 OR ? = 1)
    LEFT JOIN users lu ON lu.id = lp.user_id
    LEFT JOIN topics lt ON lt.id = lp.topic_id AND (lt.is_hidden = 0 OR ? = 1)
    WHERE f.category_id = ? AND f.is_active = 1
    ORDER BY f.sort_order ASC, f.id ASC
");

require_once __DIR__ . '/includes/header.php';
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
        $forumsStmt->execute([$staffFlag, $staffFlag, $category['id']]);
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
                                <span class="stat-value"><?= (int)$forum['topics_count'] ?></span>
                                <span class="stat-name">тем</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?= (int)$forum['posts_count'] ?></span>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>