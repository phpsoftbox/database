<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Utils\Snapshot;

use PhpSoftBox\Database\Migrations\AbstractMigration;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

/**
 * Изменение существующей таблицы после снимка.
 */
final class AddAccountNoteMigration extends AbstractMigration
{
    public function up(): void
    {
        $this->schema()->addColumn('accounts', function (TableBlueprint $table): void {
            $table->string('note')->nullable()->comment('Заметка');
        });
    }
}
