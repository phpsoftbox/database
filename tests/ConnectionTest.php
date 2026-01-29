<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\Connection;
use PhpSoftBox\Database\Tests\Utils\SpyLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DatabaseFactory::class)]
#[CoversClass(Connection::class)]
final class ConnectionTest extends TestCase
{
    /**
     * Проверяет, что можно выполнить запросы на sqlite::memory:, а префикс таблиц применяется через table().
     */
    #[Test]
    public function executesQueriesAndUsesPrefix(): void
    {
        $logger = new SpyLogger();

        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main' => [
                    'dsn'    => 'sqlite:///:memory:',
                    'prefix' => 't_',
                ],
            ],
        ], $logger);

        $conn = $factory->create('default');

        $conn->execute('CREATE TABLE ' . $conn->table('users') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $conn->execute('INSERT INTO ' . $conn->table('users') . ' (name) VALUES (:name)', ['name' => 'Alice']);

        $rows = $conn->fetchAll('SELECT id, name FROM ' . $conn->table('users'));

        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]['name']);

        // Проверяем, что логгер реально отрабатывает
        self::assertNotEmpty($logger->records);
        self::assertSame('debug', $logger->records[0]['level']);
        self::assertSame('DB query executed', $logger->records[0]['message']);
        self::assertArrayHasKey('sql', $logger->records[0]['context']);
    }

    /**
     * Проверяет, что transaction() делает rollback при исключении.
     */
    #[Test]
    public function rollsBackTransactionOnException(): void
    {
        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main' => [
                    'dsn' => 'sqlite:///:memory:',
                ],
            ],
        ]);

        $conn = $factory->create();
        $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        try {
            $conn->transaction(function ($c): void {
                $c->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);

                throw new RuntimeException('boom');
            });
            self::fail('Exception was expected.');
        } catch (RuntimeException) {
            // ok
        }

        $rows = $conn->fetchAll('SELECT name FROM users');
        self::assertSame([], $rows);
    }
}
