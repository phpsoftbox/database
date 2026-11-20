<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests;

use PhpSoftBox\CliApp\CliApp;
use PhpSoftBox\CliApp\Command\InMemoryCommandRegistry;
use PhpSoftBox\CliApp\Io\IoInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\Database\Cli\DatabaseCommandProvider;
use PhpSoftBox\Database\Cli\SeedHandler;
use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Seed\SeederInterface;
use PhpSoftBox\Database\Seed\SeedRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

#[CoversClass(SeedHandler::class)]
#[CoversClass(DatabaseCommandProvider::class)]
#[CoversClass(SeedRunner::class)]
#[CoversMethod(SeedHandler::class, 'run')]
#[CoversMethod(SeedRunner::class, 'run')]
final class SeedHandlerTest extends TestCase
{
    private ConnectionManager $connections;

    protected function setUp(): void
    {
        $this->connections = new ConnectionManager(new DatabaseFactory([
            'connections' => [
                'default' => 'core',
                'core'    => ['dsn' => 'sqlite:///:memory:'],
                'other'   => ['dsn' => 'sqlite:///:memory:'],
            ],
        ]));

        foreach (['default', 'other'] as $name) {
            $this->connections->write($name)->execute('CREATE TABLE settings (id INTEGER PRIMARY KEY)');
        }
    }

    /**
     * Проверим, что повторный запуск сценария пишет только в явно выбранное подключение и не дублирует запись.
     *
     * @see SeedHandler::run()
     * @see SeedRunner::run()
     */
    #[Test]
    public function runsRepeatableScenarioOnSelectedConnection(): void
    {
        $seeder = $this->createMock(SeederInterface::class);
        $seeder->expects($this->exactly(2))->method('run')->willReturnCallback(
            static function (ConnectionInterface $connection): void {
                $connection->execute('INSERT INTO settings (id) VALUES (1) ON CONFLICT (id) DO NOTHING');
            },
        );

        $app = $this->app($seeder);
        self::assertSame(Response::SUCCESS, $app->runCommand('db:seed', ['settings', '--connection=other'])->code);
        self::assertSame(Response::SUCCESS, $app->runCommand('db:seed', ['settings', '--connection=other'])->code);
        self::assertSame([], $this->connections->write()->fetchAll('SELECT id FROM settings'));
        self::assertSame([['id' => 1]], $this->connections->write('other')->fetchAll('SELECT id FROM settings'));
    }

    /**
     * Проверим, что команда откатывает запись в подключении по умолчанию, если сценарий завершился ошибкой.
     *
     * @see SeedHandler::run()
     * @see SeedRunner::run()
     */
    #[Test]
    public function rollsBackWritesAfterScenarioFailure(): void
    {
        $seeder = $this->createMock(SeederInterface::class);
        $seeder->expects($this->once())->method('run')->willReturnCallback(
            static function (ConnectionInterface $connection): void {
                $connection->query()->insert('settings', ['id' => 1])->execute();

                throw new RuntimeException('Ошибка сценария.');
            },
        );

        self::assertSame(Response::FAILURE, $this->app($seeder)->runCommand('db:seed', ['settings'])->code);
        self::assertSame([], $this->connections->write()->fetchAll('SELECT id FROM settings'));
    }

    /**
     * Проверим, что команда общей БД отклоняет сценарий, зарегистрированный только для арендаторов.
     *
     * @see SeedHandler::run()
     * @see SeedRunner::run()
     */
    #[Test]
    public function rejectsTenantOnlyScenario(): void
    {
        $seeder = $this->createMock(SeederInterface::class);
        $seeder->expects($this->never())->method('run');

        self::assertSame(Response::FAILURE, $this->app($seeder, 'tenant')->runCommand('db:seed', ['settings'])->code);
    }

    private function app(SeederInterface $seeder, string $scope = 'core'): CliApp
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            ['settings.seeder', $seeder],
            [SeedHandler::class, new SeedHandler(
                $this->connections,
                new SeedRunner($container, ['settings' => ['service' => 'settings.seeder', 'scope' => $scope]]),
            )],
        ]);

        $registry = new InMemoryCommandRegistry(withDefaultCommands: false);

        new DatabaseCommandProvider()->register($registry);

        return new CliApp($registry, $this->createStub(IoInterface::class), $container);
    }
}
