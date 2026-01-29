<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Cli;

use PhpSoftBox\CliApp\Command\ArgumentDefinition;
use PhpSoftBox\CliApp\Command\Command;
use PhpSoftBox\CliApp\Command\CommandRegistryInterface;
use PhpSoftBox\CliApp\Command\OptionDefinition;
use PhpSoftBox\CliApp\Loader\CommandProviderInterface;

final class DatabaseCommandProvider implements CommandProviderInterface
{
    public function register(CommandRegistryInterface $registry): void
    {
        $registry->register(Command::define(
            name: 'db:migrate',
            description: 'Применить миграции',
            signature: [
                new OptionDefinition(
                    name: 'path',
                    short: 'p',
                    description: 'Относительный путь внутри базы миграций',
                    required: false,
                    default: null,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'connection',
                    short: 'c',
                    description: 'Имя подключения или группы',
                    required: false,
                    default: null,
                    type: 'string',
                ),
            ],
            handler: MigrateHandler::class,
        ));

        $registry->register(Command::define(
            name: 'db:migrate:rollback',
            description: 'Откатить миграции',
            signature: [
                new OptionDefinition(
                    name: 'path',
                    short: 'p',
                    description: 'Относительный путь внутри базы миграций',
                    required: false,
                    default: null,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'connection',
                    short: 'c',
                    description: 'Имя подключения или группы',
                    required: false,
                    default: null,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'steps',
                    short: 's',
                    description: 'Количество откатываемых миграций',
                    required: false,
                    default: 1,
                    type: 'int',
                ),
            ],
            handler: RollbackHandler::class,
        ));

        $registry->register(Command::define(
            name: 'db:migrate:make',
            description: 'Создать файл миграции',
            signature: [
                new ArgumentDefinition(
                    name: 'name',
                    description: 'Имя миграции (например, create_users_table)',
                    required: true,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'path',
                    short: 'p',
                    description: 'Относительный путь внутри базы миграций',
                    required: false,
                    default: null,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'connection',
                    short: 'c',
                    description: 'Имя подключения или группы',
                    required: false,
                    default: null,
                    type: 'string',
                ),
            ],
            handler: MakeMigrationHandler::class,
        ));
    }
}
