<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/** Тонкая обёртка над PDO: только подготовленные запросы. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST', 'localhost'),
            Env::int('DB_PORT', 3306),
            Env::require('DB_NAME')
        );
        self::$pdo = new PDO($dsn, Env::get('DB_USER', ''), Env::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // сервер и приложение должны считать время одинаково
        self::$pdo->exec("SET time_zone = '+00:00'");
        return self::$pdo;
    }

    public static function set(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn(string $c): string => "`{$c}`", $columns)),
            implode(', ', array_map(static fn(string $c): string => ":{$c}", $columns))
        );
        self::run($sql, $data);
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $assignments = implode(', ', array_map(static fn(string $c): string => "`{$c}` = :set_{$c}", array_keys($data)));
        $params = $whereParams;
        foreach ($data as $column => $value) {
            $params["set_{$column}"] = $value;
        }
        return self::run("UPDATE `{$table}` SET {$assignments} WHERE {$where}", $params)->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        return self::run("DELETE FROM `{$table}` WHERE {$where}", $params)->rowCount();
    }

    /** Блокировка уровня СУБД — защита от параллельного cron. */
    public static function tryLock(string $name, int $timeout = 0): bool
    {
        return (int)self::value('SELECT GET_LOCK(?, ?)', [$name, $timeout]) === 1;
    }

    public static function releaseLock(string $name): void
    {
        self::run('SELECT RELEASE_LOCK(?)', [$name]);
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
