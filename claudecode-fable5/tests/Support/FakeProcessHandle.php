<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests\Support;

use HumanNotFound\MysqlSshTunnel\ProcessHandleInterface;

/**
 * Fake ssh process for unit tests: no real process is ever spawned.
 */
final class FakeProcessHandle implements ProcessHandleInterface
{
    public bool $terminated = false;

    public function __construct(
        private bool $running = true,
        private readonly int $pid = 12345,
        private readonly string $errorOutput = '',
    ) {
    }

    public function isRunning(): bool
    {
        return $this->running && !$this->terminated;
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function terminate(): void
    {
        $this->terminated = true;
        $this->running = false;
    }

    public function getErrorOutput(): string
    {
        return $this->errorOutput;
    }
}
