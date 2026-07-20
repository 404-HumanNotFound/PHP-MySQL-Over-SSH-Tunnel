<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\System;

/**
 * Seam over the handful of OS-level operations the tunnel manager needs.
 *
 * Abstracting these behind an interface is what lets the test suite exercise
 * the full detect/reuse/start/fallback/shutdown logic WITHOUT ever spawning a
 * real `ssh` process or opening a real socket (see the FakeSystem test double).
 *
 * {@see NativeSystem} is the real, `proc_open()`-backed implementation used in
 * production.
 */
interface SystemInterface
{
    /**
     * Is $path an existing, executable file? (Environmental check — a false
     * result triggers the direct-connection fallback, it does not throw.)
     */
    public function isExecutable(string $path): bool;

    /**
     * Spawn a detached background process from a raw argument vector.
     *
     * Implementations MUST escape every argument individually (never build the
     * command as one interpolated string) and MUST return the child PID.
     *
     * @param list<string> $argv    Raw arguments; $argv[0] is the binary path.
     * @param string       $logFile Path to redirect the child's stdout/stderr to.
     *
     * @return int The spawned process id.
     */
    public function spawn(array $argv, string $logFile): int;

    /** Is the given PID currently alive? */
    public function isAlive(int $pid): bool;

    /** Best-effort terminate the given PID (SIGTERM). */
    public function terminate(int $pid): void;

    /** Is something accepting TCP connections on $host:$port within $timeout seconds? */
    public function isListening(string $host, int $port, float $timeout): bool;

    /**
     * Allocate an OS-assigned ephemeral port, then release it, returning the
     * port number so it can be handed to `ssh`.
     */
    public function findFreePort(): int;

    /** Register a shutdown callback (wraps register_shutdown_function()). */
    public function registerShutdown(callable $fn): void;
}
