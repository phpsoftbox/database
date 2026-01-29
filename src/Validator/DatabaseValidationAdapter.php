<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Validator;

use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\Contracts\WarmupAwareConnectionInterface;
use PhpSoftBox\Database\QueryBuilder\SelectQueryBuilder;
use PhpSoftBox\DatabaseLookup\LookupSpec;
use PhpSoftBox\Validator\Db\Contracts\DatabaseBulkValidationAdapterInterface;
use PhpSoftBox\Validator\Db\Contracts\ExistingValuesQueryInterface;

use function array_key_exists;
use function is_array;

final readonly class DatabaseValidationAdapter implements DatabaseBulkValidationAdapterInterface
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

    public function existingValues(LookupSpec $lookup, ?string $connection = null): ExistingValuesQueryInterface
    {
        return new DatabaseExistingValuesQuery(
            fetch: fn (): array => $this->fetchExistingValues($lookup, $connection),
            fetchWarmup: fn (): array => $this->fetchExistingValuesWarmup($lookup, $connection),
        );
    }

    /**
     * @return list<mixed>
     */
    private function fetchExistingValues(
        LookupSpec $lookup,
        ?string $connection = null,
    ): array {
        $values = $lookup->lookupValues();
        if ($values === []) {
            return [];
        }

        $column = $lookup->lookupColumnName();
        $query  = $this->selectQuery($connection)
            ->select([$column])
            ->from($lookup->tableName());

        $this->applyCriteria($query, $lookup->whereCriteria());
        $query->whereIn($column, $values);

        $found = [];
        foreach ($query->fetchAll() as $row) {
            if (array_key_exists($column, $row)) {
                $found[] = $row[$column];
            }
        }

        return $found;
    }

    /**
     * @return list<mixed>
     */
    private function fetchExistingValuesWarmup(
        LookupSpec $lookup,
        ?string $connection = null,
    ): array {
        $values = $lookup->lookupValues();
        if ($values === []) {
            return [];
        }

        $conn = $this->connections->connection($connection ?? 'default');
        if (!$conn instanceof WarmupAwareConnectionInterface) {
            return $this->fetchExistingValues($lookup, $connection);
        }

        return $conn->warmup()->existingValues($lookup);
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
        return $this->selectQuery($connection)
            ->select('1')
            ->from($table)
            ->limit(1);
    }

    private function selectQuery(?string $connection): SelectQueryBuilder
    {
        $conn = $this->connections->connection($connection ?? 'default');

        return $conn->query()->select();
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
                $query->whereIn($column, $value);
                continue;
            }

            if ($value === null) {
                $query->whereNull($column);
                continue;
            }

            $index++;
            $param = '_p' . $index;
            $query->where($column . ' = :' . $param, [$param => $value]);
        }
    }
}
