<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Utils;

use PDO;
use PDOException;
use PhpSoftBox\Database\Database;
use PhpSoftBox\Database\Driver\DriversEnum;
use RuntimeException;

use function extension_loaded;

/**
 * Хелперы для интеграционных тестов, которые зависят от docker-compose сервисов.
 */
final class IntegrationDatabases
{
    private const string MARIADB_DSN_URL  = 'mariadb://phpsoftbox:phpsoftbox@mariadb:3306/phpsoftbox';
    private const string MYSQL_DSN_URL    = 'mysql://phpsoftbox:phpsoftbox@mysql:3306/phpsoftbox';
    private const string POSTGRES_DSN_URL = 'postgres://phpsoftbox:phpsoftbox@postgres:5432/phpsoftbox';
    private const string SQLITE_DSN_URL   = 'sqlite:///:memory:';

    /**
     * Пытается поднять Database для Postgres из docker-compose.
     * @throws RuntimeException
     */
    public static function postgresDatabase(array $config = []): Database
    {
        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException('pdo_pgsql extension is not available.');
        }

        $config = $config ?: self::getDefaultConfigForDriver(DriversEnum::POSTGRES);

        try {
            $db = Database::fromConfig($config);
            $db->fetchOne('SELECT 1');
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to connect to Postgres database.', 0, $e);
        }

        return $db;
    }

    /**
     * Пытается поднять Database для Postgres из docker-compose.
     * @throws RuntimeException
     */
    public static function mariadbDatabase(array $config = []): Database
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('pdo_mysql extension is not available.');
        }

        $config = $config ?: self::getDefaultConfigForDriver(DriversEnum::MARIADB);

        try {
            $db = Database::fromConfig($config);
            $db->fetchOne('SELECT 1');
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to connect to MariaDB database.', 0, $e);
        }

        return $db;
    }

    /**
     * Пытается поднять Database для MySQL из docker-compose.
     * @throws RuntimeException
     */
    public static function mysqlDatabase(array $config = []): Database
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('pdo_mysql extension is not available.');
        }

        $config = $config ?: self::getDefaultConfigForDriver(DriversEnum::MYSQL);

        try {
            $db = Database::fromConfig($config);
            $db->fetchOne('SELECT 1');
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to connect to MySQL database.', 0, $e);
        }

        return $db;
    }

    /**
     * Пытается поднять Database для Postgres из docker-compose.
     * @throws RuntimeException
     */
    public static function sqliteDatabase(array $config = []): Database
    {
        $config = $config ?: self::getDefaultConfigForDriver(DriversEnum::SQLITE);

        try {
            $db = Database::fromConfig($config);
            $db->fetchOne('SELECT 1');
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to connect to Sqlite database.', 0, $e);
        }

        return $db;
    }

    /**
     * Возвращает конфиг по умолчанию для PostgresSQL.
     */
    public static function getDefaultConfigForDriver(DriversEnum $driver): array
    {
        $dsn = match ($driver) {
            DriversEnum::POSTGRES => self::POSTGRES_DSN_URL,
            DriversEnum::MYSQL    => self::MYSQL_DSN_URL,
            DriversEnum::MARIADB  => self::MARIADB_DSN_URL,
            DriversEnum::SQLITE   => self::SQLITE_DSN_URL,
        };

        return [
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'dsn'     => $dsn,
                    'options' => [
                        PDO::ATTR_TIMEOUT => 2,
                    ],
                ],
            ],
        ];
    }
}
