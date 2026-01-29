<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\Exception\ReadOnlyException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Database::class)]
#[CoversClass(DatabaseFactory::class)]
#[CoversClass(ConnectionManager::class)]
final class DatabaseFacadeTest extends TestCase
{
    /**
     * Проверяет, что Database::fromConfig создаёт фасад и позволяет получить соединение.
     */
    #[Test]
    public function buildsFacadeFromConfig(): void
    {
        $db = Database::fromConfig([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'dsn'    => 'sqlite:///:memory:',
                    'prefix' => 't_',
                ],
            ],
        ]);

        $db->execute('CREATE TABLE ' . $db->table('users') . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        $db->transaction(function (\PhpSoftBox\Database\Contracts\ConnectionInterface $conn): void {
            $conn->execute('INSERT INTO ' . $conn->table('users') . ' (name) VALUES (:name)', ['name' => 'Alice']);
        });

        $user = $db->fetchOne('SELECT name FROM ' . $db->table('users') . ' WHERE name = :name', ['name' => 'Alice']);
        self::assertNotNull($user);
        self::assertSame('Alice', $user['name']);
    }

    /**
     * Проверяет, что read()/write() работают для групповых подключений (main.read/main.write).
     */
    #[Test]
    public function supportsReadWriteGroups(): void
    {
        $db = Database::fromConfig([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'read' => [
                        'dsn'      => 'sqlite:///:memory:',
                        'readonly' => true,
                    ],
                    'write' => [
                        'dsn'      => 'sqlite:///:memory:',
                        'readonly' => false,
                    ],
                ],
            ],
        ]);

        $read = $db->read('main');
        $this->expectException(ReadOnlyException::class);
        $read->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        $write = $db->write('main');
        self::assertFalse($write->isReadOnly());
    }
}
