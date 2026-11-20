<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Seed;

use Closure;
use InvalidArgumentException;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use Psr\Container\ContainerInterface;

use function is_string;
use function trim;

final readonly class SeedRunner
{
    /**
     * @param array<string, array{service: non-empty-string, scope: 'core'|'tenant'|'both'}> $scenarios
     */
    public function __construct(
        private ContainerInterface $container,
        private array $scenarios = [],
    ) {
    }

    /**
     * @param Closure(): ConnectionInterface $connection
     */
    public function run(string $name, SeedTarget $target, Closure $connection): void
    {
        $scenario = $this->scenarios[$name] ?? null;
        if ($scenario === null) {
            throw new InvalidArgumentException('Неизвестный seed-сценарий: ' . $name);
        }

        $scope = $scenario['scope'] ?? null;
        if ($scope !== 'both' && $scope !== $target->value) {
            throw new InvalidArgumentException('Seed-сценарий ' . $name . ' недоступен для области ' . $target->value . '.');
        }

        $service = $scenario['service'] ?? null;
        if (!is_string($service) || trim($service) === '') {
            throw new InvalidArgumentException('Не задан сервис для seed-сценария: ' . $name);
        }

        $seeder = $this->container->get($service);
        if (!$seeder instanceof SeederInterface) {
            throw new InvalidArgumentException('Сервис seed-сценария ' . $name . ' должен реализовывать ' . SeederInterface::class . '.');
        }

        $connection()->transaction(static fn (ConnectionInterface $transaction) => $seeder->run($transaction));
    }
}
