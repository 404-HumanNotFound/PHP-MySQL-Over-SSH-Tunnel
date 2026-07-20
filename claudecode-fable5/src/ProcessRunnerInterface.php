<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

/**
 * Starts the tunnel process. The default implementation wraps proc_open();
 * tests inject a fake so the suite never spawns a real ssh process.
 */
interface ProcessRunnerInterface
{
    /**
     * @param string $command fully escaped command line (every argument has
     *                        already been passed through escapeshellarg())
     *
     * @return ProcessHandleInterface|null null when the process could not be
     *                                     started at all (environmental
     *                                     failure — the caller falls back,
     *                                     it does not throw)
     */
    public function start(string $command): ?ProcessHandleInterface;
}
