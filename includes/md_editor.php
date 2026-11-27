<?php
/**
 * Ожидает переменные: $mdName, $mdId, $mdValue, $mdRows (опц.), $mdRequired (опц.),
 * $mdAutosaveId (опц.) — уникальный ключ автосохранения черновика.
 * Редактор: EasyMDE (локальный вендор, подключается в footer.php).
 */
$mdRows = $mdRows ?? 8;
$mdRequired = !empty($mdRequired) ? 'required' : '';
$mdEditorAssets = true; // сигнал footer.php подключить CSS/JS EasyMDE
?>
<div class="md-editor" data-csrf="<?= escape(generateCSRFToken()) ?>">
    <textarea id="<?= escape($mdId) ?>" name="<?= escape($mdName) ?>" class="form-control md-input"
              rows="<?= (int)$mdRows ?>" placeholder="Поддерживается Markdown..." <?= $mdRequired ?>
              <?= !empty($mdAutosaveId) ? 'data-autosave-id="' . escape($mdAutosaveId) . '"' : '' ?>><?= escape($mdValue) ?></textarea>
</div>