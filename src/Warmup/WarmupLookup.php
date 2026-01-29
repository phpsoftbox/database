<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Warmup;

use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\QueryBuilder\SelectQueryBuilder;
use PhpSoftBox\DatabaseLookup\LookupSpec;
use UnexpectedValueException;

use function array_key_exists;
use function array_keys;
use function array_values;
use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function method_exists;
use function serialize;

final readonly class WarmupLookup
{
    public function __construct(
        private ConnectionInterface $connection,
        private WarmupStore $store,
        private string $connectionName,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function one(
        LookupSpec $lookup,
        WarmupReadMode $mode = WarmupReadMode::Use,
    ): ?array {
        $rows = $this->manyUnique($lookup, $mode);

        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function manyUnique(
        LookupSpec $lookup,
        WarmupReadMode $mode = WarmupReadMode::Use,
    ): array {
        $values = $lookup->lookupValues();
        if ($values === []) {
            return [];
        }

        $uniqueValues = $this->uniqueValues($values);
        if ($mode === WarmupReadMode::Bypass) {
            return $this->flattenUniqueRows(
                $uniqueValues,
                $this->uniqueRowsByValue(
                    $lookup,
                    $uniqueValues,
                    $this->fetchRows($lookup->values(array_values($uniqueValues))),
                ),
            );
        }

        $rowsByValue = [];
        $misses      = [];
        $keys        = [];

        foreach ($uniqueValues as $valueKey => $value) {
            $key             = $this->warmupKey($lookup, $value);
            $keys[$valueKey] = $key;

            if ($mode === WarmupReadMode::Fresh) {
                $misses[$valueKey] = $value;
                continue;
            }

            $entry = $this->store->get($key);
            if ($entry === null) {
                $misses[$valueKey] = $value;
                continue;
            }

            $row = $this->uniqueRowFromEntry($lookup, $entry);
            if ($row !== null) {
                $rowsByValue[$valueKey] = $row;
            }
        }

        if ($misses !== []) {
            $loadedRowsByValue = $this->uniqueRowsByValue(
                $lookup,
                $misses,
                $this->fetchRows($lookup->values(array_values($misses))),
            );

            foreach ($loadedRowsByValue as $rowValueKey => $row) {
                $rowsByValue[$rowValueKey] = $row;
                $this->store->set($keys[$rowValueKey], WarmupEntry::row($row));
            }

            foreach ($misses as $valueKey => $value) {
                if (!array_key_exists($valueKey, $loadedRowsByValue)) {
                    $this->store->set($keys[$valueKey], WarmupEntry::missing());
                }
            }
        }

        return $this->flattenUniqueRows($uniqueValues, $rowsByValue);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function manyGrouped(
        LookupSpec $lookup,
        WarmupReadMode $mode = WarmupReadMode::Use,
    ): array {
        $values = $lookup->lookupValues();
        if ($values === []) {
            return [];
        }

        $uniqueValues = $this->uniqueValues($values);
        if ($mode === WarmupReadMode::Bypass) {
            return $this->flattenGroupedRows(
                $uniqueValues,
                $this->groupRowsByValue(
                    $lookup,
                    $uniqueValues,
                    $this->fetchRows($lookup->values(array_values($uniqueValues))),
                ),
            );
        }

        $rowsByValue = [];
        $misses      = [];
        $keys        = [];

        foreach ($uniqueValues as $valueKey => $value) {
            $key             = $this->warmupKey($lookup, $value);
            $keys[$valueKey] = $key;

            if ($mode === WarmupReadMode::Fresh) {
                $misses[$valueKey] = $value;
                continue;
            }

            $entry = $this->store->get($key);
            if ($entry === null) {
                $misses[$valueKey] = $value;
                continue;
            }

            $rowsByValue[$valueKey] = $this->groupRowsFromEntry($entry);
        }

        if ($misses !== []) {
            $loadedGroups = $this->groupRowsByValue(
                $lookup,
                $misses,
                $this->fetchRows($lookup->values(array_values($misses))),
            );

            foreach ($misses as $valueKey => $value) {
                $groupRows              = $loadedGroups[$valueKey] ?? [];
                $rowsByValue[$valueKey] = $groupRows;
                $this->store->set($keys[$valueKey], WarmupEntry::rows($groupRows));
            }
        }

        return $this->flattenGroupedRows($uniqueValues, $rowsByValue);
    }

    /**
     * @return list<mixed>
     */
    public function existingValues(
        LookupSpec $lookup,
        WarmupReadMode $mode = WarmupReadMode::Use,
    ): array {
        $column = $lookup->lookupColumnName();
        $rows   = $this->manyGrouped($lookup, $mode);

        $found = [];
        foreach ($rows as $row) {
            if (array_key_exists($column, $row)) {
                $found[] = $row[$column];
            }
        }

        return $found;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(LookupSpec $lookup): array
    {
        $values = $lookup->lookupValues();
        if ($values === []) {
            return [];
        }

        $column = $lookup->lookupColumnName();
        $query  = $this->connection
            ->query()
            ->select()
            ->from($lookup->tableName());

        $this->applyCriteria($query, $lookup->whereCriteria());
        $query->whereIn($column, array_values($values));

        return $query->fetchAll();
    }

    /**
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

    /**
     * @param list<mixed> $values
     * @return array<string, mixed>
     */
    private function uniqueValues(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $unique[$this->valueKey($value)] ??= $value;
        }

        return $unique;
    }

    /**
     * @param array<string, mixed> $uniqueValues
     * @param array<string, array<string, mixed>> $rowsByValue
     * @return list<array<string, mixed>>
     */
    private function flattenUniqueRows(array $uniqueValues, array $rowsByValue): array
    {
        $rows = [];
        foreach (array_keys($uniqueValues) as $valueKey) {
            if (array_key_exists($valueKey, $rowsByValue)) {
                $rows[] = $rowsByValue[$valueKey];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $uniqueValues
     * @param array<string, list<array<string, mixed>>> $rowsByValue
     * @return list<array<string, mixed>>
     */
    private function flattenGroupedRows(array $uniqueValues, array $rowsByValue): array
    {
        $rows = [];
        foreach (array_keys($uniqueValues) as $valueKey) {
            foreach ($rowsByValue[$valueKey] ?? [] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $expectedValues
     * @param list<array<string, mixed>> $loadedRows
     * @return array<string, array<string, mixed>>
     */
    private function uniqueRowsByValue(LookupSpec $lookup, array $expectedValues, array $loadedRows): array
    {
        $rowsByValue = [];
        foreach ($loadedRows as $row) {
            $rowValueKey = $this->rowValueKey($lookup, $row);
            if ($rowValueKey === null || !array_key_exists($rowValueKey, $expectedValues)) {
                continue;
            }

            if (array_key_exists($rowValueKey, $rowsByValue)) {
                throw new UnexpectedValueException(
                    'Warmup unique lookup returned multiple rows for "'
                    . $lookup->lookupColumnName() . '" value.',
                );
            }

            $rowsByValue[$rowValueKey] = $row;
        }

        return $rowsByValue;
    }

    /**
     * @param array<string, mixed> $expectedValues
     * @param list<array<string, mixed>> $loadedRows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupRowsByValue(LookupSpec $lookup, array $expectedValues, array $loadedRows): array
    {
        $rowsByValue = [];
        foreach ($loadedRows as $row) {
            $rowValueKey = $this->rowValueKey($lookup, $row);
            if ($rowValueKey === null || !array_key_exists($rowValueKey, $expectedValues)) {
                continue;
            }

            $rowsByValue[$rowValueKey] ??= [];
            $rowsByValue[$rowValueKey][] = $row;
        }

        return $rowsByValue;
    }

    private function rowValueKey(LookupSpec $lookup, array $row): ?string
    {
        $column = $lookup->lookupColumnName();
        if (!array_key_exists($column, $row)) {
            return null;
        }

        return $this->valueKey($row[$column]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function uniqueRowFromEntry(LookupSpec $lookup, WarmupEntry $entry): ?array
    {
        if ($entry->row !== null) {
            return $entry->row;
        }

        if ($entry->rows !== null) {
            $count = count($entry->rows);
            if ($count > 1) {
                throw new UnexpectedValueException(
                    'Warmup unique lookup found grouped rows for "'
                    . $lookup->lookupColumnName() . '" value.',
                );
            }

            return $entry->rows[0] ?? null;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groupRowsFromEntry(WarmupEntry $entry): array
    {
        if ($entry->rows !== null) {
            return $entry->rows;
        }

        if ($entry->row !== null) {
            return [$entry->row];
        }

        return [];
    }

    private function warmupKey(LookupSpec $lookup, mixed $value): WarmupKey
    {
        return WarmupKey::composite($this->connectionName, $lookup->tableName(), $lookup->keyValuesFor($value));
    }

    private function valueKey(mixed $value): string
    {
        if ($value === null) {
            return 'null:';
        }

        if (is_bool($value)) {
            return 'bool:' . ($value ? '1' : '0');
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return 'scalar:' . (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return 'scalar:' . (string) $value;
        }

        return 'complex:' . serialize($value);
    }
}
