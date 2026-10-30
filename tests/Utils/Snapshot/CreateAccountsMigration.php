<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Utils\Snapshot;

use PhpSoftBox\Database\Migrations\AbstractMigration;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

/**
 * Таблица со внешним ключом, индексом и JSON-колонкой.
 */
final class CreateAccountsMigration extends AbstractMigration
{
    public function up(): void
    {
        $this->schema()->create('accounts', function (TableBlueprint $table): void {
            $table->id()->comment('Идентификатор счёта');
            $table->foreignId('currency_id')->comment('Валюта счёта');
            $table->string('name')->index()->comment('Название счёта');
            $table->json('settings')->nullable()->comment('Настройки');
            $table->foreignKey(['currency_id'], 'currencies', ['id']);
            $table->comment('Счета');
        });
    }
}
