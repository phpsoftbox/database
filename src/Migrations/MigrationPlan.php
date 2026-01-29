<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Migrations;

use PhpSoftBox\Database\Exception\ConfigurationException;

use function strcmp;
use function trim;
use function usort;

/**
 * Набор миграций с их id.
 *
 * Нужен, когда id приходит из имени файла, а не из объекта.
 */
final class MigrationPlan
{
    /**
     * @var list<array{id: string, migration: MigrationInterface}>
     */
    private array $items = [];

    public function add(string $id, MigrationInterface $migration): self
    {
        $id = trim($id);
        if ($id === '') {
            throw new ConfigurationException('Migration id must be non-empty.');
        }

        $this->items[] = ['id' => $id, 'migration' => $migration];

        return $this;
    }

    /**
     * @return list<array{id: string, migration: MigrationInterface}>
     */
    public function all(): array
    {
        // Важно: порядок должен быть по id (в нашем формате это по времени).
        $items = $this->items;
        usort($items, static fn ($a, $b) => strcmp($a['id'], $b['id']));

        return $items;
    }
}
