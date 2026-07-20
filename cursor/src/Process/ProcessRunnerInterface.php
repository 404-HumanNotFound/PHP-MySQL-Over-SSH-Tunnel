<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Process;

/**
 * Starts external processes. Injected so unit tests can fake proc_open.
 */
interface ProcessRunnerInterface
{
    /**
     * @param list<string> $arguments Full argv including binary as [0]
     */
    public function start(array $arguments): ProcessHandleInterface;
}
