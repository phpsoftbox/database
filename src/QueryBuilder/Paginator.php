<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use function max;

/**
 * Пагинатор для запросов.
 *
 * Идея: значение perPage задаётся в одном месте (например, через DI),
 * а DBAL-уровень остаётся простым и предсказуемым.
 */
final readonly class Paginator
{
    private int $perPage;

    public function __construct(int $perPage)
    {
        $this->perPage = max(1, $perPage);
    }

    /**
     * Выполняет пагинацию SELECT-запроса.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, pages: int}
     */
    public function paginate(SelectQueryBuilder $builder, int $page): array
    {
        return $builder->paginate($page, $this->perPage);
    }

    public function perPage(): int
    {
        return $this->perPage;
    }
}
