<?php /** @var string $content */ ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title ?? 'Репостер') ?></title>
<link rel="stylesheet" href="<?= url('/assets/app.css') ?>">
</head>
<body>
<div class="shell">
<aside>
  <div class="brand">Telegram Reposter</div>
  <nav>
    <?php
    $menu = [
        '/' => 'Обзор',
        '/accounts' => 'Аккаунты',
        '/sources' => 'Источники',
        '/destinations' => 'Назначения',
        '/routes' => 'Маршруты',
        '/preview' => 'Предпросмотр',
        '/logs' => 'Журнал',
    ];
    $active = $activePath ?? '/';
    foreach ($menu as $href => $label):
        $isActive = $href === '/' ? $active === '/' : str_starts_with($active, $href);
    ?>
      <a href="<?= e($href) ?>"<?= $isActive ? ' class="active"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</aside>
<main>
  <header class="top">
    <h1><?= e($title ?? 'Обзор') ?></h1>
    <?php if (!empty($user)): ?>
      <form method="post" action="<?= url('/logout') ?>">
        <?= $csrf ?? '' ?>
        <span class="muted"><?= e($user['username']) ?></span>
        <button class="ghost" type="submit">Выйти</button>
      </form>
    <?php endif; ?>
  </header>
  <?php if (!empty($flash)): ?><div class="flash ok"><?= e($flash) ?></div><?php endif; ?>
  <?php if (!empty($flashError)): ?><div class="flash err"><?= e($flashError) ?></div><?php endif; ?>
  <?= $content ?>
</main>
</div>
</body>
</html>
