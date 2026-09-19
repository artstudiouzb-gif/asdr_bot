<div class="card">
  <h2>Как пост будет выглядеть после обработки</h2>
  <p class="muted">Вставьте текст поста (можно с HTML-ссылками вида
    &lt;a href="https://president.uz/"&gt;Prezident.uz&lt;/a&gt;) и посмотрите результат.
    Ничего никуда не публикуется.</p>
  <form method="post" action="<?= url('/preview') ?>">
    <?= $csrf ?>
    <label for="sample">Исходный пост</label>
    <textarea id="sample" name="sample" style="min-height:150px"><?= e($sample) ?></textarea>
    <label for="route_id">Правила какого маршрута применить</label>
    <select id="route_id" name="route_id">
      <option value="0">по умолчанию</option>
      <?php foreach ($routes as $route): ?>
        <option value="<?= (int)$route['id'] ?>"<?= (int)$routeId === (int)$route['id'] ? ' selected' : '' ?>>
          <?= e($route['source_name']) ?> → <?= e($route['destination_name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="check" style="margin-top:12px">
      <input id="has_media" name="has_media" type="checkbox" value="1">
      <label for="has_media" style="margin:0;font-weight:400">Считать, что в посте есть медиа</label>
    </div>
    <div class="actions"><button type="submit">Обработать</button></div>
  </form>
</div>

<?php if ($result !== null): ?>
  <div class="card">
    <h2>Результат</h2>
    <?php if ($result['skip'] !== null): ?>
      <div class="flash err">Пост был бы пропущен: <?= e($result['skip']) ?></div>
    <?php else: ?>
      <div class="flash ok">Пост был бы опубликован</div>
      <label>Видимый текст</label>
      <pre><?= e($plain) ?></pre>
      <label style="margin-top:14px">HTML, который уйдёт в Telegram</label>
      <pre><?= e($result['html']) ?></pre>
    <?php endif; ?>
    <label style="margin-top:14px">Сработавшие правила</label>
    <?php if ($result['applied'] === []): ?>
      <p class="muted">Ни одно правило не изменило текст.</p>
    <?php else: ?>
      <p><?php foreach ($result['applied'] as $rule): ?>
        <span class="badge ok" style="margin-right:6px"><?= e($rule) ?></span>
      <?php endforeach; ?></p>
    <?php endif; ?>
  </div>
<?php endif; ?>
