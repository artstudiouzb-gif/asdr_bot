<div class="card">
  <h2>Наборы фильтров</h2>
  <p class="muted">Фильтры решают, публиковать ли пост вообще. Набор назначается маршруту; маршрут без
     набора публикует всё, что прошло очистку.</p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">
    <?php foreach ($sets as $set): ?>
      <a class="badge <?= (int)$set['id'] === (int)$setId ? 'ok' : 'muted' ?>" href="<?= url('/filters?set=') ?><?= (int)$set['id'] ?>">
        <?= e($set['name']) ?> (<?= (int)$set['filters_count'] ?>)</a>
    <?php endforeach; ?>
  </div>
  <form method="post" action="<?= url('/filter-sets/save') ?>" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
    <?= $csrf ?>
    <div style="flex:1;min-width:200px"><label for="set_name">Новый набор</label>
      <input id="set_name" name="name" placeholder="Например: Только экономика"></div>
    <button type="submit">Создать</button>
  </form>
</div>

<?php if ($setId > 0): ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <h2 style="margin:0">Фильтры набора</h2>
    <form method="post" action="<?= url('/filter-sets/') ?><?= (int)$setId ?>/delete"
          onsubmit="return confirm('Удалить набор фильтров?')"><?= $csrf ?>
      <button class="danger" type="submit">Удалить набор</button></form>
  </div>
  <?php if ($filters === []): ?>
    <p class="muted" style="margin-top:12px">Пока пусто — посты проходят без ограничений.</p>
  <?php else: ?>
    <table style="margin-top:12px">
      <tr><th>Условие</th><th>Значение</th><th>Регистр</th><th>Состояние</th><th></th></tr>
      <?php foreach ($filters as $filter): ?>
        <tr>
          <td><?= e($kinds[$filter['kind']] ?? $filter['kind']) ?></td>
          <td><code><?= e($filter['value'] ?? '—') ?></code></td>
          <td class="muted"><?= $filter['case_insensitive'] ? 'не важен' : 'важен' ?></td>
          <td><?= $filter['is_active'] ? '<span class="badge ok">включён</span>' : '<span class="badge muted">выключен</span>' ?></td>
          <td><div style="display:flex;gap:6px">
            <form method="post" action="<?= url('/filters/') ?><?= (int)$filter['id'] ?>/toggle"><?= $csrf ?>
              <button class="ghost" type="submit"><?= $filter['is_active'] ? 'Выключить' : 'Включить' ?></button></form>
            <form method="post" action="<?= url('/filters/') ?><?= (int)$filter['id'] ?>/delete"><?= $csrf ?>
              <button class="danger" type="submit">Удалить</button></form>
          </div></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <p class="muted" style="margin-top:10px">Если в наборе есть условия «только если есть слово», достаточно
      совпадения с любым из них. Условия «не публиковать» срабатывают по первому совпадению.</p>
  <?php endif; ?>

  <h2 style="margin-top:20px">Добавить условие</h2>
  <form method="post" action="<?= url('/filters/save') ?>">
    <?= $csrf ?>
    <input type="hidden" name="filter_set_id" value="<?= (int)$setId ?>">
    <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:0 16px">
      <div><label for="kind">Условие</label>
        <select id="kind" name="kind">
          <?php foreach ($kinds as $value => $label): ?><option value="<?= $value ?>"><?= e($label) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="value">Значение</label>
        <input id="value" name="value" placeholder="экономика, инвестиции, реформы"></div>
    </div>
    <small>Для слов можно перечислить несколько через запятую — каждое станет отдельным условием.</small>
    <div class="check" style="margin-top:10px"><input id="case_insensitive" name="case_insensitive" type="checkbox" value="1" checked>
      <label for="case_insensitive" style="margin:0;font-weight:400">Без учёта регистра</label></div>
    <div class="actions"><button type="submit">Добавить</button></div>
  </form>
</div>
<?php endif; ?>
