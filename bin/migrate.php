<?php

declare(strict_types=1);

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\Migrations\FileMigrationLoader;
use PhpSoftBox\Database\Migrations\MigrationPlan;
use PhpSoftBox\Database\Migrations\MigrationRunner;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Пример запуска миграций через нативный php-cli.
 *
 * Это не CLI фреймворк и не mg-команда. Просто демонстрация того,
 * как можно собрать будущую CLI Application.
 *
 * Usage:
 *   php packages/Database/bin/migrate.php /abs/path/to/migrations [connectionGroup]
 *
 * Где:
 * - migrations directory содержит файлы формата YYYYMMDDHHMMSS_description.php
 * - connectionGroup — имя группы из конфига (например, "main"), по умолчанию "default"
 */

$argv = $_SERVER['argv'] ?? [];
$dir = $argv[1] ?? null;
$connectionGroup = $argv[2] ?? 'default';

if (!is_string($dir) || $dir === '') {
    fwrite(STDERR, "Usage: php packages/Database/bin/migrate.php /abs/path/to/migrations [connectionGroup]\n");
    exit(2);
}

if (!is_string($connectionGroup) || $connectionGroup === '') {
    fwrite(STDERR, "Invalid connectionGroup.\n");
    exit(2);
}

if (!is_dir($dir)) {
    fwrite(STDERR, "Migrations directory does not exist: {$dir}\n");
    exit(2);
}

// Минимальный пример: конфиг можно получить откуда угодно (php array файл, env, DI container и т.д.)
$configFile = __DIR__ . '/../migrations.config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "Config file not found: {$configFile}\n");
    fwrite(STDERR, "Create packages/Database/migrations.config.php returning array with 'connections'.\n");
    exit(2);
}

/** @var array<string,mixed> $config */
$config = require $configFile;

$factory = new DatabaseFactory($config);
$connections = new ConnectionManager($factory);

$loader = new FileMigrationLoader();
$plan = new MigrationPlan();
foreach ($loader->load($dir) as $item) {
    $plan->add($item['id'], $item['migration']);
}

$runner = new MigrationRunner(connections: $connections, connectionName: $connectionGroup);

try {
    $applied = $runner->migrate($plan);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: {$e->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, 'Applied: ' . count($applied) . "\n");
foreach ($applied as $id) {
    fwrite(STDOUT, " - {$id}\n");
}
