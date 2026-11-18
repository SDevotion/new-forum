<?php
/**
 * Блок выбора вложений. Ожидает: $attachExisting (опц., массив строк attachments для edit).
 * Форма-родитель обязана иметь enctype="multipart/form-data".
 */
$attachExisting = $attachExisting ?? [];
?>
<div class="attach-upload" data-max-files="<?= ATTACH_MAX_FILES ?>" data-max-size="<?= ATTACH_MAX_SIZE ?>">
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
           accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.zip,.doc,.docx,.xls,.xlsx,.mp3,.mp4">
    <div class="attach-selected"></div>
    <small class="form-hint">До <?= ATTACH_MAX_FILES ?> файлов, каждый до <?= (int)(ATTACH_MAX_SIZE / 1024 / 1024) ?> МБ. Изображения, PDF, TXT, ZIP, Office, MP3, MP4.</small>
</div>