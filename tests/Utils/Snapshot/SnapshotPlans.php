<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Utils\Snapshot;

use PhpSoftBox\Database\Migrations\MigrationPlan;

use function in_array;

/**
 * Планы миграций для проверок снимка схемы.
 */
final class SnapshotPlans
{
    public const string CURRENCIES = '20260101000000_create_currencies';
    public const string ACCOUNTS   = '20260101000100_create_accounts';
    public const string NOTE       = '20260101000200_add_account_note';

    /**
     * @param list<string> $ids Миграции, файлы которых есть в каталоге.
     */
    public static function plan(array $ids): MigrationPlan
    {
        $plan = new MigrationPlan();

        foreach ([self::CURRENCIES => new CreateCurrenciesMigration(), self::ACCOUNTS => new CreateAccountsMigration(), self::NOTE => new AddAccountNoteMigration()] as $id => $migration) {
            if (in_array($id, $ids, true)) {
                $plan->add($id, $migration);
            }
        }

        return $plan;
    }
}
