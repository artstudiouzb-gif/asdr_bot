<?php

/** Запуск воркера по URL — для хостинга, где cron умеет только открывать адрес. */

declare(strict_types=1);

use App\Core\Env;

require dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$expected = (string)Env::get('CRON_KEY', '');
$given = (string)($_GET['key'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit("403 — неверный ключ\n");
}

$worker = APP_ROOT . '/bin/cron.php';
$argv = ['cron.php'];
$argc = 1;
require $worker;
