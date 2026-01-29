<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Validator;

use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\QueryBuilder\SelectQueryBuilder;
use PhpSoftBox\Validator\Db\Contracts\DatabaseValidationAdapterInterface;

use function is_array;

final readonly class DatabaseValidationAdapter implements DatabaseValidationAdapterInterface
{
    public function __construct(
        private ConnectionManagerInterface $connections,
    ) {
    }

    public function exists(string $table, array $criteria, ?string $connection = null): bool
    {
        $query = $this->baseQuery($table, $connection);
        $this->applyCriteria($query, $criteria);

        return $query->fetchOne() !== null;
    }

    public function unique(
        string $table,
        array $criteria,
        ?string $connection = null,
        ?string $ignoreColumn = null,
        mixed $ignoreValue = null,
    ): bool {
        $query = $this->baseQuery($table, $connection);
        $this->applyCriteria($query, $criteria);

        if ($ignoreColumn !== null && $ignoreValue !== null) {
            $query->where($ignoreColumn . ' != :_ignore', ['_ignore' => $ignoreValue]);
        }

        return $query->fetchOne() === null;
    }

    private function baseQuery(string $table, ?string $connection): SelectQueryBuilder
    {
        $conn = $this->connections->connection($connection ?? 'default');

        return $conn->query()
            ->select('1')
            ->from($table)
            ->limit(1);
    }

    /**
     * Применяет критерии к запросу.
     *
     * @param array<string, mixed> $criteria
     */
    private function applyCriteria(SelectQueryBuilder $query, array $criteria): void
    {
        $index = 0;

        foreach ($criteria as $column => $value) {
            if (is_array($value)) {
                $query->whereIn((string) $column, $value);
                continue;
            }

            if ($value === null) {
                $query->whereNull((string) $column);
                continue;
            }

            $index++;
            $param = '_p' . $index;
            $query->where((string) $column . ' = :' . $param, [$param => $value]);
        }
    }
}
