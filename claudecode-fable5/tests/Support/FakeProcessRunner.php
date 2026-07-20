<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests\Support;

use HumanNotFound\MysqlSshTunnel\ProcessHandleInterface;
use HumanNotFound\MysqlSshTunnel\ProcessRunnerInterface;

/**
 * Fake proc_open() layer. Records every command it is asked to run and
 * delegates to an optional $onStart callback so a test can e.g. bind a real
 * listening socket on the port parsed from the command — simulating the
 * forward coming up without any ssh process.
 */
final class FakeProcessRunner implements ProcessRunnerInterface
{
    /** @var list<string> */
    public array $commands = [];

    /** @var callable(string): (ProcessHandleInterface|null) */
    private $onStart;

    /**
     * @param callable(string): (ProcessHandleInterface|null)|null $onStart
     *        return value is what start() returns; defaults to a running
     *        FakeProcessHandle
     */
    public function __construct(?callable $onStart = null)
    {
        $this->onStart = $onStart ?? static fn (string $command): ProcessHandleInterface => new FakeProcessHandle();
    }

    public function start(string $command): ?ProcessHandleInterface
    {
        $this->commands[] = $command;

        return ($this->onStart)($command);
    }

    public function wasCalled(): bool
    {
        return $this->commands !== [];
    }

    /**
     * Extract the local port from the `-L 'PORT:127.0.0.1:REMOTE'` argument
     * of a recorded command.
     */
    public static function localPortFromCommand(string $command): int
    {
        if (preg_match("/-L' '?([0-9]+):127\\.0\\.0\\.1:[0-9]+/", $command, $m) !== 1) {
            throw new \RuntimeException('No -L forward found in command: ' . $command);
        }

        return (int) $m[1];
    }
}
