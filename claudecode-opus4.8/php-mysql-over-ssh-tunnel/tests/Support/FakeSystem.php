<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests\Support;

use HumanNotFound\MysqlSshTunnel\System\SystemInterface;

/**
 * In-memory {@see SystemInterface} for tests. Records interactions and lets a
 * test dictate the outcome of every OS-level operation — so the full
 * detect/reuse/start/fallback/shutdown logic runs without spawning `ssh` or
 * opening a socket.
 */
final class FakeSystem implements SystemInterface
{
    public bool $executable = true;

    /** @var list<array{argv: list<string>, logFile: string}> */
    public array $spawned = [];

    public int $nextPid = 4242;

    /** @var array<int, bool> Per-PID liveness override. */
    public array $alive = [];

    public bool $defaultAlive = true;

    public bool $listening = true;

    /** @var list<int> */
    public array $terminated = [];

    public int $freePort = 55555;

    /** @var list<callable> */
    public array $shutdownCallbacks = [];

    public function isExecutable(string $path): bool
    {
        return $this->executable;
    }

    public function spawn(array $argv, string $logFile): int
    {
        $this->spawned[] = ['argv' => $argv, 'logFile' => $logFile];

        return $this->nextPid;
    }

    public function isAlive(int $pid): bool
    {
        return $this->alive[$pid] ?? $this->defaultAlive;
    }

    public function terminate(int $pid): void
    {
        $this->terminated[] = $pid;
    }

    public function isListening(string $host, int $port, float $timeout): bool
    {
        return $this->listening;
    }

    public function findFreePort(): int
    {
        return $this->freePort;
    }

    public function registerShutdown(callable $fn): void
    {
        $this->shutdownCallbacks[] = $fn;
    }
}
