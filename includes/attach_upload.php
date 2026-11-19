<?php
/**
 * Блок выбора вложений. Ожидает: $attachExisting (опц., массив строк attachments для edit).
 * Форма-родитель обязана иметь enctype="multipart/form-data".
 */
$attachExisting = $attachExisting ?? [];
if (attachMaxFiles() === 0 && !$attachExisting) return; // загрузка отключена
?>
<div class="attach-upload" data-max-files="<?= attachMaxFiles() ?>" data-max-size="<?= attachMaxSize() ?>">
    <label class="attach-upload-label">📎 Вложения</label>
    <?php if ($attachExisting): ?>
        <div class="attach-existing">
            <?php foreach ($attachExisting as $ae): ?>
                <label class="attach-existing-item">
                    <input type="checkbox" name="attach_remove[]" value="<?= (int)$ae['id'] ?>">
                    <span class="attach-existing-name"><?= escape($ae['orig_name']) ?></span>
                    <span class="attach-file-size"><?= attachFormatSize($ae['size_bytes']) ?></span>
                    <span class="attach-remove-mark">удалить</span>
                </label>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <input type="file" name="attachments[]" class="attach-input" multiple
           accept="<?= escape(attachAcceptAttr()) ?>">
    <div class="attach-selected"></div>
    <small class="form-hint">До <?= attachMaxFiles() ?> файлов, каждый до <?= (int)(attachMaxSize() / 1024 / 1024) ?> МБ. <?= escape(attachTypesLabel()) ?>.</small>
</div>