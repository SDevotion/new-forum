<?php
// profile/edit.php
//
// РЕШЕНО В ЭТОМ ФАЙЛЕ:
// [6.массовый 500] Весь ввод через postStr() — массивы в полях не фаталят.
// [2.9] Смена пароля: csrfReset() вместо легаси unset($_SESSION['csrf_token']).
// [new] Аватар: файл загружается ДО UPDATE, при ошибке UPDATE новый файл удаляется
//       (не остаётся сиротой до orphan-чистки); старый удаляется только после commit.
// Антибрутфорс смены пароля (лимит только по неудачным verify) — сохранён.

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/avatar.php';
require_once __DIR__ . '/../includes/ratelimit.php';

if (!isLoggedIn()) {
    setFlashMessage('error', 'Необходимо войти в систему');
    redirect('/auth/login.php');
}
enforceActiveUser();

$db = Database::getInstance()->getConnection();
$currentUser = getCurrentUser();
$userId = (int)$currentUser['id'];

$stmt = $db->prepare("SELECT username, email, avatar, bio, location, website, signature, notify_replies, notify_mentions, notify_email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

$pageTitle = 'Редактирование профиля';
$errors = [];
$passErrors = [];

$bio       = $user['bio'] ?? '';
$location  = $user['location'] ?? '';
$website   = $user['website'] ?? '';
$signature = $user['signature'] ?? '';
$notifyReplies = (bool)$user['notify_replies'];
$notifyMentions = (bool)$user['notify_mentions'];
$notifyEmail = (bool)($user['notify_email'] ?? false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken(postStr('csrf_token'))) {
        $errors[] = 'Недействительный запрос. Обновите страницу.';
    } else {
        $action = postStr('action');

        // --- Смена пароля ---
        if ($action === 'password') {
            $old = postStr('old_password');
            $new = postStr('new_password');
            $confirm = postStr('new_password_confirm');

            $pcKey = 'u' . $userId;
            $st = $db->prepare("SELECT COUNT(*) FROM rate_limits WHERE action = 'password_change' AND actor_key = ? AND created_at >= (NOW() - INTERVAL 900 SECOND)");
            $st->execute([$pcKey]);
            if ((int)$st->fetchColumn() >= 5) {
                $passErrors[] = 'Слишком много неудачных попыток. Подождите 15 минут.';
            } else {
                $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $hash = $stmt->fetchColumn();

                if (!password_verify($old, $hash)) {
                    $passErrors[] = 'Текущий пароль указан неверно';
                    $db->prepare("INSERT INTO rate_limits (action, actor_key, ip_address) VALUES ('password_change', ?, ?)")
                        ->execute([$pcKey, rateLimitIp()]);
                }
                if (mb_strlen($new) < setting('password_min', 6)) {
                    $passErrors[] = 'Новый пароль должен содержать минимум ' . setting('password_min', 6) . ' символов';
                }
                if (strlen($new) > 4096) {
                    $passErrors[] = 'Новый пароль слишком длинный (максимум 4096 байт)';
                }
                if ($new !== $confirm) {
                    $passErrors[] = 'Новые пароли не совпадают';
                }
                if ($old === $new && $old !== '') {
                    $passErrors[] = 'Новый пароль должен отличаться от текущего';
                }

                if (empty($passErrors)) {
                    $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);

                    session_regenerate_id(true);
                    $db->prepare("DELETE FROM user_sessions WHERE user_id = ? AND session_token <> ?")
                       ->execute([$userId, session_id()]);
                    csrfReset();
                    generateCSRFToken();
                    rateLimitClear('password_change', 'u' . $userId);
                    setFlashMessage('success', 'Пароль успешно изменён');
                    redirect('/profile/edit.php');
                }
            }

        // --- Удаление аватара ---
        } elseif ($action === 'delete_avatar') {
            $db->prepare("UPDATE users SET avatar = NULL WHERE id = ?")->execute([$userId]);
            deleteAvatarFile($user['avatar']);
            setFlashMessage('success', 'Аватар удалён');
            redirect('/profile/edit.php');

        // --- Основные данные ---
        } else {
            $bio       = trim(postStr('bio'));
            $location  = trim(postStr('location'));
            $website   = trim(postStr('website'));
            $signature = trim(postStr('signature'));
            $notifyReplies = isset($_POST['notify_replies']);
            $notifyMentions = isset($_POST['notify_mentions']);
            $notifyEmail = isset($_POST['notify_email']);

            if (mb_strlen($bio) > setting('bio_max', 1000)) {
                $errors[] = 'О себе: максимум ' . setting('bio_max', 1000) . ' символов';
            }
            if (mb_strlen($location) > setting('location_max', 100)) {
                $errors[] = 'Город: максимум ' . setting('location_max', 100) . ' символов';
            }
            if (mb_strlen($signature) > setting('signature_max', 500)) {
                $errors[] = 'Подпись: максимум ' . setting('signature_max', 500) . ' символов';
            }
            if ($website !== '') {
                if (mb_strlen($website) > setting('website_max', 255)) {
                    $errors[] = 'Ссылка на сайт слишком длинная';
                } elseif (!filter_var($website, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $website)) {
                    $errors[] = 'Укажите корректный адрес сайта (http:// или https://)';
                }
            }

            $newAvatar = null;
            if (empty($errors)) {
                [$ok, $msg, $path] = handleAvatarUpload($_FILES['avatar'] ?? [], $userId);
                if (!$ok) {
                    $errors[] = $msg;
                } else {
                    $newAvatar = $path;
                }
            }

            if (empty($errors)) {
                try {
                    if ($newAvatar !== null) {
                        $stmt = $db->prepare("
                            UPDATE users SET bio = ?, location = ?, website = ?, signature = ?, avatar = ?,
                                notify_replies = ?, notify_mentions = ?, notify_email = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $bio ?: null, $location ?: null, $website ?: null,
                            $signature ?: null, $newAvatar, (int)$notifyReplies, (int)$notifyMentions, (int)$notifyEmail, $userId
                        ]);
                        deleteAvatarFile($user['avatar']);
                    } else {
                        $stmt = $db->prepare("
                            UPDATE users SET bio = ?, location = ?, website = ?, signature = ?,
                                notify_replies = ?, notify_mentions = ?, notify_email = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $bio ?: null, $location ?: null, $website ?: null,
                            $signature ?: null, (int)$notifyReplies, (int)$notifyMentions, (int)$notifyEmail, $userId
                        ]);
                    }

                    setFlashMessage('success', 'Профиль обновлён');
                    redirect('/profile.php?id=' . $userId);
                } catch (PDOException $e) {
                    // Новый файл не привязан — убираем сразу, не ждём orphan-чистки.
                    if ($newAvatar !== null) deleteAvatarFile($newAvatar);
                    error_log("Profile update error: " . $e->getMessage());
                    $errors[] = 'Ошибка при сохранении. Попробуйте позже.';
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<nav class="breadcrumbs">
    <a href="/index.php">Главная</a>
    <span class="sep">›</span>
    <a href="/profile.php?id=<?= $userId ?>">Мой профиль</a>
    <span class="sep">›</span>
    <span class="current">Редактирование</span>
</nav>

<div class="form-card">
    <h1 class="form-card-title">Редактирование профиля</h1>
    <p class="form-card-subtitle"><?= escape($user['username']) ?> · <?= escape($user['email']) ?></p>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <ul class="error-list">
                <?php foreach ($errors as $e): ?><li><?= escape($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="" enctype="multipart/form-data" id="profileForm">
        <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
        <input type="hidden" name="action" value="profile">

        <div class="avatar-edit">
            <img src="<?= avatarUrl($user['avatar']) ?>" alt="" class="avatar-preview" id="avatarPreview">
            <div class="avatar-edit-controls">
                <label for="avatar">Аватар</label>
                <input type="file" id="avatar" name="avatar" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp">
                <small class="form-hint">JPG, PNG, GIF, WEBP. До <?= max(1, setting('avatar_max_size_mb', 2)) ?> МБ</small>
            </div>
        </div>

        <div class="form-group">
            <label for="bio">О себе</label>
            <textarea id="bio" name="bio" class="form-control" rows="4" maxlength="<?= setting('bio_max', 1000) ?>" placeholder="Несколько слов о себе..."><?= escape($bio) ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="location">Город</label>
                <input type="text" id="location" name="location" class="form-control" value="<?= escape($location) ?>" maxlength="<?= setting('location_max', 100) ?>" placeholder="Москва">
            </div>
            <div class="form-group">
                <label for="website">Сайт</label>
                <input type="url" id="website" name="website" class="form-control" value="<?= escape($website) ?>" maxlength="<?= setting('website_max', 255) ?>" placeholder="https://example.com">
            </div>
        </div>

        <div class="form-group">
            <label for="signature">Подпись</label>
            <textarea id="signature" name="signature" class="form-control" rows="2" maxlength="<?= setting('signature_max', 500) ?>" placeholder="Отображается под вашими сообщениями"><?= escape($signature) ?></textarea>
        </div>

        <div class="form-group">
            <label>Уведомления</label>
            <div class="checkbox-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="notify_replies" value="1" <?= $notifyReplies ? 'checked' : '' ?>>
                    Ответы в моих темах и подписках
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="notify_mentions" value="1" <?= $notifyMentions ? 'checked' : '' ?>>
                    Упоминания @ник и цитаты
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="notify_email" value="1" <?= $notifyEmail ? 'checked' : '' ?>>
                    Дублировать уведомления на email
                </label>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Сохранить</button>
            <a href="/profile.php?id=<?= $userId ?>" class="btn btn-outline">Отмена</a>
        </div>
    </form>

    <?php if (!empty($user['avatar'])): ?>
        <form method="POST" action="" class="inline-form js-confirm-avatar-delete avatar-delete-form">
            <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
            <input type="hidden" name="action" value="delete_avatar">
            <button type="submit" class="btn-link btn-link-danger">Удалить текущий аватар</button>
        </form>
    <?php endif; ?>
</div>

<div class="form-card">
    <h2 class="form-card-title form-card-title-sm">Смена пароля</h2>
    <p class="form-card-subtitle">Используйте надёжный пароль</p>

    <?php if (!empty($passErrors)): ?>
        <div class="alert alert-error">
            <ul class="error-list">
                <?php foreach ($passErrors as $e): ?><li><?= escape($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="passwordForm">
        <input type="hidden" name="csrf_token" value="<?= escape(generateCSRFToken()) ?>">
        <input type="hidden" name="action" value="password">

        <div class="form-group">
            <label for="old_password">Текущий пароль</label>
            <input type="password" id="old_password" name="old_password" class="form-control" maxlength="4096" required>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="new_password">Новый пароль</label>
                <input type="password" id="new_password" name="new_password" class="form-control" minlength="<?= setting('password_min', 6) ?>" maxlength="4096" required>
            </div>
            <div class="form-group">
                <label for="new_password_confirm">Повторите новый</label>
                <input type="password" id="new_password_confirm" name="new_password_confirm" class="form-control" minlength="<?= setting('password_min', 6) ?>" maxlength="4096" required>
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Изменить пароль</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>