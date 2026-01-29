<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\Exception\ReadOnlyException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseFactory::class)]
#[CoversClass(ConnectionManager::class)]
#[CoversClass(ReadOnlyException::class)]
final class ConnectionGroupsTest extends TestCase
{
    /**
     * Проверяет, что можно описать read/write как вложенные секции (connections.main.read/write)
     * и затем получать их через ConnectionManager::read()/write().
     */
    #[Test]
    public function resolvesReadWriteConnections(): void
    {
        $factory = new DatabaseFactory([
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

        $manager = new ConnectionManager($factory);

        $read = $manager->read('main');
        $this->expectException(ReadOnlyException::class);
        $read->execute('CREATE TABLE users_read (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        $write = $manager->write('main');
        self::assertFalse($write->isReadOnly());
        $write->execute('CREATE TABLE users_write (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    }

    /**
     * Проверяет, что алиас default указывает на группу read/write при запросе default.read/default.write.
     */
    #[Test]
    public function resolvesDefaultGroupAliasForReadWrite(): void
    {
        $factory = new DatabaseFactory([
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

        $manager = new ConnectionManager($factory);

        $read = $manager->read();
        $this->expectException(ReadOnlyException::class);
        $read->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        $write = $manager->write();
        self::assertFalse($write->isReadOnly());
        $write->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    }

    /**
     * Проверяет, что read/write работают даже при отсутствии отдельных секций read/write.
     */
    #[Test]
    public function resolvesReadWriteWhenSingleConnectionDefined(): void
    {
        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'dsn'      => 'sqlite:///:memory:',
                    'readonly' => false,
                ],
            ],
        ]);

        $manager = new ConnectionManager($factory);

        $read = $manager->read('main');
        self::assertFalse($read->isReadOnly());
        $read->execute('CREATE TABLE users_read (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        $write = $manager->write('main');
        self::assertFalse($write->isReadOnly());
        $write->execute('CREATE TABLE users_write (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    }
}
