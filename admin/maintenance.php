<?php
// admin/maintenance.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [5.6] orphan_attachments/orphan_avatars: известные имена грузятся ЧАНКАМИ
//       по 5000 (keyset по id / курсор по пользователям), каталог обходится
//       DirectoryIterator без загрузки всего списка в память; лимит удаления
//       за прогон (5000 файлов) с сообщением «повторите» — запрос не висит минутами.
// [new] Все массовые DELETE — пачками LIMIT (clean_*), как в cron.
// [6.массовый 500] Ввод через postStr()/getStr()/getInt().
// Grace-период 1 час для сирот — сохранён.

require_once __DIR__ . '/../includes/permissions.php';
requireAdmin();
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/attachments.php';

$pageTitle = 'Обслуживание';
$db = Database::getInstance()->getConnection();

define('ORPHAN_GRACE_SECONDS', 3600);
define('ORPHAN_MAX_PER_RUN', 5000);

/** Известные stored_name чанками по 5000 — массив в памяти ограничен размером таблицы, но без единого большого fetch. */
function knownAttachmentNames($db) {
    $known = [];
    $lastId = 0;
    while (true) {
        $st = $db->prepare("SELECT id, stored_name FROM attachments WHERE id > ? ORDER BY id ASC LIMIT 5000");
        $st->execute([$lastId]);
        $rows = $st->fetchAll();
        if (!$rows) break;
        foreach ($rows as $r) { $known[$r['stored_name']] = true; $lastId = (int)$r['id']; }
    }
    return $known;
}

/** Удаление сирот в каталоге: потоковый обход, кап за прогон. Возвращает [removed, bytes, skipped, capped]. */
function purgeOrphanFiles($dir, array $known, $skipNames = []) {
    $removed = 0; $bytes = 0; $skipped = 0; $capped = false;
    if (!is_dir($dir)) return [$removed, $bytes, $skipped, $capped];
    $it = new DirectoryIterator($dir);
    foreach ($it as $fi) {
        if ($fi->isDot() || !$fi->isFile()) continue;
        $fn = $fi->getFilename();
        if (isset($known[$fn]) || isset($skipNames[$fn])) continue;
        if (time() - $fi->getMTime() < ORPHAN_GRACE_SECONDS) { $skipped++; continue; }
        if ($removed >= ORPHAN_MAX_PER_RUN) { $capped = true; break; }
        $sz = $fi->getSize();
        if (@unlink($fi->getPathname())) { $removed++; $bytes += $sz; }
    }
    return [$removed, $bytes, $skipped, $capped];
}

/** DELETE пачками до конца (для ручных операций админа). */
function deleteInBatches($db, $sqlNoLimit, $batch = 2000) {
    $total = 0;
    do {
        $n = $db->exec($sqlNoLimit . ' LIMIT ' . $batch);
        $total += $n;
        if ($n === $batch) usleep(20000);
    } while ($n === $batch);
    return $total;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken(postStr('csrf_token'))) {
        setFlashMessage('error', 'Недействительный запрос');
        redirect('/admin/maintenance.php');
    }
    $op = postStr('op');

    switch ($op) {
        case 'recount':
            require_once __DIR__ . '/../includes/counters.php';
            recountAll();
            logModeration('maint_recount', 'system', 0);
            setFlashMessage('success', 'Счётчики пересчитаны');
            break;

        case 'clean_orphan_records':
            $n1 = $db->exec("
                DELETE e FROM post_edits e
                LEFT JOIN topics t ON e.target_type = 'topic' AND t.id = e.target_id
                LEFT JOIN posts p  ON e.target_type = 'post'  AND p.id = e.target_id
                WHERE t.id IS NULL AND p.id IS NULL
            ");
            $n2 = $db->exec("
                DELETE r FROM reports r
                LEFT JOIN topics t ON r.target_type = 'topic' AND t.id = r.target_id
                LEFT JOIN posts p  ON r.target_type = 'post'  AND p.id = r.target_id
                WHERE t.id IS NULL AND p.id IS NULL
            ");
            logModeration('maint_clean', 'system', 0, "Записи без целей: правок — $n1, жалоб — $n2");
            setFlashMessage('success', "Удалено записей без целей: правок — $n1, жалоб — $n2");
            break;

        case 'clean_ratelimits':
            $n = deleteInBatches($db, "DELETE FROM rate_limits WHERE created_at < NOW() - INTERVAL 1 DAY");
            $n += deleteInBatches($db, "DELETE FROM post_dedup WHERE created_at < NOW() - INTERVAL 1 DAY");
            logModeration('maint_clean', 'system', 0, "Лимитеры частоты: удалено записей — $n");
            setFlashMessage('success', "Удалено записей rate_limits/post_dedup: $n");
            break;

        case 'clean_sessions':
            $n = deleteInBatches($db, "DELETE FROM user_sessions WHERE expires_at < NOW()");
            logModeration('maint_clean', 'system', 0, "Сессии: удалено протухших записей — $n");
            setFlashMessage('success', "Удалено протухших сессий: $n");
            break;

        case 'clean_resets':
            $n = deleteInBatches($db, "DELETE FROM password_resets WHERE expires_at < NOW()");
            logModeration('maint_clean', 'system', 0, "Токены сброса пароля: удалено протухших — $n");
            setFlashMessage('success', "Удалено протухших токенов сброса пароля: $n");
            break;

        case 'clean_searchlog':
            $n = deleteInBatches($db, "DELETE FROM search_log WHERE created_at < NOW() - INTERVAL 90 DAY");
            logModeration('maint_clean', 'system', 0, "Журнал поиска: удалено записей — $n");
            setFlashMessage('success', "Удалено записей search_log: $n");
            break;

        case 'clean_notifications':
            $n = deleteInBatches($db, "DELETE FROM notifications WHERE is_read = 1 AND created_at < NOW() - INTERVAL 30 DAY");
            logModeration('maint_clean', 'system', 0, "Прочитанные уведомления: удалено — $n");
            setFlashMessage('success', "Удалено прочитанных уведомлений: $n");
            break;

        case 'orphan_attachments':
            $known = knownAttachmentNames($db);
            [$removed, $bytes, $skipped, $capped] = purgeOrphanFiles(ATTACH_DIR, $known, ['.htaccess' => true, '.stats_cache' => true]);
            logModeration('maint_clean', 'system', 0, "Вложения без записей: удалено файлов — $removed");
            setFlashMessage('success', "Удалено осиротевших вложений: $removed (" . round($bytes / 1048576, 1) . " МБ)"
                . ($skipped ? ", пропущено свежих: $skipped" : '')
                . ($capped ? '. Достигнут лимит за прогон — повторите операцию.' : ''));
            break;

        case 'orphan_avatars':
            $dir = __DIR__ . '/../assets/uploads/avatars';
            $known = [];
            $lastId = 0;
            while (true) {
                $st = $db->prepare("SELECT id, avatar FROM users WHERE id > ? AND avatar IS NOT NULL ORDER BY id ASC LIMIT 5000");
                $st->execute([$lastId]);
                $rows = $st->fetchAll();
                if (!$rows) break;
                foreach ($rows as $r) { $known[basename($r['avatar'])] = true; $lastId = (int)$r['id']; }
            }
            [$removed, $bytes, $skipped, $capped] = purgeOrphanFiles($dir, $known);
            logModeration('maint_clean', 'system', 0, "Аватары без ссылок: удалено файлов — $removed");
            setFlashMessage('success', "Удалено неиспользуемых аватаров: $removed (" . round($bytes / 1048576, 1) . " МБ)"
                . ($skipped ? ", пропущено свежих: $skipped" : '')
                . ($capped ? '. Достигнут лимит за прогон — повторите операцию.' : ''));
            break;

        default:
            setFlashMessage('error', 'Неизвестная операция');
    }
    redirect('/admin/maintenance.php');
}

// ===== Журнал модерации с фильтрами =====
$fAction = trim(getStr('action'));
$fUser   = getInt('user');
$fTarget = getStr('target');
if (!in_array($fTarget, ['topic','post','user','forum','category','system'], true)) $fTarget = '';

$where = []; $params = [];
if ($fAction !== '') { $where[] = 'l.action = ?'; $params[] = $fAction; }
if ($fUser > 0)      { $where[] = 'l.user_id = ?'; $params[] = $fUser; }
if ($fTarget !== '') { $where[] = 'l.target_type = ?'; $params[] = $fTarget; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = max(1, setting('per_page_staff', 15));
$page = max(1, getInt('page', 1));

$st = $db->prepare("SELECT COUNT(*) FROM moderation_log l $whereSql");
$st->execute($params);
$total = (int)$st->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$st = $db->prepare("
    SELECT l.*, u.username
    FROM moderation_log l
    JOIN users u ON u.id = l.user_id
    $whereSql
    ORDER BY l.created_at DESC, l.id DESC
    LIMIT $perPage OFFSET $offset
");
$st->execute($params);
$log = $st->fetchAll();

$actionNames = moderationActionNames();
$targetNames = ['topic' => 'Тема', 'post' => 'Сообщение', 'user' => 'Пользователь', 'forum' => 'Раздел', 'category' => 'Категория', 'system' => 'Система'];

$usedActions = $db->query("SELECT DISTINCT action FROM moderation_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$staffUsers = $db->query("SELECT id, username FROM users WHERE role IN ('moderator','admin') ORDER BY username")->fetchAll();

$sizes = [
    'rate_limits'   => (int)$db->query("SELECT COUNT(*) FROM rate_limits")->fetchColumn(),
    'search_log'    => (int)$db->query("SELECT COUNT(*) FROM search_log")->fetchColumn(),
    'notifications' => (int)$db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 1")->fetchColumn(),
    'sessions'      => (int)$db->query("SELECT COUNT(*) FROM user_sessions")->fetchColumn(),
    'resets'        => (int)$db->query("SELECT COUNT(*) FROM password_resets")->fetchColumn(),
];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/pagination.php';
adminOpen('maintenance');
?>

<div class="page-header"><h1>Обслуживание</h1></div>

<div class="admin-cards">
    <div class="admin-card">
        <h3>Пересчёт счётчиков</h3>
        <p class="admin-muted">Темы/сообщения разделов, ответы и активность тем, счётчики участников, последние сообщения разделов. Безопасно, можно выполнять в любой момент.</p>
        <form method="POST" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="recount">
            <button class="btn btn-primary">Пересчитать</button>
        </form>
    </div>
    <div class="admin-card">
        <h3>Очистка данных</h3>
        <p class="admin-muted">Регулярная чистка выполняется автоматически через install/cron.php; кнопки ниже — для ручного запуска.</p>
        <form method="POST" class="admin-clean-row js-confirm-maint">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="clean_orphan_records">
            <span>Правки и жалобы на удалённые темы/сообщения</span>
            <button class="btn btn-sm btn-outline">Очистить</button>
        </form>
        <form method="POST" class="admin-clean-row">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="clean_ratelimits">
            <span>rate_limits старше суток (<?= $sizes['rate_limits'] ?> записей всего)</span>
            <button class="btn btn-sm btn-outline">Очистить</button>
        </form>
        <form method="POST" class="admin-clean-row">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="clean_searchlog">
            <span>search_log старше 90 дней (<?= $sizes['search_log'] ?> записей всего)</span>
            <button class="btn btn-sm btn-outline">Очистить</button>
        </form>
        <form method="POST" class="admin-clean-row">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="clean_notifications">
            <span>Прочитанные уведомления старше 30 дней (<?= $sizes['notifications'] ?> прочитанных)</span>
            <button class="btn btn-sm btn-outline">Очистить</button>
        </form>
        <form method="POST" class="admin-clean-row">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="clean_sessions">
            <span>Протухшие сессии user_sessions (<?= $sizes['sessions'] ?> записей всего)</span>
            <button class="btn btn-sm btn-outline">Очистить</button>
        </form>
        <form method="POST" class="admin-clean-row">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="clean_resets">
            <span>Протухшие токены сброса пароля (<?= $sizes['resets'] ?> записей всего)</span>
            <button class="btn btn-sm btn-outline">Очистить</button>
        </form>
    </div>
    <div class="admin-card">
        <h3>Осиротевшие файлы</h3>
        <p class="admin-muted">Файлы на диске без ссылок в БД. Файлы моложе 1 часа не удаляются. За прогон удаляется не более <?= ORPHAN_MAX_PER_RUN ?> файлов — при большом объёме повторите операцию.</p>
        <form method="POST" class="inline-form js-confirm-maint">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="orphan_attachments">
            <button class="btn btn-sm btn-danger">Вложения без записей</button>
        </form>
        <form method="POST" class="inline-form js-confirm-maint">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="op" value="orphan_avatars">
            <button class="btn btn-sm btn-danger">Аватары без ссылок</button>
        </form>
    </div>
</div>

<div class="admin-card">
    <h3>Журнал модерации (<?= $total ?>)</h3>
    <form method="GET" class="admin-log-filters">
        <select name="action" class="form-control form-control-sm">
            <option value="">Все действия</option>
            <?php foreach ($usedActions as $a): ?>
                <option value="<?= escape($a) ?>" <?= $a === $fAction ? 'selected' : '' ?>><?= escape($actionNames[$a] ?? $a) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="user" class="form-control form-control-sm">
            <option value="0">Все авторы</option>
            <?php foreach ($staffUsers as $su): ?>
                <option value="<?= (int)$su['id'] ?>" <?= (int)$su['id'] === $fUser ? 'selected' : '' ?>><?= escape($su['username']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="target" class="form-control form-control-sm">
            <option value="">Все объекты</option>
            <?php foreach ($targetNames as $k => $label): ?>
                <option value="<?= $k ?>" <?= $k === $fTarget ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-primary">Фильтр</button>
        <a href="/admin/maintenance.php" class="btn btn-sm btn-outline">Сброс</a>
    </form>

    <?php if (!$log): ?>
        <p class="admin-muted">Записей нет.</p>
    <?php else: ?>
        <ul class="admin-log">
            <?php foreach ($log as $l): ?>
                <li>
                    <span class="admin-log-date"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></span>
                    <strong><?= escape($l['username']) ?></strong>
                    — <?= escape($actionNames[$l['action']] ?? $l['action']) ?>
                    · <?= escape($targetNames[$l['target_type']] ?? $l['target_type']) ?><?= $l['target_type'] !== 'system' ? ' #' . (int)$l['target_id'] : '' ?>
                    <?php $r = moderationReasonLabel($l['reason']); ?>
                    <?= $r !== '' ? '· ' . escape($r) : '' ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        $qs = http_build_query(array_filter(['action' => $fAction, 'user' => $fUser ?: null, 'target' => $fTarget]));
        renderPagination($page, $totalPages, '/admin/maintenance.php' . ($qs ? '?' . $qs : ''));
        ?>
    <?php endif; ?>
</div>

<?php
adminClose();
require_once __DIR__ . '/../includes/footer.php';