<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Utils\Snapshot;

use PhpSoftBox\Database\Migrations\AbstractMigration;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

/**
 * Справочник валют со строками, которые создаёт сама миграция.
 */
final class CreateCurrenciesMigration extends AbstractMigration
{
    public function up(): void
    {
        $this->schema()->create('currencies', function (TableBlueprint $table): void {
            $table->id()->comment('Идентификатор валюты');
            $table->string('code', 3)->unique()->comment('Код ISO 4217');
            $table->string('title')->comment('Название');
            $table->comment('Валюты');
        });

        foreach ([['RUB', "Рубль; d'Artagnan"], ['USD', 'Доллар: США']] as [$code, $title]) {
            $this->connection()->execute(
                'INSERT INTO ' . $this->connection()->table('currencies') . ' (code, title) VALUES (:code, :title)',
                ['code' => $code, 'title' => $title],
            );
        }
    }
}
