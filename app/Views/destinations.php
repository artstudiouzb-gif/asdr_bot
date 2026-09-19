<div class="card">
  <h2>Куда публикуем</h2>
  <p class="muted">Аккаунт (или бот) должен быть администратором канала с правом публикации.
     Чтобы посты выходили от имени канала, в настройках канала выключите «Подписывать сообщения».</p>
  <?php if ($destinations === []): ?>
    <p class="muted">Назначений пока нет.</p>
  <?php else: ?>
    <table>
      <tr><th>Название</th><th>Канал</th><th>Публикует</th><th>Лимит</th><th>Состояние</th><th>Действия</th></tr>
      <?php foreach ($destinations as $destination): ?>
        <tr>
          <td><b><?= e($destination['name']) ?></b>
            <?php if ($destination['last_error']): ?><div class="muted"><?= e($destination['last_error']) ?></div><?php endif; ?></td>
          <td>@<?= e($destination['tg_identifier']) ?>
            <?php if ($destination['tg_peer_id']): ?><div class="muted">id <?= (int)$destination['tg_peer_id'] ?></div><?php endif; ?></td>
          <td><?= $destination['publish_as'] === 'bot' ? 'бот' : 'аккаунт' ?>
            <div class="muted"><?= e($destination['account_label'] ?? 'любой активный') ?></div></td>
          <td><?= (int)$destination['rate_limit_per_min'] ?>/мин</td>
          <td><?= $destination['is_active'] ? '<span class="badge ok">активно</span>' : '<span class="badge muted">выключено</span>' ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <a class="badge muted" href="<?= url('/destinations?edit=') ?><?= (int)$destination['id'] ?>">изменить</a>
              <form method="post" action="<?= url('/destinations/') ?><?= (int)$destination['id'] ?>/test"><?= $csrf ?>
                <button class="ghost" type="submit">Проверить</button></form>
              <form method="post" action="<?= url('/destinations/') ?><?= (int)$destination['id'] ?>/toggle"><?= $csrf ?>
                <button class="ghost" type="submit"><?= $destination['is_active'] ? 'Выключить' : 'Включить' ?></button></form>
              <form method="post" action="<?= url('/destinations/') ?><?= (int)$destination['id'] ?>/delete"
                    onsubmit="return confirm('Удалить назначение и его маршруты?')"><?= $csrf ?>
                <button class="danger" type="submit">Удалить</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= $edit ? 'Изменить назначение' : 'Добавить назначение' ?></h2>
  <form method="post" action="<?= url('/destinations/save') ?>">
    <?= $csrf ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label for="name">Название</label>
    <input id="name" name="name" value="<?= e($edit['name'] ?? '') ?>" placeholder="Наш канал" required>
    <label for="tg_identifier">Канал</label>
    <input id="tg_identifier" name="tg_identifier" value="<?= e($edit['tg_identifier'] ?? '') ?>"
           placeholder="@my_channel или -1001234567890" required>
    <label for="publish_as">От чьего имени публиковать</label>
    <select id="publish_as" name="publish_as">
      <option value="user"<?= ($edit['publish_as'] ?? 'user') === 'user' ? ' selected' : '' ?>>аккаунт (медиа без перезаливки, до 2 ГБ)</option>
      <option value="bot"<?= ($edit['publish_as'] ?? '') === 'bot' ? ' selected' : '' ?>>бот (файлы до 50 МБ, с перезаливкой)</option>
    </select>
    <label for="account_id">Аккаунт</label>
    <select id="account_id" name="account_id">
      <option value="0">любой подключённый</option>
      <?php foreach ($accounts as $account): ?>
        <option value="<?= (int)$account['id'] ?>"<?= (int)($edit['account_id'] ?? 0) === (int)$account['id'] ? ' selected' : '' ?>>
          <?= e($account['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="rate_limit_per_min">Не больше публикаций в минуту</label>
    <input id="rate_limit_per_min" name="rate_limit_per_min" type="number" min="1" max="60"
           value="<?= (int)($edit['rate_limit_per_min'] ?? 15) ?>">
    <small>Защита от ограничений Telegram. Остальное подождёт следующего запуска.</small>
    <div class="check" style="margin-top:14px">
      <input id="is_active" name="is_active" type="checkbox" value="1" <?= !$edit || $edit['is_active'] ? 'checked' : '' ?>>
      <label for="is_active" style="margin:0;font-weight:400">Активно</label>
    </div>
    <div class="actions">
      <button type="submit"><?= $edit ? 'Сохранить' : 'Добавить' ?></button>
      <?php if ($edit): ?><a class="badge muted" href="<?= url('/destinations') ?>" style="align-self:center">отмена</a><?php endif; ?>
    </div>
  </form>
</div>
