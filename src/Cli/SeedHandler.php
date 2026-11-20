<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Seed\SeedRunner;
use PhpSoftBox\Database\Seed\SeedTarget;
use Throwable;

use function is_string;
use function trim;

final readonly class SeedHandler implements HandlerInterface
{
    public function __construct(
        private ConnectionManagerInterface $connections,
        private SeedRunner $seeds,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        $scenario   = $runner->request()->param('scenario');
        $connection = $runner->request()->option('connection', 'default');

        if (!is_string($scenario) || trim($scenario) === '' || !is_string($connection) || trim($connection) === '') {
            $runner->io()->writeln('Укажите имя seed-сценария и корректное подключение.', 'error');

            return Response::FAILURE;
        }

        try {
            $this->seeds->run(
                $scenario,
                SeedTarget::Core,
                fn (): ConnectionInterface => $this->connections->write($connection),
            );
        } catch (Throwable $exception) {
            $runner->io()->writeln('Ошибка заполнения БД: ' . $exception->getMessage(), 'error');

            return Response::FAILURE;
        }

        $runner->io()->writeln('Применён seed-сценарий ' . $scenario . ', подключение: ' . $connection . '.', 'success');

        return Response::SUCCESS;
    }
}
