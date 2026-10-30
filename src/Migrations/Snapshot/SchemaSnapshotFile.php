<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations\Snapshot;

use PhpSoftBox\Database\Exception\ConfigurationException;

use function array_slice;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_file;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function substr;
use function trim;

/**
 * Читает и пишет снимок схемы в SQL-файл рядом с миграциями.
 *
 * Заголовок из строк `-- ключ: значение` хранит драйвер, префикс, таблицы с данными и
 * покрытые миграции (по одной на строку, чтобы изменения были видны в diff). Инструкции
 * разделены строкой, заканчивающейся `;`, и пустой строкой.
 */
final class SchemaSnapshotFile
{
    public const string FILE_NAME = 'schema.sql';

    private const int FORMAT = 1;

    private const string TITLE = '-- Снимок схемы PhpSoftBox: создан db:migrate:dump, не редактируйте вручную.';

    private const string SEPARATOR = ";\n\n";

    public static function exists(string $path): bool
    {
        return is_file($path);
    }

    public static function read(string $path): SchemaSnapshot
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new ConfigurationException('Не удалось прочитать снимок схемы: ' . $path);
        }

        $lines  = explode("\n", str_replace("\r\n", "\n", $content));
        $header = ['format' => null, 'driver' => null, 'prefix' => '', 'data' => [], 'migration' => []];
        $start  = count($lines);
        foreach ($lines as $index => $line) {
            if (!str_starts_with($line, '--')) {
                $start = $index;

                break;
            }
            if (preg_match('/^-- ([a-z]+):(?: (.*))?$/D', $line, $match) !== 1) {
                continue;
            }
            $value = trim($match[2] ?? '');
            match ($match[1]) {
                'data', 'migration' => $value !== '' ? $header[$match[1]][] = $value : null,
                default             => $header[$match[1]]                   = $value,
            };
        }

        if ($header['format'] !== (string) self::FORMAT || $header['driver'] === null || $header['driver'] === '') {
            throw new ConfigurationException('Неподдерживаемый формат снимка схемы: ' . $path);
        }

        $body       = trim(implode("\n", array_slice($lines, $start)));
        $statements = [];
        if ($body !== '') {
            if (!str_ends_with($body, ';')) {
                throw new ConfigurationException('Снимок схемы обрезан: ' . $path);
            }
            $statements = explode(self::SEPARATOR, substr($body, 0, -1));
        }

        return new SchemaSnapshot($header['driver'], $header['prefix'], $header['migration'], $header['data'], $statements);
    }

    public static function write(string $path, SchemaSnapshot $snapshot): void
    {
        $header = [
            self::TITLE,
            '-- format: ' . self::FORMAT,
            '-- driver: ' . $snapshot->driver,
            rtrim('-- prefix: ' . $snapshot->prefix),
        ];
        foreach ($snapshot->dataTables as $table) {
            $header[] = '-- data: ' . $table;
        }
        foreach ($snapshot->migrations as $id) {
            $header[] = '-- migration: ' . $id;
        }

        foreach ($snapshot->statements as $statement) {
            // Разделитель внутри инструкции сломал бы чтение файла.
            if (str_contains($statement . "\n", ";\n") || str_contains($statement, "\n\n") || str_starts_with($statement, '--')) {
                throw new ConfigurationException('Инструкцию нельзя сохранить в снимок схемы: ' . substr($statement, 0, 120));
            }
        }

        $body = $snapshot->statements === [] ? '' : "\n" . implode(self::SEPARATOR, $snapshot->statements) . ";\n";
        if (file_put_contents($path, implode("\n", $header) . "\n" . $body) === false) {
            throw new ConfigurationException('Не удалось записать снимок схемы: ' . $path);
        }
    }
}
