<?php

declare(strict_types=1);

use App\Core\Migrator;

require dirname(__DIR__) . '/app/bootstrap.php';

$migrator = new Migrator(APP_ROOT . '/db/migrations');
$applied = $migrator->run();

echo $applied === []
    ? "Новых миграций нет — схема актуальна.\n"
    : "Применены миграции:\n  " . implode("\n  ", $applied) . "\n";
