<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Dsn;

use PhpSoftBox\Database\Exception\ConfigurationException;

use function is_array;
use function ltrim;
use function parse_str;
use function parse_url;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * Парсер URL-style DSN.
 *
 * Поддерживаемые примеры:
 * - sqlite:///:memory:
 * - sqlite:////abs/path/to/db.sqlite
 * - sqlite:///relative/path/to/db.sqlite
 * - postgres://user:pass@host:5432/dbname?sslmode=disable
 * - mariadb://user:pass@host:3306/dbname
 */
final class DsnParser
{
    public function parse(string $dsn): Dsn
    {
        $dsn = trim($dsn);
        if ($dsn === '') {
            throw new ConfigurationException('DSN is empty.');
        }

        // SQLite: parse_url() на некоторых вариантах (sqlite:///:memory:, sqlite:////abs/path)
        // может возвращать false, поэтому разбираем эти DSN вручную.
        if (str_starts_with($dsn, 'sqlite:')) {
            $rest = substr($dsn, strlen('sqlite:'));

            // Возможные варианты:
            // - "///:memory:" (из sqlite:///:memory:)
            // - "////tmp/test.sqlite" (из sqlite:////tmp/test.sqlite)
            // - "/relative/path.sqlite" (из sqlite:///relative/path.sqlite)
            // - "/:memory:"

            // Отдельно поддержим вариант sqlite:///:memory:
            if (str_starts_with($rest, '///')) {
                $rest = substr($rest, 2); // оставляем один ведущий '/'
            }

            // Нормализация пути:
            // - "/:memory:" -> ":memory:"
            // - "//abs/path" -> "/abs/path"
            // - "/relative/path" -> "relative/path"
            $path = $rest;
            if ($path === '/:memory:') {
                $path = ':memory:';
            } elseif (str_starts_with($path, '//')) {
                $path = substr($path, 1);
            } else {
                $path = ltrim($path, '/');
            }

            if ($path === '') {
                throw new ConfigurationException('SQLite DSN must contain a path (sqlite:///:memory: or sqlite:////path/to/db).');
            }

            return new Dsn(
                driver: 'sqlite',
                host: null,
                port: null,
                database: null,
                path: $path,
                user: null,
                password: null,
                params: [],
            );
        }

        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['scheme']) || $parts['scheme'] === '') {
            throw new ConfigurationException('Invalid DSN. Expected URL-style DSN like "sqlite:///:memory:".');
        }

        $driver = strtolower((string) $parts['scheme']);

        // Нормализация алиасов, чтобы остальной код работал с одним именованием драйверов.
        // php-pdo использует "pgsql" и "mysql", но мы хотим более говорящие названия в DSN.
        $driver = match ($driver) {
            'pgsql' => 'postgres',
            'mysql' => 'mariadb',
            default => $driver,
        };

        $host     = isset($parts['host']) ? (string) $parts['host'] : null;
        $port     = isset($parts['port']) ? (int) $parts['port'] : null;
        $user     = isset($parts['user']) ? (string) $parts['user'] : null;
        $password = isset($parts['pass']) ? (string) $parts['pass'] : null;
        $path     = isset($parts['path']) ? (string) $parts['path'] : null;
        $database = null;

        // Для сетевых драйверов считаем database = path без ведущего '/'
        if ($driver !== 'sqlite' && $path !== null && $path !== '') {
            $database = ltrim($path, '/');
        }

        /** @var array<string, string> $params */
        $params = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str((string) $parts['query'], $params);
            // parse_str может дать смешанные типы, нормализуем к string
            foreach ($params as $k => $v) {
                if (is_array($v)) {
                    unset($params[$k]);
                    continue;
                }
                $params[$k] = (string) $v;
            }
        }

        // sqlite: special cases
        if ($driver === 'sqlite') {
            // sqlite:///:memory: => path="/:memory:"
            // sqlite:////abs/path => path="//abs/path" (parse_url)
            if ($path === null || $path === '') {
                throw new ConfigurationException('SQLite DSN must contain a path (sqlite:///:memory: or sqlite:////path/to/db).');
            }

            $normalizedPath = $path;
            // /:memory: -> :memory:
            if ($normalizedPath === '/:memory:') {
                $normalizedPath = ':memory:';
            } elseif (str_starts_with($normalizedPath, '//')) {
                // //abs/path -> /abs/path
                $normalizedPath = substr($normalizedPath, 1);
            } else {
                // /relative/path -> relative/path
                $normalizedPath = ltrim($normalizedPath, '/');
            }

            return new Dsn(
                driver: $driver,
                host: null,
                port: null,
                database: null,
                path: $normalizedPath,
                user: $user,
                password: $password,
                params: $params,
            );
        }

        return new Dsn(
            driver: $driver,
            host: $host,
            port: $port,
            database: $database,
            path: $path,
            user: $user,
            password: $password,
            params: $params,
        );
    }
}
