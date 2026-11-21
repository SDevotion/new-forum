<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/editing.php';
require_once __DIR__ . '/includes/markdown.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/attachments.php';

$db = Database::getInstance()->getConnection();
$topicId = (int)($_GET['id'] ?? 0);

$isStaff = isModerator();

$stmt = $db->prepare("
    SELECT t.*, 
           u.username, u.avatar, u.role, u.signature, u.id AS author_id,
           eu.username AS editor_name,
           f.title AS forum_title, f.id AS forum_id,
           c.title AS category_title
    FROM topics t
    JOIN users u ON u.id = t.user_id
    JOIN forums f ON f.id = t.forum_id
    JOIN categories c ON c.id = f.category_id
    LEFT JOIN users eu ON eu.id = t.edited_by
    WHERE t.id = ? AND f.is_active = 1 AND c.is_active = 1
");
$stmt->execute([$topicId]);
$topic = $stmt->fetch();

if (!$topic || ($topic['is_hidden'] && !$isStaff)) {
    http_response_code(404);
    $pageTitle = 'Тема не найдена';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="empty-state"><div class="empty-icon">🔍</div><h3>Тема не найдена</h3><a href="/index.php" class="btn btn-primary">Вернуться к форуму</a></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Счётчик просмотров: только авторизованные участники (не автор и не стафф),
// не чаще раза за сессию. Гости/боты не учитываются — метрику нельзя накрутить
// массовым созданием гостевых сессий. Отметки живут в payload, FIFO-лимит 500.
$isAuthorView = isLoggedIn() && (int)$_SESSION['user_id'] === (int)$topic['user_id'];
if (isLoggedIn() && !$isStaff && !$isAuthorView && sessionMarkOnce('viewed_topics', $topicId)) {
    $db->prepare("UPDATE topics SET views_count = views_count + 1 WHERE id = ?")->execute([$topicId]);
    $topic['views_count']++;
}

$pageTitle = $topic['title'];
$currentUser = getCurrentUser();

// Пагинация ответов
require_once __DIR__ . '/includes/pagination.php';
$perPage = max(1, setting('per_page_posts', 15));
$page = max(1, (int)($_GET['page'] ?? 1));

$stmt = $db->prepare("SELECT COUNT(*) FROM posts WHERE topic_id = ? AND (is_hidden = 0 OR ? = 1)");
$stmt->execute([$topicId, $isStaff ? 1 : 0]);
$visibleReplies = (int)$stmt->fetchColumn();
$topic['replies_count'] = $visibleReplies;

$totalPages = max(1, (int)ceil($visibleReplies / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare("
    SELECT p.*, u.username, u.avatar, u.role, u.signature, u.id AS author_id,
           eu.username AS editor_name
    FROM posts p
    JOIN users u ON u.id = p.user_id
    LEFT JOIN users eu ON eu.id = p.edited_by
    WHERE p.topic_id = ? AND (p.is_hidden = 0 OR ? = 1)
    ORDER BY p.created_at ASC, p.id ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute([$topicId, $isStaff ? 1 : 0]);
$posts = $stmt->fetchAll();

// Предзагрузка упоминаний одним запросом на всю страницу
mdPrimeMentions(array_merge([$topic['content']], array_column($posts, 'content')));

// Вложения: первое сообщение + ответы текущей страницы
$topicAttachments = attachForObject('topic', $topicId);
$postAttachments = attachForTargets('post', array_column($posts, 'id'));

$allForums = $isStaff ? $db->query("SELECT id, title FROM forums WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll() : [];
$backUrl = '/topic.php?id=' . $topicId . '&page=' . $page;

$roles = ['user' => 'Участник', 'moderator' => 'Модератор', 'admin' => 'Администратор'];
$forumClosed = setting('forum_closed', false) && !isModerator();
$canReply = isLoggedIn() && !$topic['is_closed'] && !$topic['is_hidden'] && !$forumClosed;
$subscribed = isSubscribed($topicId);

// Флаги редактирования: автор не правит скрытое/закрытое, стафф — всегда
$topicEditFlags = ['is_hidden' => (bool)$topic['is_hidden'], 'topic_closed' => (bool)$topic['is_closed']];

require_once __DIR__ . '/includes/header.php';
?>

<nav class="breadcrumbs">
    <a href="/index.php">Главная</a>
    <span class="sep">›</span>
    <a href="/section.php?id=<?= (int)$topic['forum_id'] ?>"><?= escape($topic['forum_title']) ?></a>
    <span class="sep">›</span>
    <span class="current"><?= escape($topic['title']) ?></span>
</nav>

<div class="topic-header">
    <div class="topic-badges">
        <?php if ($topic['is_pinned']): ?><span class="badge badge-pin">📌 Закреплена</span><?php endif; ?>
        <?php if ($topic['is_closed']): ?><span class="badge badge-closed">🔒 Закрыта</span><?php endif; ?>
    </div>
    <h1><?= escape($topic['title']) ?></h1>
    <?php if (isLoggedIn()): ?>
        <form method="POST" action="/notifications/subscribe.php" class="inline-form subscribe-form">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="topic_id" value="<?= $topicId ?>">
            <button class="btn btn-sm btn-outline subscribe-btn <?= $subscribed ? 'subscribe-active' : '' ?>">
                <?= $subscribed ? '🔔 Вы подписаны' : '🔕 Подписаться на ответы' ?>
            </button>
        </form>
    <?php endif; ?>

    <div class="topic-meta">
        <?php if (isLoggedIn() && (int)$currentUser['id'] !== (int)$topic['user_id']): ?>
            <button type="button" class="btn-link btn-report" data-type="topic" data-id="<?= $topicId ?>">Пожаловаться на тему</button>
        <?php endif; ?>
        <span>👁 <?= (int)$topic['views_count'] ?> просмотров</span>
        <span>💬 <?= (int)$topic['replies_count'] ?> ответов</span>
        <span>🕒 <?= date('d.m.Y H:i', strtotime($topic['created_at'])) ?></span>
    </div>
</div>

<?php if ($isStaff): ?>
    <div class="mod-toolbar">
        <span class="mod-toolbar-label">Модерация:</span>

        <?php foreach ([
            'topic_pin'   => $topic['is_pinned'] ? 'Открепить' : 'Закрепить',
            'topic_close' => $topic['is_closed'] ? 'Открыть' : 'Закрыть',
            'topic_hide'  => $topic['is_hidden'] ? 'Показать' : 'Скрыть',
        ] as $act => $label): ?>
            <form method="POST" action="/moderation/action.php" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                <input type="hidden" name="action" value="<?= $act ?>">
                <input type="hidden" name="id" value="<?= $topicId ?>">
                <input type="hidden" name="back" value="<?= escape($backUrl) ?>">
                <button class="btn btn-sm btn-outline"><?= $label ?></button>
            </form>
        <?php endforeach; ?>

        <form method="POST" action="/moderation/action.php" class="inline-form mod-move-form">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="action" value="topic_move">
            <input type="hidden" name="id" value="<?= $topicId ?>">
            <input type="hidden" name="back" value="<?= escape($backUrl) ?>">
            <select name="forum_id" class="form-control form-control-sm">
                <?php foreach ($allForums as $af): ?>
                    <option value="<?= (int)$af['id'] ?>" <?= (int)$af['id'] === (int)$topic['forum_id'] ? 'selected' : '' ?>><?= escape($af['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline">Перенести</button>
        </form>

        <form method="POST" action="/moderation/action.php" class="inline-form js-confirm-topic-delete">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="action" value="topic_delete">
            <input type="hidden" name="id" value="<?= $topicId ?>">
            <button class="btn btn-sm btn-danger">Удалить тему</button>
        </form>
    </div>

    <?php if ($topic['is_hidden']): ?>
        <div class="hidden-note">⚠️ Тема скрыта и видна только модерации.</div>
    <?php endif; ?>
<?php endif; ?>

<!-- Первое сообщение темы -->
<div class="post-card post-first">
    <div class="post-author">
        <a href="/profile.php?id=<?= (int)$topic['author_id'] ?>" class="post-author-link">
            <img src="<?= avatarUrl($topic['avatar']) ?>" alt="" class="post-avatar">
            <div class="post-author-name"><?= escape($topic['username']) ?></div>
        </a>
        <div class="post-author-role role-<?= escape($topic['role']) ?>">
            <?= $roles[$topic['role']] ?? 'Участник' ?>
        </div>
    </div>
    <div class="post-body">
        <div class="post-date"><?= date('d.m.Y H:i', strtotime($topic['created_at'])) ?></div>
        <div class="post-content"><?= mdRender($topic['content']) ?></div>
        <?= attachRender($topicAttachments) ?>

        <?php if ($topic['edit_count'] > 0): ?>
            <div class="post-edit-info">
                <span>✏️ Изменено <?= date('d.m.Y H:i', strtotime($topic['edited_at'])) ?><?= $topic['editor_name'] ? ' · ' . escape($topic['editor_name']) : '' ?></span>
                <a href="/history.php?type=topic&id=<?= $topicId ?>">История правок (<?= (int)$topic['edit_count'] ?>)</a>
            </div>
        <?php endif; ?>

        <?php if (canEdit($topic['user_id'], $topic['created_at'], $topicEditFlags)): ?>
            <div class="post-actions">
                <a href="/edit/index.php?type=topic&id=<?= $topicId ?>" class="btn-link">Редактировать</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($topic['signature'])): ?>
            <div class="post-signature"><?= nl2br(escape($topic['signature'])) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="posts-header">
    <h2>Ответы <span class="posts-count"><?= $visibleReplies ?></span></h2>
    <?php if ($totalPages > 1): ?>
        <span class="posts-page-info">Страница <?= $page ?> из <?= $totalPages ?></span>
    <?php endif; ?>
</div>

<?php if (empty($posts)): ?>
    <div class="empty-state empty-state-sm">
        <div class="empty-icon">💬</div>
        <h3>Ответов пока нет</h3>
        <p>Будьте первым, кто ответит в этой теме.</p>
    </div>
<?php else: ?>
    <?php foreach ($posts as $index => $post): ?>
        <?php
        $number = $offset + $index + 1;
        $isOwner = $currentUser && (int)$post['user_id'] === (int)$currentUser['id'];
        $postEditFlags = ['is_hidden' => (bool)$post['is_hidden'], 'topic_closed' => (bool)$topic['is_closed']];
        ?>
        <div class="post-card <?= $post['is_hidden'] ? 'post-hidden' : '' ?>" id="post-<?= (int)$post['id'] ?>">
            <div class="post-author">
                <a href="/profile.php?id=<?= (int)$post['author_id'] ?>" class="post-author-link">
                    <img src="<?= avatarUrl($post['avatar']) ?>" alt="" class="post-avatar">
                    <div class="post-author-name"><?= escape($post['username']) ?></div>
                </a>
                <div class="post-author-role role-<?= escape($post['role']) ?>">
                    <?= $roles[$post['role']] ?? 'Участник' ?>
                </div>
            </div>
            <div class="post-body">
                <div class="post-top">
                    <span class="post-date"><?= date('d.m.Y H:i', strtotime($post['created_at'])) ?></span>
                    <span class="post-number">#<?= $number ?></span>
                </div>
                <?php if ($post['is_hidden']): ?>
                    <div class="hidden-note">⚠️ Сообщение скрыто модератором.</div>
                <?php endif; ?>
                <div class="post-content"><?= mdRender($post['content']) ?></div>
                <?= attachRender($postAttachments[(int)$post['id']] ?? []) ?>

                <?php if (!empty($post['signature'])): ?>
                    <div class="post-signature"><?= nl2br(escape($post['signature'])) ?></div>
                <?php endif; ?>

                <?php if ($post['edit_count'] > 0): ?>
                    <div class="post-edit-info">
                        <span>✏️ Изменено <?= date('d.m.Y H:i', strtotime($post['updated_at'])) ?><?= $post['editor_name'] ? ' · ' . escape($post['editor_name']) : '' ?></span>
                        <a href="/history.php?type=post&id=<?= (int)$post['id'] ?>">История правок (<?= (int)$post['edit_count'] ?>)</a>
                    </div>
                <?php endif; ?>

                <div class="post-actions">
                    <?php if (canEdit($post['user_id'], $post['created_at'], $postEditFlags)): ?>
                        <a href="/edit/index.php?type=post&id=<?= (int)$post['id'] ?>" class="btn-link">Редактировать</a>
                    <?php endif; ?>

                    <?php if ($canReply): ?>
                        <button type="button"
                                class="btn-link btn-quote"
                                data-author="<?= escape($post['username']) ?>"
                                data-content="<?= escape($post['content']) ?>">Цитировать</button>
                    <?php endif; ?>

                    <?php if (isLoggedIn() && !$isOwner): ?>
                        <button type="button" class="btn-link btn-report"
                                data-type="post" data-id="<?= (int)$post['id'] ?>">Пожаловаться</button>
                    <?php endif; ?>

                    <?php if ($isStaff): ?>
                        <form method="POST" action="/moderation/action.php" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                            <input type="hidden" name="action" value="post_hide">
                            <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                            <input type="hidden" name="back" value="<?= escape($backUrl) ?>#post-<?= (int)$post['id'] ?>">
                            <button class="btn-link"><?= $post['is_hidden'] ? 'Показать' : 'Скрыть' ?></button>
                        </form>
                    <?php endif; ?>

                    <?php if ($isOwner || $isStaff): ?>
                        <form method="POST" action="/posts/delete.php" class="inline-form js-confirm-delete">
                            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
                            <input type="hidden" name="topic_id" value="<?= $topicId ?>">
                            <input type="hidden" name="post_id" value="<?= (int)$post['id'] ?>">
                            <button type="submit" class="btn-link btn-link-danger">Удалить</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php renderPagination($page, $totalPages, '/topic.php?id=' . $topicId); ?>
<?php endif; ?>

<!-- Форма ответа -->
<?php if ($canReply): ?>
    <div class="reply-box" id="reply">
        <h3 class="reply-title">Ваш ответ</h3>
        <form method="POST" action="/posts/reply.php" id="replyForm" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="topic_id" value="<?= $topicId ?>">

            <div class="form-group">
                <?php
                $mdName = 'content'; $mdId = 'replyContent'; $mdValue = '';
                $mdRows = 6; $mdRequired = true;
                require __DIR__ . '/includes/md_editor.php';
                ?>
                <small class="form-hint">Символов: <span id="replyCharCount">0</span> / <?= setting('message_max', 20000) ?></small>
            </div>

            <div class="form-group">
                <?php require __DIR__ . '/includes/attach_upload.php'; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Отправить ответ</button>
                <button type="button" class="btn btn-outline" id="clearQuote">Очистить</button>
            </div>
        </form>
    </div>
<?php elseif (!isLoggedIn()): ?>
    <div class="reply-notice">
        <a href="/auth/login.php">Войдите</a><?php if (setting('registration_enabled', true)): ?> или <a href="/auth/register.php">зарегистрируйтесь</a><?php endif; ?>, чтобы участвовать в обсуждении.
    </div>
<?php elseif ($forumClosed): ?>
    <div class="reply-notice">🔒 <?= escape(setting('forum_closed_message', 'Форум временно закрыт для новых сообщений')) ?></div>
<?php else: ?>
    <div class="reply-notice">🔒 Тема закрыта. Новые ответы недоступны.</div>
<?php endif; ?>

<?php if (isLoggedIn()): ?>
<div class="report-modal" id="reportModal" role="dialog" aria-modal="true" aria-labelledby="reportModalTitle">
    <div class="report-modal-card">
        <h3 id="reportModalTitle">Жалоба</h3>
        <form method="POST" action="/posts/report.php" id="reportForm">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="target_type" id="reportTargetType" value="topic">
            <input type="hidden" name="target_id" id="reportTargetId" value="0">
            <input type="hidden" name="back" value="<?= escape($backUrl) ?>">
            <div class="form-group">
                <label for="reportReason">Причина жалобы</label>
                <textarea id="reportReason" name="reason" class="form-control" rows="4" maxlength="<?= setting('report_max', 500) ?>"
                          placeholder="Спам, оскорбления, нарушение правил..." required></textarea>
                <small class="form-hint">От <?= setting('report_min', 5) ?> до <?= setting('report_max', 500) ?> символов</small>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Отправить</button>
                <button type="button" class="btn btn-outline js-modal-close">Отмена</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
