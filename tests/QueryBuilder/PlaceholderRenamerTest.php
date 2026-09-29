<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\QueryBuilder;

use PhpSoftBox\Database\QueryBuilder\PlaceholderRenamer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlaceholderRenamer::class)]
#[CoversMethod(PlaceholderRenamer::class, 'prefix')]
final class PlaceholderRenamerTest extends TestCase
{
    /**
     * Проверим, что именованные параметры и их плейсхолдеры получают префикс, а :in_1 не задевает :in_10.
     *
     * @see PlaceholderRenamer::prefix()
     */
    #[Test]
    public function prefixesNamedParamsAndPlaceholders(): void
    {
        $result = PlaceholderRenamer::prefix(
            'SELECT * FROM t WHERE id IN (:in_1, :in_10)',
            ['in_1' => 1, 'in_10' => 10],
            '__sq1_',
        );

        self::assertSame('SELECT * FROM t WHERE id IN (:__sq1_in_1, :__sq1_in_10)', $result['sql']);
        self::assertSame(['__sq1_in_1' => 1, '__sq1_in_10' => 10], $result['params']);
    }

    /**
     * Проверим, что строковые литералы, экранированные идентификаторы и приведения типов не изменяются.
     *
     * @see PlaceholderRenamer::prefix()
     */
    #[Test]
    public function keepsLiteralsIdentifiersAndCasts(): void
    {
        $result = PlaceholderRenamer::prefix(
            'SELECT \':id\' AS "x:id", `y:id`, col::id FROM t WHERE id = :id',
            ['id' => 5],
            '__sq1_',
        );

        self::assertSame('SELECT \':id\' AS "x:id", `y:id`, col::id FROM t WHERE id = :__sq1_id', $result['sql']);
        self::assertSame(['__sq1_id' => 5], $result['params']);
    }
}
