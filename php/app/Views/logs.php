<?php
$badge = static function (string $status): string {
    return match ($status) {
        'PUBLISHED'  => '<span class="badge ok">опубликовано</span>',
        'SKIPPED'    => '<span class="badge muted">пропущено</span>',
        'ERROR'      => '<span class="badge err">ошибка</span>',
        'RETRY'      => '<span class="badge warn">повтор</span>',
        'PROCESSING' => '<span class="badge warn">в работе</span>',
        default      => '<span class="badge muted">в очереди</span>',
    };
};
?>
<div class="card">
  <h2>Фильтры</h2>
  <form method="get" action="<?= url('/logs') ?>">
    <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:0 16px">
      <div>
        <label for="status">Статус</label>
        <select id="status" name="status">
          <option value="">любой</option>
          <?php foreach (['PUBLISHED' => 'опубликовано', 'SKIPPED' => 'пропущено', 'ERROR' => 'ошибка',
                          'RETRY' => 'повтор', 'NEW' => 'в очереди'] as $value => $label): ?>
            <option value="<?= $value ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="source_id">Источник</label>
        <select id="source_id" name="source_id">
          <option value="0">любой</option>
          <?php foreach ($sources as $source): ?>
            <option value="<?= (int)$source['id'] ?>"<?= $filters['source_id'] === (int)$source['id'] ? ' selected' : '' ?>>
              <?= e($source['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="destination_id">Назначение</label>
        <select id="destination_id" name="destination_id">
          <option value="0">любое</option>
          <?php foreach ($destinations as $destination): ?>
            <option value="<?= (int)$destination['id'] ?>"<?= $filters['destination_id'] === (int)$destination['id'] ? ' selected' : '' ?>>
              <?= e($destination['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label for="from">С даты</label><input id="from" name="from" type="date" value="<?= e($filters['from']) ?>"></div>
      <div><label for="to">По дату</label><input id="to" name="to" type="date" value="<?= e($filters['to']) ?>"></div>
      <div><label for="message_id">ID сообщения</label>
        <input id="message_id" name="message_id" type="number" value="<?= $filters['message_id'] ?: '' ?>"></div>
    </div>
    <div class="actions">
      <button type="submit">Показать</button>
      <a class="badge muted" href="<?= url('/logs') ?>" style="align-self:center">сбросить</a>
    </div>
  </form>
</div>

<div class="card">
  <h2>Публикации <span class="muted">(всего <?= (int)$total ?>)</span></h2>
  <?php if ($rows === []): ?>
    <p class="muted">Ничего не найдено.</p>
  <?php else: ?>
    <table>
      <tr><th>Получено</th><th>Источник</th><th>Назначение</th><th>Статус</th><th>Опубликовано</th><th>Комментарий</th><th></th></tr>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= e(display_time((string)$row['created_at'], 'd.m H:i')) ?></td>
          <td><?= e($row['source_name']) ?>
            <div class="muted">
              <?php $link = \App\Services\Telegram\Peer::messageLink((string)$row['source_peer'], (int)$row['source_message_id']); ?>
              <?php if ($link !== null): ?><a href="<?= e($link) ?>" target="_blank" rel="noopener">#<?= (int)$row['source_message_id'] ?></a>
              <?php else: ?>#<?= (int)$row['source_message_id'] ?><?php endif; ?>
              · <?= e($row['media_kind']) ?>
            </div>
          </td>
          <td><?= e($row['destination_name']) ?>
            <?php if ($row['dest_message_id']): ?><div class="muted">#<?= (int)$row['dest_message_id'] ?></div><?php endif; ?></td>
          <td><?= $badge((string)$row['status']) ?>
            <?php if ((int)$row['attempts'] > 1): ?><div class="muted">попыток: <?= (int)$row['attempts'] ?></div><?php endif; ?></td>
          <td><?= e(display_time($row['published_at'], 'd.m H:i:s')) ?></td>
          <td class="muted"><?= e($row['skip_reason'] ?? $row['last_error'] ?? '') ?></td>
          <td>
            <?php if (in_array($row['status'], ['ERROR', 'SKIPPED'], true)): ?>
              <form method="post" action="<?= url('/logs/') ?><?= (int)$row['id'] ?>/retry"><?= $csrf ?>
                <button class="ghost" type="submit">Повторить</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if ($pages > 1): ?>
      <p class="muted" style="margin-top:14px">
        Страница <?= (int)$page ?> из <?= (int)$pages ?> ·
        <?php $query = $filters; ?>
        <?php if ($page > 1): ?>
          <a href="<?= url('/logs?') ?><?= e(http_build_query($query + ['page' => $page - 1])) ?>">назад</a>
        <?php endif; ?>
        <?php if ($page < $pages): ?>
          <a href="<?= url('/logs?') ?><?= e(http_build_query($query + ['page' => $page + 1])) ?>">вперёд</a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Ошибки и предупреждения</h2>
  <?php if ($events === []): ?>
    <p class="muted">Чисто.</p>
  <?php else: ?>
    <table>
      <tr><th>Время</th><th>Уровень</th><th>Компонент</th><th>Сообщение</th></tr>
      <?php foreach ($events as $event): ?>
        <tr>
          <td><?= e(display_time((string)$event['created_at'], 'd.m H:i:s')) ?></td>
          <td><span class="badge <?= $event['level'] === 'error' ? 'err' : 'warn' ?>"><?= e($event['level']) ?></span></td>
          <td><?= e($event['component']) ?></td>
          <td><?= e($event['message']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
