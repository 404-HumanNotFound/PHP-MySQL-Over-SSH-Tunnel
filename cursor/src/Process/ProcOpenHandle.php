<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Process;

/**
 * Real process handle backed by proc_open().
 */
final class ProcOpenHandle implements ProcessHandleInterface
{
    /** @param resource|object|null $process */
    public function __construct(
        private mixed $process,
        private ?int $pid,
    ) {
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        if ($this->process === null) {
            return false;
        }

        $status = @proc_get_status($this->process);
        if ($status === false) {
            return false;
        }

        return (bool) $status['running'];
    }

    public function terminate(): void
    {
        if ($this->process === null) {
            return;
        }

        @proc_terminate($this->process);
        @proc_close($this->process);
        $this->process = null;
    }
}
