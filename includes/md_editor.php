<?php
/**
 * Ожидает переменные: $mdName, $mdId, $mdValue, $mdRows (опц.), $mdRequired (опц.)
 */
$mdRows = $mdRows ?? 8;
$mdRequired = !empty($mdRequired) ? 'required' : '';
?>
<div class="md-editor">
    <div class="md-toolbar">
        <button type="button" class="md-btn" data-action="bold" title="Жирный (Ctrl+B)"><b>B</b></button>
        <button type="button" class="md-btn" data-action="italic" title="Курсив (Ctrl+I)"><i>I</i></button>
        <button type="button" class="md-btn" data-action="strike" title="Зачёркнутый"><s>S</s></button>
        <span class="md-sep"></span>
        <button type="button" class="md-btn" data-action="h" title="Заголовок">H</button>
        <button type="button" class="md-btn" data-action="quote" title="Цитата">❝</button>
        <button type="button" class="md-btn" data-action="ul" title="Список">• —</button>
        <button type="button" class="md-btn" data-action="ol" title="Нумерованный список">1.</button>
        <span class="md-sep"></span>
        <button type="button" class="md-btn" data-action="code" title="Код">&lt;/&gt;</button>
        <button type="button" class="md-btn" data-action="codeblock" title="Блок кода">{ }</button>
        <button type="button" class="md-btn" data-action="link" title="Ссылка (Ctrl+K)">🔗</button>
        <button type="button" class="md-btn" data-action="hr" title="Разделитель">―</button>
    </div>

    <div class="md-tabs">
        <button type="button" class="md-tab active" data-tab="write">Написание</button>
        <button type="button" class="md-tab" data-tab="preview">Предпросмотр</button>
    </div>

    <div class="md-pane active" data-pane="write">
        <textarea id="<?= escape($mdId) ?>" name="<?= escape($mdName) ?>" class="form-control"
                  rows="<?= (int)$mdRows ?>" placeholder="Поддерживается Markdown..." <?= $mdRequired ?>><?= escape($mdValue) ?></textarea>
    </div>
    <div class="md-pane" data-pane="preview">
        <div class="md-preview-body post-content"></div>
    </div>

    <div class="md-hint">**жирный** · *курсив* · `код` · &gt; цитата · - список · [текст](https://…) · Ctrl+Enter — отправить</div>
</div>