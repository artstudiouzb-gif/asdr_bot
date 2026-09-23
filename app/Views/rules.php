<?php
$options = $edit['options'] ?? [];
$type = $edit['type'] ?? 'REMOVE_LINE';
$currentSet = null;
foreach ($sets as $set) {
    if ((int)$set['id'] === (int)$setId) {
        $currentSet = $set;
    }
}
$help = [
    'SOCIAL_FOOTER' => 'Находит строку-подпись вида «Prezident.uz | Facebook | X» и удаляет её целиком. '
        . 'Строка удаляется, если в ней есть ссылка на домен из списка, а кроме названий соцсетей ничего не осталось.',
    'REMOVE_LINE'   => 'Удаляет каждую строку, где найдётся шаблон. Например: ^Фото: или (реклама|erid)',
    'REMOVE_BLOCK'  => 'Удаляет фрагмент, который может занимать несколько строк. Например: Источник:.*$',
    'REMOVE_URL'    => 'Снимает ссылки с текста. Пусто — все ссылки; иначе домен (youtube.com) или шаблон.',
    'REGEX_REPLACE' => 'Заменяет найденное по шаблону. В замене можно ссылаться на группы: $1',
    'REPLACE_TEXT'  => 'Заменяет точный текст другим — без регулярных выражений.',
    'PREPEND_TEXT'  => 'Добавляет текст (можно HTML Telegram) в начало поста.',
    'APPEND_TEXT'   => 'Добавляет текст в конец поста, до подписи.',
    'DROP_MESSAGE'  => 'Если шаблон найден — пост вообще не публикуется.',
];
?>
<div class="card">
  <h2>Наборы правил</h2>
  <p class="muted">Маршрут использует выбранный набор, а если не выбран — набор по умолчанию.
     Проверить любой набор на своём тексте можно на странице <a href="<?= url('/preview') ?>">«Предпросмотр»</a>.</p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">
    <?php foreach ($sets as $set): ?>
      <a class="badge <?= (int)$set['id'] === (int)$setId ? 'ok' : 'muted' ?>" href="<?= url('/rules?set=') ?><?= (int)$set['id'] ?>">
        <?= e($set['name']) ?><?= $set['is_default'] ? ' · по умолчанию' : '' ?> (<?= (int)$set['rules_count'] ?>)</a>
    <?php endforeach; ?>
  </div>
  <form method="post" action="<?= url('/rule-sets/save') ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
    <?= $csrf ?>
    <div style="flex:1;min-width:180px"><label for="set_name">Новый набор</label>
      <input id="set_name" name="name" placeholder="Например: Для канала новостей"></div>
    <div style="min-width:180px"><label for="copy_from">Скопировать правила из</label>
      <select id="copy_from" name="copy_from"><option value="0">начать с пустого</option>
        <?php foreach ($sets as $set): ?><option value="<?= (int)$set['id'] ?>"<?= (int)$set['id'] === (int)$setId ? ' selected' : '' ?>><?= e($set['name']) ?></option><?php endforeach; ?>
      </select></div>
    <button type="submit">Создать</button>
  </form>
</div>

<?php if ($currentSet !== null): ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">«<?= e($currentSet['name']) ?>»
      <?php if ($currentSet['is_default']): ?><span class="badge ok">по умолчанию</span><?php endif; ?>
      <span class="muted" style="font-weight:400">· маршрутов: <?= (int)$currentSet['routes_count'] ?></span></h2>
    <div style="display:flex;gap:6px">
      <?php if (!$currentSet['is_default']): ?>
        <form method="post" action="<?= url('/rule-sets/') ?><?= (int)$currentSet['id'] ?>/default"><?= $csrf ?>
          <button class="ghost" type="submit">Сделать по умолчанию</button></form>
        <form method="post" action="<?= url('/rule-sets/') ?><?= (int)$currentSet['id'] ?>/delete"
              onsubmit="return confirm('Удалить набор вместе с его правилами?')"><?= $csrf ?>
          <button class="danger" type="submit">Удалить набор</button></form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($rules === []): ?>
    <p class="muted" style="margin-top:14px">В наборе пока нет правил.</p>
  <?php else: ?>
    <table style="margin-top:14px">
      <tr><th>Порядок</th><th>Правило</th><th>Тип</th><th>Шаблон</th><th>Состояние</th><th></th></tr>
      <?php foreach ($rules as $rule): ?>
        <tr>
          <td><?= (int)$rule['priority'] ?></td>
          <td><b><?= e($rule['name']) ?></b></td>
          <td class="muted"><?= e($types[$rule['type']] ?? $rule['type']) ?></td>
          <td><code style="font-size:12.5px"><?= e(mb_strimwidth((string)($rule['pattern'] ?? ($rule['type'] === 'SOCIAL_FOOTER' ? 'домены и метки из настроек правила' : '')), 0, 60, '…')) ?></code></td>
          <td><?= $rule['is_active'] ? '<span class="badge ok">включено</span>' : '<span class="badge muted">выключено</span>' ?></td>
          <td><div style="display:flex;gap:6px;flex-wrap:wrap">
            <a class="badge muted" href="<?= url('/rules?edit=') ?><?= (int)$rule['id'] ?>">изменить</a>
            <form method="post" action="<?= url('/rules/') ?><?= (int)$rule['id'] ?>/toggle"><?= $csrf ?>
              <button class="ghost" type="submit"><?= $rule['is_active'] ? 'Выключить' : 'Включить' ?></button></form>
            <form method="post" action="<?= url('/rules/') ?><?= (int)$rule['id'] ?>/delete"
                  onsubmit="return confirm('Удалить правило?')"><?= $csrf ?>
              <button class="danger" type="submit">Удалить</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="muted" style="margin-top:10px">Правила выполняются по типам: сначала «Не публиковать», потом подпись
      источника, блоки, строки, ссылки, замены, добавления. Внутри одного типа — по полю «Порядок».</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= $edit ? 'Изменить правило' : 'Добавить правило в набор' ?></h2>
  <form method="post" action="<?= url('/rules/save') ?>" id="rule-form">
    <?= $csrf ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <input type="hidden" name="rule_set_id" value="<?= (int)$setId ?>">
    <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 16px">
      <div><label for="name">Название</label>
        <input id="name" name="name" value="<?= e($edit['name'] ?? '') ?>" required placeholder="Подпись президента"></div>
      <div><label for="type">Тип</label>
        <select id="type" name="type">
          <?php foreach ($types as $value => $label): ?>
            <option value="<?= $value ?>"<?= $type === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label for="priority">Порядок</label>
        <input id="priority" name="priority" type="number" min="0" max="9999" value="<?= (int)($edit['priority'] ?? 100) ?>"></div>
    </div>
    <p class="muted" id="type-help" style="margin-top:10px"><?= e($help[$type] ?? '') ?></p>

    <div data-for="REMOVE_LINE REMOVE_BLOCK REMOVE_URL REGEX_REPLACE REPLACE_TEXT DROP_MESSAGE">
      <label for="pattern">Шаблон</label>
      <input id="pattern" name="pattern" value="<?= e($edit['pattern'] ?? '') ?>" placeholder="^Фото:" style="font-family:ui-monospace,monospace">
      <small>Регулярное выражение без ограничителей. Для «Замена текста» — обычный текст.</small>
    </div>
    <div data-for="REGEX_REPLACE REPLACE_TEXT PREPEND_TEXT APPEND_TEXT">
      <label for="replacement">Заменить на / добавить</label>
      <textarea id="replacement" name="replacement" style="min-height:70px"><?= e($edit['replacement'] ?? '') ?></textarea>
    </div>
    <div data-for="SOCIAL_FOOTER">
      <label for="domains">Домены-подписи (по одному в строке)</label>
      <textarea id="domains" name="domains" style="min-height:120px"><?= e(implode("\n", $options['domains'] ?? []) ?: $defaultDomains) ?></textarea>
      <label for="labels">Названия соцсетей, из которых может состоять строка-подпись</label>
      <textarea id="labels" name="labels" style="min-height:70px"><?= e(implode(', ', $options['labels'] ?? []) ?: $defaultLabels) ?></textarea>
      <div class="check"><input id="unwrap_links" name="unwrap_links" type="checkbox" value="1" <?= ($options['unwrap_links'] ?? true) ? 'checked' : '' ?>>
        <label for="unwrap_links" style="margin:0;font-weight:400">Снимать ссылки на эти домены и внутри текста (сам текст остаётся)</label></div>
    </div>
    <div data-for="REMOVE_LINE REMOVE_BLOCK REGEX_REPLACE DROP_MESSAGE">
      <div class="check"><input id="case_insensitive" name="case_insensitive" type="checkbox" value="1" <?= ($options['case_insensitive'] ?? true) ? 'checked' : '' ?>>
        <label for="case_insensitive" style="margin:0;font-weight:400">Без учёта регистра</label></div>
    </div>
    <div class="check"><input id="is_active" name="is_active" type="checkbox" value="1" <?= !$edit || $edit['is_active'] ? 'checked' : '' ?>>
      <label for="is_active" style="margin:0;font-weight:400">Правило включено</label></div>
    <div class="actions">
      <button type="submit"><?= $edit ? 'Сохранить' : 'Добавить' ?></button>
      <?php if ($edit): ?><a class="badge muted" href="<?= url('/rules?set=') ?><?= (int)$setId ?>" style="align-self:center">отмена</a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($global !== []): ?>
<div class="card">
  <h2>Общие правила</h2>
  <p class="muted">Действуют во всех наборах.</p>
  <table>
    <?php foreach ($global as $rule): ?>
      <tr><td><b><?= e($rule['name']) ?></b></td><td class="muted"><?= e($types[$rule['type']] ?? $rule['type']) ?></td>
        <td><?= $rule['is_active'] ? '<span class="badge ok">включено</span>' : '<span class="badge muted">выключено</span>' ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<script>
(function () {
  var help = <?= json_encode($help, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var select = document.getElementById('type');
  if (!select) { return; }
  function sync() {
    document.querySelectorAll('#rule-form [data-for]').forEach(function (block) {
      block.style.display = block.getAttribute('data-for').split(' ').indexOf(select.value) >= 0 ? '' : 'none';
    });
    document.getElementById('type-help').textContent = help[select.value] || '';
  }
  select.addEventListener('change', sync);
  sync();
})();
</script>
