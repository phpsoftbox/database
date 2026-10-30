<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Migrations;

use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Database\Migrations\Snapshot\SchemaSnapshot;
use PhpSoftBox\Database\Migrations\Snapshot\SchemaSnapshotFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(SchemaSnapshotFile::class)]
#[CoversMethod(SchemaSnapshotFile::class, 'read')]
#[CoversMethod(SchemaSnapshotFile::class, 'write')]
final class SchemaSnapshotFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'schema');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /**
     * Проверим, что записанный снимок читается без потерь: многострочные инструкции, пустой префикс и списки заголовка.
     *
     * @see SchemaSnapshotFile::write()
     * @see SchemaSnapshotFile::read()
     */
    #[Test]
    public function roundTripsSnapshot(): void
    {
        $snapshot = new SchemaSnapshot(
            driver: 'mariadb',
            prefix: '',
            migrations: ['20260101000000_first', '20260101000100_second'],
            dataTables: ['currencies'],
            statements: ["CREATE TABLE `a` (\n  `id` int NOT NULL COMMENT 'ключ; id'\n)", 'INSERT INTO `a` (`id`) VALUES (1)'],
        );

        SchemaSnapshotFile::write($this->path, $snapshot);

        self::assertEquals($snapshot, SchemaSnapshotFile::read($this->path));
    }

    /**
     * Проверим, что инструкция с разделителем внутри не записывается, иначе файл прочитался бы иначе.
     *
     * @see SchemaSnapshotFile::write()
     */
    #[Test]
    public function rejectsStatementWithSeparator(): void
    {
        $this->expectException(ConfigurationException::class);

        SchemaSnapshotFile::write($this->path, new SchemaSnapshot('sqlite', '', [], [], ["INSERT INTO a VALUES ('x;\ny')"]));
    }
}
