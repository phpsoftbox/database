<?php

declare(strict_types=1);

namespace PhpSoftBox\Database\Tests\Utils;

use Psr\Log\AbstractLogger;

final class SpyLogger extends AbstractLogger
{
    /**
     * @var list<array{level:string,message:string,context:array}>
     */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
