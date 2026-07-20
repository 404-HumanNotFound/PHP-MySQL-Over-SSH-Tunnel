<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Collects log records in memory so tests can assert on warnings.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function hasWarningContaining(string $needle): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === 'warning' && str_contains($record['message'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
