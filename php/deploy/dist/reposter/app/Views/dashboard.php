<?php
$statusBadge = static function (string $status): string {
    return match ($status) {
        'PUBLISHED' => '<span class="badge ok">опубликовано</span>',
        'SKIPPED'   => '<span class="badge muted">пропущено</span>',
        'ERROR'     => '<span class="badge err">ошибка</span>',
        'RETRY'     => '<span class="badge warn">повтор</span>',
        'PROCESSING'=> '<span class="badge warn">в работе</span>',
        default     => '<span class="badge muted">в очереди</span>',
    };
};
?>
<div class="grid">
  <div class="tile"><b><?= (int)$stats['sourcesActive'] ?>/<?= (int)$stats['sources'] ?></b><span>активных источников</span></div>
  <div class="tile"><b><?= (int)$stats['routes'] ?></b><span>активных маршрутов</span></div>
  <div class="tile"><b><?= (int)$stats['messages'] ?></b><span>получено постов</span></div>
  <div class="tile"><b><?= (int)$stats['published'] ?></b><span>опубликовано за сутки</span></div>
  <div class="tile"><b><?= (int)$stats['skipped'] ?></b><span>пропущено за сутки</span></div>
  <div class="tile"><b><?= (int)$stats['queue'] ?></b><span>в очереди</span></div>
  <div class="tile"><b><?= (int)$stats['errors'] ?></b><span>ошибок</span></div>
</div>

<div class="card">
  <h2>Воркер</h2>
  <?php if ($lastRun === null): ?>
    <p class="muted">Cron ещё ни разу не отработал. Проверьте задание в панели хостинга.</p>
  <?php else: ?>
    <p>
      Последний запуск: <b><?= e(display_time((string)$lastRun['started_at'], 'd.m.Y H:i:s')) ?></b>
      <?php if ($cronAgeMinutes !== null && $cronAgeMinutes > 5): ?>
        <span class="badge err">не отвечает <?= (int)$cronAgeMinutes ?> мин</span>
      <?php else: ?>
        <span class="badge ok">в норме</span>
      <?php endif; ?>
    </p>
    <p class="muted">
      Получено: <?= (int)$lastRun['ingested'] ?> · опубликовано: <?= (int)$lastRun['published'] ?> ·
      пропущено: <?= (int)$lastRun['skipped'] ?> · ошибок: <?= (int)$lastRun['errors'] ?> ·
      длительность: <?= (int)$lastRun['duration_ms'] ?> мс
    </p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Последние публикации</h2>
  <?php if ($recent === []): ?>
    <p class="muted">Пока ничего не публиковалось.</p>
  <?php else: ?>
    <table>
      <tr><th>Время</th><th>Источник</th><th>Назначение</th><th>Статус</th><th>Комментарий</th></tr>
      <?php foreach ($recent as $row): ?>
        <tr>
          <td><?= e(display_time((string)($row['published_at'] ?? $row['created_at']))) ?></td>
          <td><?= e($row['source_name']) ?> <span class="muted">#<?= (int)$row['source_message_id'] ?></span></td>
          <td><?= e($row['destination_name']) ?></td>
          <td><?= $statusBadge((string)$row['status']) ?></td>
          <td class="muted"><?= e($row['skip_reason'] ?? $row['last_error'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Последние проблемы</h2>
  <?php if ($problems === []): ?>
    <p class="muted">Ошибок и предупреждений нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Время</th><th>Уровень</th><th>Компонент</th><th>Сообщение</th></tr>
      <?php foreach ($problems as $row): ?>
        <tr>
          <td><?= e(display_time((string)$row['created_at'])) ?></td>
          <td><span class="badge <?= $row['level'] === 'error' ? 'err' : 'warn' ?>"><?= e($row['level']) ?></span></td>
          <td><?= e($row['component']) ?></td>
          <td><?= e($row['message']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
