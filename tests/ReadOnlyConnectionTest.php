<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\Connection;
use PhpSoftBox\Database\Exception\ReadOnlyException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseFactory::class)]
#[CoversClass(Connection::class)]
#[CoversClass(ReadOnlyException::class)]
final class ReadOnlyConnectionTest extends TestCase
{
    /**
     * Проверяет, что execute() запрещён для readonly подключения.
     */
    #[Test]
    public function executeIsForbidden(): void
    {
        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'ro',
                'ro'      => [
                    'dsn'      => 'sqlite:///:memory:',
                    'readonly' => true,
                ],
            ],
        ]);

        $conn = $factory->create('ro');

        $this->expectException(ReadOnlyException::class);
        $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    }

    /**
     * Проверяет, что transaction() запрещён для readonly подключения.
     */
    #[Test]
    public function transactionIsForbidden(): void
    {
        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'ro',
                'ro'      => [
                    'dsn'      => 'sqlite:///:memory:',
                    'readonly' => true,
                ],
            ],
        ]);

        $conn = $factory->create('ro');

        $this->expectException(ReadOnlyException::class);
        $conn->transaction(static function (): void {
            // noop
        });
    }
}
