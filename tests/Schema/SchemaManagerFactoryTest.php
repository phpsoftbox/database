<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\Driver\MariaDbDriver;
use PhpSoftBox\Database\Driver\MySqlDriver;
use PhpSoftBox\Database\Schema\MariaDbSchemaManager;
use PhpSoftBox\Database\Schema\MySqlSchemaManager;
use PhpSoftBox\Database\Schema\SchemaManagerFactory;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaManagerFactory::class)]
final class SchemaManagerFactoryTest extends TestCase
{
    #[Test]
    public function distinguishesMySqlAndMariaDbDespiteSharedPdoDriver(): void
    {
        $factory = new SchemaManagerFactory();

        $mariaDb = $factory->create(new SpyConnection(
            new FakePdo('mysql'),
            driver: new MariaDbDriver(),
        ));
        $mysql = $factory->create(new SpyConnection(
            new FakePdo('mysql'),
            driver: new MySqlDriver(),
        ));

        self::assertInstanceOf(MariaDbSchemaManager::class, $mariaDb);
        self::assertNotInstanceOf(MySqlSchemaManager::class, $mariaDb);
        self::assertInstanceOf(MySqlSchemaManager::class, $mysql);
        self::assertNotInstanceOf(MariaDbSchemaManager::class, $mysql);
    }
}
