<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Схему нельзя прогнать без MySQL, поэтому проверяем то, на чём она уже один раз
 * сломалась: незаэкранированные имена столбцов. `separator`, `key`, `rank` и другие
 * слова зарезервированы в MariaDB и без обратных кавычек валят миграцию.
 */
final class MigrationsTest extends TestCase
{
    private const RESERVED = [
        'separator', 'key', 'keys', 'rank', 'system', 'order', 'group', 'range', 'interval',
        'lines', 'match', 'option', 'read', 'usage', 'values', 'when', 'where', 'status',
        'level', 'position', 'signal', 'rows', 'partition', 'window', 'over', 'lead', 'lag',
    ];

    /** @return array<int, string> */
    private function migrations(): array
    {
        $files = glob(dirname(__DIR__) . '/db/migrations/*.sql') ?: [];
        self::assertNotEmpty($files, 'Файлы миграций не найдены');
        return $files;
    }

    public function testColumnDefinitionsAreQuoted(): void
    {
        foreach ($this->migrations() as $file) {
            foreach ($this->columnDefinitions($file) as $line => $definition) {
                if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX|CONSTRAINT|FOREIGN\s+KEY)/i', $definition) === 1) {
                    continue;
                }
                self::assertMatchesRegularExpression(
                    '/^`[A-Za-z_][A-Za-z0-9_]*`\s+/',
                    $definition,
                    sprintf('%s строка %d: имя столбца без обратных кавычек — %s',
                        basename($file), $line, mb_substr($definition, 0, 70))
                );
            }
        }
    }

    /**
     * Определения столбцов как логические строки: многострочный ENUM склеивается
     * в одно определение, иначе его продолжение выглядит как новый столбец.
     *
     * @return array<int, string> номер строки => определение
     */
    private function columnDefinitions(string $file): array
    {
        $definitions = [];
        $inside = false;
        $buffer = '';
        $startLine = 0;
        $depth = 0;

        foreach (explode("\n", (string)file_get_contents($file)) as $index => $raw) {
            $line = trim($raw);
            if (preg_match('/^CREATE TABLE/i', $line) === 1) {
                $inside = true;
                $buffer = '';
                continue;
            }
            if (!$inside || $line === '' || str_starts_with($line, '--')) {
                continue;
            }
            if (preg_match('/^\)\s*ENGINE/i', $line) === 1) {
                $inside = false;
                continue;
            }
            if ($buffer === '') {
                $startLine = $index + 1;
            }
            $buffer .= ($buffer === '' ? '' : ' ') . $line;
            $depth += substr_count($line, '(') - substr_count($line, ')');
            if ($depth <= 0 && str_ends_with($line, ',')) {
                $definitions[$startLine] = rtrim($buffer, ',');
                $buffer = '';
                $depth = 0;
                continue;
            }
            if ($depth <= 0 && !str_ends_with($line, ',')) {
                $definitions[$startLine] = $buffer;    // последнее определение без запятой
                $buffer = '';
                $depth = 0;
            }
        }
        return $definitions;
    }

    public function testIndexAndForeignKeyColumnsAreQuoted(): void
    {
        foreach ($this->migrations() as $file) {
            foreach (explode("\n", (string)file_get_contents($file)) as $number => $line) {
                if (preg_match('/^\s*(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX|CONSTRAINT|FOREIGN\s+KEY)/i', $line) !== 1) {
                    continue;
                }
                preg_match_all('/\(([^()]*)\)/', $line, $matches);
                foreach ($matches[1] ?? [] as $group) {
                    foreach (explode(',', $group) as $column) {
                        $column = trim($column);
                        if ($column === '' || is_numeric($column)) {
                            continue;
                        }
                        self::assertStringStartsWith('`', $column,
                            sprintf('%s строка %d: столбец %s в индексе без кавычек',
                                basename($file), $number + 1, $column));
                    }
                }
            }
        }
    }

    public function testInsertColumnListsAreQuoted(): void
    {
        foreach ($this->migrations() as $file) {
            $sql = (string)file_get_contents($file);
            preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?\w+`?\s*\(([^)]*)\)/i', $sql, $matches);
            foreach ($matches[1] ?? [] as $columns) {
                foreach (explode(',', $columns) as $column) {
                    $column = trim($column);
                    if ($column === '') {
                        continue;
                    }
                    self::assertStringStartsWith('`', $column,
                        basename($file) . ': столбец ' . $column . ' в INSERT без кавычек');
                }
            }
        }
    }

    public function testNoBareReservedWordBeforeType(): void
    {
        foreach ($this->migrations() as $file) {
            $sql = (string)file_get_contents($file);
            foreach (self::RESERVED as $word) {
                self::assertDoesNotMatchRegularExpression(
                    '/^\s*' . $word . '\s+(INT|BIGINT|VARCHAR|TEXT|ENUM|DATETIME|TINYINT|SMALLINT|JSON|CHAR)/im',
                    $sql,
                    basename($file) . ': зарезервированное слово «' . $word . '» использовано без кавычек'
                );
            }
        }
    }

    public function testStatementsSplitCleanly(): void
    {
        foreach ($this->migrations() as $file) {
            $sql = (string)preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($file));
            $statements = array_values(array_filter(
                array_map('trim', explode(";\n", $sql . "\n")),
                static fn(string $part): bool => trim($part, " \t\n;") !== ''
            ));
            self::assertNotEmpty($statements, basename($file) . ': не разбирается на выражения');
            foreach ($statements as $statement) {
                self::assertMatchesRegularExpression('/^(CREATE|INSERT|ALTER|UPDATE|DROP)/i', $statement,
                    basename($file) . ': выражение начинается не с команды — ' . mb_substr($statement, 0, 60));
            }
        }
    }
}
