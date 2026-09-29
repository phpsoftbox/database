<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\QueryBuilder;

use InvalidArgumentException;
use PhpSoftBox\Database\QueryBuilder\Compiler\StandardQueryCompiler;
use PhpSoftBox\Database\Tests\Utils\FakePdo;
use PhpSoftBox\Database\Tests\Utils\SpyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StandardQueryCompiler::class)]
#[CoversMethod(StandardQueryCompiler::class, 'compileUpdate')]
final class EmptyUpdateTest extends TestCase
{
    /**
     * Проверим, что UPDATE без колонок выбрасывает исключение, а не компилируется в SET 1 = 1.
     *
     * @see StandardQueryCompiler::compileUpdate()
     */
    #[Test]
    public function rejectsUpdateWithoutColumns(): void
    {
        $conn = new SpyConnection(new FakePdo('sqlite'));

        $this->expectException(InvalidArgumentException::class);

        $conn->query()->update('users', [])->where('id = :id', ['id' => 1])->toSql();
    }
}
