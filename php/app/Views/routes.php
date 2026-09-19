<div class="card">
  <h2>Маршруты публикации</h2>
  <p class="muted">Один источник может идти в несколько каналов, и наоборот — в один канал может
     сходиться несколько источников.</p>
  <?php if ($routes === []): ?>
    <p class="muted">Маршрутов пока нет. Без маршрута посты никуда не публикуются.</p>
  <?php else: ?>
    <table>
      <tr><th>Источник</th><th>Назначение</th><th>Задержка</th><th>Обработка</th><th>Состояние</th><th>Действия</th></tr>
      <?php foreach ($routes as $route): ?>
        <tr>
          <td><b><?= e($route['source_name']) ?></b><div class="muted">@<?= e($route['source_peer']) ?></div></td>
          <td><b><?= e($route['destination_name']) ?></b><div class="muted">@<?= e($route['destination_peer']) ?></div></td>
          <td><?= (int)$route['delay_seconds'] ?> с</td>
          <td class="muted">
            правила: <?= e($route['rule_set_name'] ?? 'по умолчанию') ?><br>
            подпись: <?= e($route['signature_name'] ?? 'по умолчанию') ?><br>
            медиа: <?= e(match ($route['media_mode']) {
                'text_only' => 'только текст', 'skip_media' => 'пропускать посты с медиа', default => 'как в источнике' }) ?>
          </td>
          <td><?= $route['is_active'] ? '<span class="badge ok">активен</span>' : '<span class="badge muted">выключен</span>' ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <a class="badge muted" href="<?= url('/routes?edit=') ?><?= (int)$route['id'] ?>">изменить</a>
              <form method="post" action="<?= url('/routes/') ?><?= (int)$route['id'] ?>/toggle"><?= $csrf ?>
                <button class="ghost" type="submit"><?= $route['is_active'] ? 'Выключить' : 'Включить' ?></button></form>
              <form method="post" action="<?= url('/routes/') ?><?= (int)$route['id'] ?>/delete"
                    onsubmit="return confirm('Удалить маршрут?')"><?= $csrf ?>
                <button class="danger" type="submit">Удалить</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= $edit ? 'Изменить маршрут' : 'Создать маршрут' ?></h2>
  <?php if ($sources === [] || $destinations === []): ?>
    <p class="muted">Сначала добавьте хотя бы один <a href="<?= url('/sources') ?>">источник</a> и одно
      <a href="<?= url('/destinations') ?>">назначение</a>.</p>
  <?php else: ?>
  <form method="post" action="<?= url('/routes/save') ?>">
    <?= $csrf ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 16px">
      <div>
        <label for="source_id">Источник</label>
        <select id="source_id" name="source_id" required>
          <?php foreach ($sources as $source): ?>
            <option value="<?= (int)$source['id'] ?>"<?= (int)($edit['source_id'] ?? 0) === (int)$source['id'] ? ' selected' : '' ?>>
              <?= e($source['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="destination_id">Назначение</label>
        <select id="destination_id" name="destination_id" required>
          <?php foreach ($destinations as $destination): ?>
            <option value="<?= (int)$destination['id'] ?>"<?= (int)($edit['destination_id'] ?? 0) === (int)$destination['id'] ? ' selected' : '' ?>>
              <?= e($destination['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="delay_seconds">Задержка перед публикацией, с</label>
        <input id="delay_seconds" name="delay_seconds" type="number" min="0" max="86400"
               value="<?= (int)($edit['delay_seconds'] ?? 0) ?>">
      </div>
      <div>
        <label for="media_mode">Медиа</label>
        <select id="media_mode" name="media_mode">
          <option value="all"<?= ($edit['media_mode'] ?? 'all') === 'all' ? ' selected' : '' ?>>как в источнике</option>
          <option value="text_only"<?= ($edit['media_mode'] ?? '') === 'text_only' ? ' selected' : '' ?>>только текст</option>
          <option value="skip_media"<?= ($edit['media_mode'] ?? '') === 'skip_media' ? ' selected' : '' ?>>пропускать посты с медиа</option>
        </select>
      </div>
      <?php
      $selects = [
          'rule_set_id'   => ['Набор правил очистки', $ruleSets],
          'signature_id'  => ['Подпись', $signatures],
          'filter_set_id' => ['Набор фильтров', $filterSets],
      ];
      foreach ($selects as $field => [$label, $options]): ?>
        <div>
          <label for="<?= $field ?>"><?= e($label) ?></label>
          <select id="<?= $field ?>" name="<?= $field ?>">
            <option value="0">по умолчанию</option>
            <?php foreach ($options as $option): ?>
              <option value="<?= (int)$option['id'] ?>"<?= (int)($edit[$field] ?? 0) === (int)$option['id'] ? ' selected' : '' ?>>
                <?= e($option['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="check" style="margin-top:14px">
      <input id="is_active" name="is_active" type="checkbox" value="1" <?= !$edit || $edit['is_active'] ? 'checked' : '' ?>>
      <label for="is_active" style="margin:0;font-weight:400">Активен</label>
    </div>
    <div class="actions">
      <button type="submit"><?= $edit ? 'Сохранить' : 'Создать' ?></button>
      <?php if ($edit): ?><a class="badge muted" href="<?= url('/routes') ?>" style="align-self:center">отмена</a><?php endif; ?>
    </div>
  </form>
  <?php endif; ?>
</div>
