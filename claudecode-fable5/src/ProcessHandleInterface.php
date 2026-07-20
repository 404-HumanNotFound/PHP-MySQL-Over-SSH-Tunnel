<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

/**
 * Handle to a spawned tunnel process. Abstracted behind an interface so
 * tests can fake the ssh process instead of opening real SSH connections.
 */
interface ProcessHandleInterface
{
    public function isRunning(): bool;

    public function getPid(): int;

    /**
     * Terminate the process (SIGTERM) and release all resources. Safe to
     * call more than once.
     */
    public function terminate(): void;

    /**
     * Whatever the process wrote to stderr so far — used to enrich the
     * fallback warning when the tunnel fails to come up. Never contains
     * key material (ssh does not print private keys to stderr, and this
     * library never reads key files itself).
     */
    public function getErrorOutput(): string;
}
