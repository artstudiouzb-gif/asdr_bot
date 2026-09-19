<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Db;

require dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['username:', 'password:', 'role::']);
$username = (string)($options['username'] ?? '');
$password = (string)($options['password'] ?? '');

if ($username === '' && PHP_SAPI === 'cli' && function_exists('readline')) {
    $username = (string)readline('Логин: ');
    $password = (string)readline('Пароль: ');
}
if ($username === '' || strlen($password) < 10) {
    fwrite(STDERR, "Использование: php bin/create-admin.php --username=admin --password='минимум 10 символов'\n");
    exit(1);
}

$exists = Db::one('SELECT id FROM admins WHERE username = ?', [$username]);
if ($exists !== null) {
    Db::update('admins', ['password_hash' => Auth::hash($password), 'is_active' => 1,
        'failed_attempts' => 0, 'locked_until' => null], 'id = :id', ['id' => $exists['id']]);
    echo "Пароль администратора «{$username}» обновлён.\n";
    exit(0);
}

Db::insert('admins', [
    'username'      => $username,
    'password_hash' => Auth::hash($password),
    'role'          => ($options['role'] ?? 'owner') === 'admin' ? 'admin' : 'owner',
    'is_active'     => 1,
    'created_at'    => Db::now(),
]);
echo "Администратор «{$username}» создан.\n";
