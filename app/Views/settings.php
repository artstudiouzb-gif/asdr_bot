<div class="card">
  <h2>Работа воркера</h2>
  <form method="post" action="<?= url('/settings/save') ?>">
    <?= $csrf ?>
    <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0 16px">
      <div><label for="retry_max">Попыток публикации</label>
        <input id="retry_max" name="retry_max" type="number" min="1" max="10" value="<?= (int)$settings['retry_max'] ?>">
        <small>Повтор через 1, 5, 15, 60 минут, потом статус «ошибка».</small></div>
      <div><label for="publish_batch">Публикаций за один запуск</label>
        <input id="publish_batch" name="publish_batch" type="number" min="1" max="100" value="<?= (int)$settings['publish_batch'] ?>">
        <small>Остальное уйдёт следующим запуском cron.</small></div>
      <div><label for="backfill_on_first_run">Постов при подключении источника</label>
        <input id="backfill_on_first_run" name="backfill_on_first_run" type="number" min="0" max="50" value="<?= (int)$settings['backfill_on_first_run'] ?>">
        <small>0 — публиковать только новые посты.</small></div>
    </div>
    <div class="actions"><button type="submit">Сохранить</button></div>
  </form>
</div>

<div class="card">
  <h2>Cron</h2>
  <p>Задание для панели хостинга, каждую минуту:</p>
  <pre><?= e($cronLine) ?></pre>
  <?php if ($cronUrl !== null): ?>
    <p style="margin-top:12px">Если cron умеет только открывать адреса:</p>
    <pre><?= e($cronUrl) ?></pre>
    <p class="muted">Ключ в адресе — пароль к запуску воркера. Не публикуйте его.</p>
  <?php endif; ?>
  <p class="muted" style="margin-top:12px">
    Последний запуск: <?= $lastRun ? e(display_time((string)$lastRun['started_at'], 'd.m.Y H:i:s')) . ' (' . e($lastRun['status']) . ')' : 'ещё не было' ?> ·
    часовой пояс панели: <?= e($timezone) ?> ·
    ключи Telegram: <?= $apiReady ? 'заданы' : '<b>не заданы в .env</b>' ?>
  </p>
</div>

<div class="card">
  <h2>Пароль</h2>
  <form method="post" action="<?= url('/settings/password') ?>">
    <?= $csrf ?>
    <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 16px">
      <div><label for="current">Текущий пароль</label><input id="current" name="current" type="password" autocomplete="current-password" required></div>
      <div><label for="new">Новый пароль</label><input id="new" name="new" type="password" minlength="10" autocomplete="new-password" required></div>
      <div><label for="confirm">Ещё раз</label><input id="confirm" name="confirm" type="password" minlength="10" autocomplete="new-password" required></div>
    </div>
    <div class="actions"><button type="submit">Сменить пароль</button></div>
  </form>
</div>

<div class="card">
  <h2>Активные сеансы</h2>
  <table>
    <tr><th>Вход</th><th>Последняя активность</th><th>IP</th><th>Браузер</th></tr>
    <?php foreach ($sessions as $session): ?>
      <tr>
        <td><?= e(display_time((string)$session['created_at'])) ?>
          <?php if ($session['id'] === $currentSession): ?><span class="badge ok">этот</span><?php endif; ?></td>
        <td><?= e(display_time((string)$session['last_seen_at'])) ?></td>
        <td class="muted"><?= e($session['ip'] ?? '') ?></td>
        <td class="muted"><?= e(mb_strimwidth((string)($session['user_agent'] ?? ''), 0, 60, '…')) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php if (count($sessions) > 1): ?>
    <form method="post" action="<?= url('/settings/sessions') ?>" style="margin-top:12px"><?= $csrf ?>
      <button class="ghost" type="submit">Завершить все остальные сеансы</button></form>
  <?php endif; ?>
</div>
