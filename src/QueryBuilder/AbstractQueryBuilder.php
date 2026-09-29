<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\QueryBuilder;

use PhpSoftBox\Database\Contracts\ConnectionInterface;

use function trim;

abstract class AbstractQueryBuilder
{
    /**
     * Счётчик встроенных подзапросов: даёт уникальный префикс их параметрам.
     */
    private int $embeddedQueryCounter = 0;

    public function __construct(
        protected readonly ConnectionInterface $connection,
    ) {
    }

    /**
     * Готовит скомпилированный подзапрос к встраиванию в текущий запрос.
     *
     * Именованные параметры подзапроса получают уникальный префикс (__sq{N}_), чтобы не совпасть
     * с параметрами внешнего запроса и других подзапросов (у каждого билдера свой счётчик :in_1, :where_1, ...).
     *
     * @param array{sql: string, params: array<string|int, mixed>} $compiled
     * @return array{sql: string, params: array<string|int, mixed>}
     */
    protected function embedSubquery(array $compiled): array
    {
        if ($compiled['params'] === []) {
            return $compiled;
        }

        $this->embeddedQueryCounter++;

        return PlaceholderRenamer::prefix($compiled['sql'], $compiled['params'], '__sq' . $this->embeddedQueryCounter . '_');
    }

    protected function applyTablePrefix(string $table): string
    {
        return $this->connection->table(trim($table));
    }

    /**
     * Экранирует идентификатор колонки/таблицы с поддержкой dot-нотации (t.col) через Quoter текущего драйвера.
     */
    protected function quoteDottedIdent(string $ident): string
    {
        return $this->connection->driver()->createQuoter()->dotted($ident);
    }
}
