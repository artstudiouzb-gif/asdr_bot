<?php

/**
 * Воркер. Запускается по cron раз в минуту, работает считанные секунды и выходит.
 *
 *   * * * * * /usr/bin/php /путь/к/проекту/bin/cron.php >> /путь/к/проекту/storage/logs/cron.log 2>&1
 *
 * Параллельные запуски безопасны: второй процесс видит блокировку и сразу выходит.
 */

declare(strict_types=1);

use App\Core\Db;
use App\Core\Env;
use App\Core\Logger;
use App\Services\Telegram\CommandRunner;

require dirname(__DIR__) . '/app/bootstrap.php';

const WORKER_LOCK = 'reposter.worker';
const TIME_BUDGET = 50;   // секунд: выходим сами, не дожидаясь, пока хостинг убьёт процесс

$startedAt = microtime(true);
$deadline = $startedAt + TIME_BUDGET;

if (!Db::tryLock(WORKER_LOCK)) {
    Db::insert('cron_runs', [
        'started_at' => Db::now(), 'finished_at' => Db::now(),
        'status' => 'locked', 'note' => 'Предыдущий запуск ещё работает', 'duration_ms' => 0,
    ]);
    echo "Предыдущий запуск ещё работает — выходим.\n";
    exit(0);
}

$runId = Db::insert('cron_runs', ['started_at' => Db::now(), 'status' => 'ok']);
$counters = ['ingested' => 0, 'published' => 0, 'skipped' => 0, 'errors' => 0];
$note = null;
$status = 'ok';

try {
    // 1. задания из панели: вход в Telegram, проверка каналов
    $counters['errors'] += 0;
    $commands = (new CommandRunner())->runQueued();
    if ($commands > 0) {
        echo "Выполнено команд панели: {$commands}\n";
    }

    // 2. сбор и публикация подключаются следующим этапом (Phase 1.2)

    if (microtime(true) >= $deadline) {
        $status = 'partial';
        $note = 'Достигнут лимит времени, остаток доберёт следующий запуск';
    }
} catch (Throwable $e) {
    $status = 'error';
    $note = mb_substr($e->getMessage(), 0, 255);
    $counters['errors']++;
    Logger::error('cron', 'Сбой воркера: ' . $e->getMessage());
} finally {
    Db::update('cron_runs', [
        'finished_at' => Db::now(),
        'ingested'    => $counters['ingested'],
        'published'   => $counters['published'],
        'skipped'     => $counters['skipped'],
        'errors'      => $counters['errors'],
        'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        'status'      => $status,
        'note'        => $note,
    ], 'id = :id', ['id' => $runId]);

    $retention = Env::int('LOG_RETENTION_DAYS', 30);
    if ($retention > 0 && random_int(1, 60) === 1) {      // примерно раз в час
        Db::delete('logs', 'created_at < ?', [gmdate('Y-m-d H:i:s', time() - $retention * 86400)]);
        Db::delete('cron_runs', 'started_at < ?', [gmdate('Y-m-d H:i:s', time() - 3 * 86400)]);
    }
    Db::releaseLock(WORKER_LOCK);
}

printf("Готово за %d мс (%s)\n", (int)round((microtime(true) - $startedAt) * 1000), $status);
