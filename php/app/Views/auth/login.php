<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Вход — Telegram Reposter</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="login">
  <div class="card">
    <h2>Вход в панель</h2>
    <?php if (!empty($error)): ?><div class="flash err"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/login">
      <?= $csrf ?>
      <label for="username">Логин</label>
      <input id="username" name="username" autocomplete="username" autofocus required>
      <label for="password">Пароль</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required>
      <div class="actions"><button type="submit">Войти</button></div>
    </form>
  </div>
  <p class="muted" style="text-align:center">Telegram Reposter</p>
</div>
</body>
</html>
