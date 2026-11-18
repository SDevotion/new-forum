<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Главная';
$currentUser = getCurrentUser();

require_once __DIR__ . '/includes/header.php';
?>

<div class="hero">
    <h1>Добро пожаловать на ForumHub</h1>
    <p class="hero-subtitle">Место для интересных обсуждений и обмена знаниями</p>
    
    <?php if (!$currentUser): ?>
        <div class="hero-actions">
            <a href="/auth/register.php" class="btn btn-primary btn-lg">Присоединиться</a>
            <a href="/auth/login.php" class="btn btn-outline btn-lg">Войти</a>
        </div>
    <?php else: ?>
        <div class="hero-actions">
            <a href="/topics/create.php" class="btn btn-primary btn-lg">Создать тему</a>
            <a href="/topics.php" class="btn btn-outline btn-lg">Все темы</a>
        </div>
    <?php endif; ?>
</div>

<div class="features">
    <div class="feature-card">
        <div class="feature-icon">📝</div>
        <h3>Создавайте темы</h3>
        <p>Делитесь своими мыслями и задавайте вопросы сообществу</p>
    </div>
    
    <div class="feature-card">
        <div class="feature-icon">💬</div>
        <h3>Общайтесь</h3>
        <p>Участвуйте в обсуждениях и помогайте другим участникам</p>
    </div>
    
    <div class="feature-card">
        <div class="feature-icon">👥</div>
        <h3>Сообщество</h3>
        <p>Находите единомышленников и расширяйте круг общения</p>
    </div>
</div>

<?php
$totals = getForumTotals();
$online = getOnlineList();
?>
<div class="stats-section">
    <h2>Статистика форума</h2>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= $totals['topics'] ?></div>
            <div class="stat-label">Тем</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $totals['posts'] ?></div>
            <div class="stat-label">Сообщений</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $totals['users'] ?></div>
            <div class="stat-label">Участников</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $online['count'] ?></div>
            <div class="stat-label">Онлайн</div>
        </div>
    </div>

    <?php if ($totals['newest']): ?>
        <p class="stats-newest">
            Новый участник: <a href="/profile.php?id=<?= (int)$totals['newest']['id'] ?>"><?= escape($totals['newest']['username']) ?></a>
            · <a href="/stats.php">Подробная статистика →</a>
        </p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>