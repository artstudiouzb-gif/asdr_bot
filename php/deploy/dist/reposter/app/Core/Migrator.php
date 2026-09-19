<?php

declare(strict_types=1);

namespace App\Core;

/** Применение .sql-миграций по порядку, по одному разу. */
final class Migrator
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @return array<int, string> применённые в этом запуске версии */
    public function run(): array
    {
        Db::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                version VARCHAR(64) PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $applied = array_column(Db::all('SELECT version FROM migrations'), 'version');
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files);

        $done = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }
            foreach ($this->statements((string)file_get_contents($file)) as $statement) {
                Db::pdo()->exec($statement);
            }
            Db::insert('migrations', ['version' => $version, 'applied_at' => Db::now()]);
            $done[] = $version;
        }
        return $done;
    }

    public function pending(): array
    {
        $applied = array_column(Db::all('SELECT version FROM migrations'), 'version');
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files);
        return array_values(array_filter(
            array_map(static fn(string $f): string => basename($f, '.sql'), $files),
            static fn(string $v): bool => !in_array($v, $applied, true)
        ));
    }

    /** @return array<int, string> */
    private function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = array_map('trim', explode(";\n", $sql . "\n"));
        return array_values(array_filter($parts, static fn(string $p): bool => trim($p, " \t\n;") !== ''));
    }
}
