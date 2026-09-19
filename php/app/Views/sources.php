<div class="card">
  <h2>Каналы-источники</h2>
  <?php if ($sources === []): ?>
    <p class="muted">Источников пока нет. Добавьте первый в форме ниже.</p>
  <?php else: ?>
    <table>
      <tr><th>Название</th><th>Канал</th><th>Аккаунт</th><th>Маршрутов</th><th>Состояние</th><th>Действия</th></tr>
      <?php foreach ($sources as $source): ?>
        <tr>
          <td><b><?= e($source['name']) ?></b>
            <?php if ($source['last_error']): ?><div class="muted"><?= e($source['last_error']) ?></div><?php endif; ?></td>
          <td>@<?= e($source['tg_identifier']) ?>
            <?php if ($source['tg_peer_id']): ?><div class="muted">id <?= (int)$source['tg_peer_id'] ?> · прочитано до <?= (int)$source['last_message_id'] ?></div><?php endif; ?></td>
          <td class="muted"><?= e($source['account_label'] ?? 'любой активный') ?></td>
          <td><?= (int)$source['routes_count'] ?></td>
          <td><?= $source['is_active'] ? '<span class="badge ok">активен</span>' : '<span class="badge muted">выключен</span>' ?></td>
          <td>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <a class="badge muted" href="/sources?edit=<?= (int)$source['id'] ?>">изменить</a>
              <form method="post" action="/sources/<?= (int)$source['id'] ?>/test"><?= $csrf ?>
                <button class="ghost" type="submit">Проверить</button></form>
              <form method="post" action="/sources/<?= (int)$source['id'] ?>/toggle"><?= $csrf ?>
                <button class="ghost" type="submit"><?= $source['is_active'] ? 'Выключить' : 'Включить' ?></button></form>
              <form method="post" action="/sources/<?= (int)$source['id'] ?>/delete"
                    onsubmit="return confirm('Удалить источник вместе с его маршрутами и историей?')"><?= $csrf ?>
                <button class="danger" type="submit">Удалить</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= $edit ? 'Изменить источник' : 'Добавить источник' ?></h2>
  <form method="post" action="/sources/save">
    <?= $csrf ?>
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label for="name">Название</label>
    <input id="name" name="name" value="<?= e($edit['name'] ?? '') ?>" placeholder="Пресс-служба" required>
    <label for="tg_identifier">Канал</label>
    <input id="tg_identifier" name="tg_identifier" value="<?= e($edit['tg_identifier'] ?? '') ?>"
           placeholder="@shmirziyoyev, https://t.me/shmirziyoyev или -1001234567890" required>
    <small>Аккаунт должен видеть канал: публичный — достаточно адреса, приватный — нужна подписка.</small>
    <label for="account_id">Через аккаунт</label>
    <select id="account_id" name="account_id">
      <option value="0">любой подключённый</option>
      <?php foreach ($accounts as $account): ?>
        <option value="<?= (int)$account['id'] ?>"<?= (int)($edit['account_id'] ?? 0) === (int)$account['id'] ? ' selected' : '' ?>>
          <?= e($account['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="fetch_limit">Сколько постов читать за проход</label>
    <input id="fetch_limit" name="fetch_limit" type="number" min="1" max="100" value="<?= (int)($edit['fetch_limit'] ?? 20) ?>">
    <div class="check" style="margin-top:14px">
      <input id="is_active" name="is_active" type="checkbox" value="1" <?= !$edit || $edit['is_active'] ? 'checked' : '' ?>>
      <label for="is_active" style="margin:0;font-weight:400">Активен</label>
    </div>
    <div class="actions">
      <button type="submit"><?= $edit ? 'Сохранить' : 'Добавить' ?></button>
      <?php if ($edit): ?><a class="badge muted" href="/sources" style="align-self:center">отмена</a><?php endif; ?>
    </div>
  </form>
</div>
