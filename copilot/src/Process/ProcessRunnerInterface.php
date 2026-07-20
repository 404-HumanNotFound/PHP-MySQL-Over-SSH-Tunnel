<?php

namespace PhpMySqlOverSshTunnel\Process;

interface ProcessRunnerInterface
{
    /**
     * Start a process with the provided argv-style command array.
     * Return an implementation specific handle (resource or array).
     * The TunnelManager will rely on proc_get_status-style checks if native proc_open resource is returned.
     *
     * @param array $argv
     * @return mixed
     */
    public function start(array $argv): mixed;

    /**
     * Check if a started handle is still running.
     * @param mixed $handle
     */
    public function isRunning(mixed $handle): bool;

    /**
     * Terminate the provided handle.
     */
    public function terminate(mixed $handle): void;
}
