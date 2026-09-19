<?php
$statusBadge = static function (string $status): string {
    return match ($status) {
        'active'            => '<span class="badge ok">подключён</span>',
        'awaiting_code'     => '<span class="badge warn">ждёт код</span>',
        'awaiting_password' => '<span class="badge warn">ждёт пароль 2FA</span>',
        'error'             => '<span class="badge err">ошибка</span>',
        default             => '<span class="badge muted">не подключён</span>',
    };
};
?>
<?php if (!$apiReady): ?>
  <div class="flash err">В файле .env не заданы TG_API_ID и TG_API_HASH — получите их на
    <a href="https://my.telegram.org" target="_blank" rel="noopener">my.telegram.org</a> → API development tools.</div>
<?php endif; ?>

<div class="card">
  <h2>Подключённые аккаунты</h2>
  <p class="muted">Аккаунт читает каналы-источники и публикует посты от имени вашего канала.
     Панель сама к Telegram не обращается: она ставит задание, а выполняет его воркер — результат
     появляется в течение минуты.</p>
  <?php if ($accounts === []): ?>
    <p class="muted">Пока ни одного аккаунта.</p>
  <?php else: ?>
    <table>
      <tr><th>Название</th><th>Телефон</th><th>Состояние</th><th>Действия</th></tr>
      <?php foreach ($accounts as $account): ?>
        <tr>
          <td>
            <b><?= e($account['label']) ?></b>
            <?php if ($account['username']): ?><div class="muted">@<?= e($account['username']) ?></div><?php endif; ?>
            <?php if ($account['last_error']): ?><div class="muted"><?= e($account['last_error']) ?></div><?php endif; ?>
          </td>
          <td><?= e(preg_replace('/(?<=.{4}).(?=.{2})/', '•', (string)$account['phone'])) ?></td>
          <td><?= $statusBadge((string)$account['status']) ?>
              <div class="muted"><?= e(display_time($account['last_check_at'])) ?></div></td>
          <td>
            <?php if ($account['status'] === 'awaiting_code'): ?>
              <form method="post" action="<?= url('/accounts/') ?><?= (int)$account['id'] ?>/code" style="display:flex;gap:6px">
                <?= $csrf ?>
                <input name="code" inputmode="numeric" placeholder="код из Telegram" required style="max-width:170px">
                <button type="submit">Отправить</button>
              </form>
            <?php elseif ($account['status'] === 'awaiting_password'): ?>
              <form method="post" action="<?= url('/accounts/') ?><?= (int)$account['id'] ?>/password" style="display:flex;gap:6px">
                <?= $csrf ?>
                <input name="password" type="password" placeholder="пароль 2FA" required style="max-width:170px">
                <button type="submit">Отправить</button>
              </form>
            <?php else: ?>
              <form method="post" action="<?= url('/accounts/') ?><?= (int)$account['id'] ?>/logout"
                    onsubmit="return confirm('Отключить аккаунт и удалить файл сессии?')">
                <?= $csrf ?>
                <button class="ghost" type="submit">Отключить</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Подключить аккаунт</h2>
  <p class="muted">Используйте отдельный номер, не личный. После отправки формы воркер запросит код,
     он придёт в Telegram на этот номер — введите его в таблице выше.</p>
  <form method="post" action="<?= url('/accounts') ?>">
    <?= $csrf ?>
    <label for="label">Название</label>
    <input id="label" name="label" placeholder="Читатель новостей" required>
    <label for="phone">Телефон</label>
    <input id="phone" name="phone" placeholder="+998901234567" required>
    <div class="actions"><button type="submit">Запросить код</button></div>
  </form>
</div>

<div class="card">
  <h2>Последние задания воркера</h2>
  <?php if ($commands === []): ?>
    <p class="muted">Заданий пока не было.</p>
  <?php else: ?>
    <table>
      <tr><th>Время</th><th>Команда</th><th>Состояние</th><th>Результат</th></tr>
      <?php foreach ($commands as $command): ?>
        <tr>
          <td><?= e(display_time((string)$command['created_at'], 'd.m H:i:s')) ?></td>
          <td><?= e($command['command']) ?></td>
          <td>
            <?php $map = ['done' => 'ok', 'error' => 'err', 'running' => 'warn', 'queued' => 'muted']; ?>
            <span class="badge <?= $map[$command['status']] ?? 'muted' ?>"><?= e($command['status']) ?></span>
          </td>
          <td class="muted"><?= e($command['result'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
