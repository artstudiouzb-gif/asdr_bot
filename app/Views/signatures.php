<div class="card">
  <h2>Подписи</h2>
  <p class="muted">Подпись добавляется к посту после очистки. Маршрут может использовать свою подпись,
     подпись по умолчанию или обходиться без неё — это выбирается на странице <a href="<?= url('/routes') ?>">«Маршруты»</a>.</p>
  <?php if ($signatures === []): ?>
    <p class="muted">Подписей пока нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Название</th><th>Как выглядит в канале</th><th>Где</th><th>Состояние</th><th></th></tr>
      <?php foreach ($signatures as $signature): ?>
        <tr>
          <td><b><?= e($signature['name']) ?></b>
            <?php if ($signature['is_default']): ?><div><span class="badge ok">по умолчанию</span></div><?php endif; ?>
            <div class="muted">маршрутов: <?= (int)$signature['routes_count'] ?></div></td>
          <td style="white-space:pre-wrap"><?= e($signature['plain']) ?></td>
          <td class="muted"><?= $signature['position'] === 'prepend' ? 'в начале' : 'в конце' ?></td>
          <td><?= $signature['is_active'] ? '<span class="badge ok">включена</span>' : '<span class="badge muted">выключена</span>' ?></td>
          <td><div style="display:flex;gap:6px;flex-wrap:wrap">
            <a class="badge muted" href="<?= url('/signatures?edit=') ?><?= (int)$signature['id'] ?>">изменить</a>
            <form method="post" action="<?= url('/signatures/') ?><?= (int)$signature['id'] ?>/toggle"><?= $csrf ?>
              <button class="ghost" type="submit"><?= $signature['is_active'] ? 'Выключить' : 'Включить' ?></button></form>
            <form method="post" action="<?= url('/signatures/') ?><?= (int)$signature['id'] ?>/delete"
                  onsubmit="return confirm('Удалить подпись?')"><?= $csrf ?>
              <button class="danger" type="submit">Удалить</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= $edit ? 'Изменить подпись' : 'Новая подпись' ?></h2>
  <form method="post" action="<?= url('/signatures/save') ?>">
    <?= $csrf ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label for="name">Название</label>
    <input id="name" name="name" value="<?= e($edit['name'] ?? '') ?>" required placeholder="ASR">
    <label for="content">Текст подписи</label>
    <textarea id="content" name="content" style="min-height:120px" required
      placeholder='<a href="https://asr.gov.uz/">website</a> | <a href="https://t.me/my_channel">Telegram</a>'><?= e($edit['content'] ?? '') ?></textarea>
    <small>Разрешены теги Telegram: &lt;b&gt;, &lt;i&gt;, &lt;u&gt;, &lt;s&gt;, &lt;a href="…"&gt;, &lt;code&gt;,
      &lt;blockquote&gt;, &lt;tg-spoiler&gt;. Новая строка — просто перенос в поле.</small>
    <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 16px">
      <div><label for="position">Где ставить</label>
        <select id="position" name="position">
          <option value="append"<?= ($edit['position'] ?? 'append') === 'append' ? ' selected' : '' ?>>в конце поста</option>
          <option value="prepend"<?= ($edit['position'] ?? '') === 'prepend' ? ' selected' : '' ?>>в начале поста</option>
        </select></div>
      <div><label for="separator">Отделять от текста</label>
        <select id="separator" name="separator">
          <?php foreach ($separators as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= ($edit['separator'] ?? "\n\n") === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="check" style="margin-top:12px"><input id="is_active" name="is_active" type="checkbox" value="1" <?= !$edit || $edit['is_active'] ? 'checked' : '' ?>>
      <label for="is_active" style="margin:0;font-weight:400">Подпись включена</label></div>
    <div class="check"><input id="is_default" name="is_default" type="checkbox" value="1" <?= !empty($edit['is_default']) ? 'checked' : '' ?>>
      <label for="is_default" style="margin:0;font-weight:400">Подпись по умолчанию для всех маршрутов без своей</label></div>
    <div class="actions">
      <button type="submit"><?= $edit ? 'Сохранить' : 'Добавить' ?></button>
      <?php if ($edit): ?><a class="badge muted" href="<?= url('/signatures') ?>" style="align-self:center">отмена</a><?php endif; ?>
    </div>
  </form>
</div>
