<?php

/**
 * Одноразовая установка через браузер — для хостинга без SSH.
 * Применяет миграции и создаёт первого администратора.
 * Работает только пока в базе нет ни одного администратора; после установки файл удалить.
 */

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Db;
use App\Core\Env;
use App\Core\Migrator;

require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$installKey = (string)Env::get('INSTALL_KEY', '');
$givenKey = (string)($_GET['key'] ?? $_POST['key'] ?? '');
if ($installKey === '' || !hash_equals($installKey, $givenKey)) {
    http_response_code(403);
    exit('<p>403. Откройте адрес с ?key=… — значение берётся из INSTALL_KEY в файле .env</p>');
}

$messages = [];
$errors = [];
$adminsExist = false;

try {
    $migrator = new Migrator(APP_ROOT . '/db/migrations');
    if (($_POST['action'] ?? '') === 'migrate') {
        $applied = $migrator->run();
        $messages[] = $applied === [] ? 'Новых миграций нет.' : 'Применены миграции: ' . implode(', ', $applied);
    }
    $pending = $migrator->pending();
    $adminsExist = (int)Db::value('SELECT COUNT(*) FROM admins') > 0;

    if (($_POST['action'] ?? '') === 'admin') {
        if ($adminsExist) {
            $errors[] = 'Администратор уже создан — установка закрыта. Удалите install.php.';
        } else {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if ($username === '' || strlen($password) < 10) {
                $errors[] = 'Логин обязателен, пароль — не короче 10 символов.';
            } else {
                Db::insert('admins', [
                    'username' => $username, 'password_hash' => Auth::hash($password),
                    'role' => 'owner', 'is_active' => 1, 'created_at' => Db::now(),
                ]);
                $adminsExist = true;
                $messages[] = 'Администратор создан. Теперь удалите install.php и войдите в панель.';
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Ошибка: ' . $e->getMessage();
    $pending = ['неизвестно — сначала проверьте доступ к базе'];
}

?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Установка</title><link rel="stylesheet" href="assets/app.css"></head>
<body><div class="login" style="max-width:520px">
<div class="card">
  <h2>Установка репостера</h2>
  <?php foreach ($messages as $message): ?><div class="flash ok"><?= e($message) ?></div><?php endforeach; ?>
  <?php foreach ($errors as $error): ?><div class="flash err"><?= e($error) ?></div><?php endforeach; ?>

  <h2>1. Схема базы данных</h2>
  <p class="muted">Не применено миграций: <?= count($pending ?? []) ?>
    <?= ($pending ?? []) === [] ? '— схема актуальна' : '(' . e(implode(', ', $pending)) . ')' ?></p>
  <form method="post">
    <input type="hidden" name="key" value="<?= e($givenKey) ?>">
    <input type="hidden" name="action" value="migrate">
    <div class="actions"><button type="submit">Применить миграции</button></div>
  </form>

  <h2 style="margin-top:22px">2. Администратор</h2>
  <?php if ($adminsExist): ?>
    <p class="muted">Администратор уже создан. <b>Удалите install.php с хостинга</b> и войдите в
      <a href="login">панель</a>.</p>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="key" value="<?= e($givenKey) ?>">
      <input type="hidden" name="action" value="admin">
      <label for="username">Логин</label>
      <input id="username" name="username" required>
      <label for="password">Пароль (минимум 10 символов)</label>
      <input id="password" name="password" type="password" minlength="10" required>
      <div class="actions"><button type="submit">Создать администратора</button></div>
    </form>
  <?php endif; ?>
</div>
</div></body></html>
