<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Schema;

use PhpSoftBox\Database\SchemaBuilder\Compiler\MariaDbSchemaCompiler;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Database\SchemaBuilder\UseCurrentFormatsEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MariaDbSchemaCompiler::class)]
final class MariaDbSchemaCompilerUseCurrentTest extends TestCase
{
    /**
     * Проверяет, что MariaDbSchemaCompiler компилирует DEFAULT/ON UPDATE CURRENT_TIMESTAMP для datetime.
     */
    #[Test]
    public function compilesUseCurrentForDatetime(): void
    {
        $t = new TableBlueprint('users');

        $t->datetime('updated_at')
                    ->nullable(false)
                    ->useCurrent()
                    ->useCurrentOnUpdate();

        $sql = new MariaDbSchemaCompiler()->compileCreateTable($t);

        self::assertStringContainsString('`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $sql);
    }

    /**
     * Проверяет, что useCurrent применяется к колонке datetime независимо от устаревшего аргумента формата.
     */
    #[Test]
    public function appliesUseCurrentRegardlessOfFormat(): void
    {
        $t = new TableBlueprint('users');

        $t->datetime('updated_at')->useCurrent(true, UseCurrentFormatsEnum::TIMESTAMP);

        $sql = new MariaDbSchemaCompiler()->compileCreateTable($t);

        self::assertStringContainsString('`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql);
    }
}
