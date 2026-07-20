<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

/**
 * Real process handle backed by a proc_open() resource.
 */
final class ProcOpenProcessHandle implements ProcessHandleInterface
{
    /** @var resource|null */
    private $process;

    /** @var resource|null */
    private $stderr;

    private int $pid;

    private string $errorOutput = '';

    /**
     * @param resource $process proc_open() resource
     * @param resource $stderr  non-blocking stderr pipe of the process
     */
    public function __construct($process, $stderr)
    {
        $this->process = $process;
        $this->stderr = $stderr;

        $status = proc_get_status($process);
        $this->pid = (int) ($status['pid'] ?? 0);
    }

    public function isRunning(): bool
    {
        if ($this->process === null) {
            return false;
        }

        $status = proc_get_status($this->process);

        return (bool) ($status['running'] ?? false);
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function terminate(): void
    {
        if ($this->process === null) {
            return;
        }

        $this->drainStderr();

        if ($this->stderr !== null) {
            @fclose($this->stderr);
            $this->stderr = null;
        }

        @proc_terminate($this->process);
        @proc_close($this->process);
        $this->process = null;
    }

    public function getErrorOutput(): string
    {
        $this->drainStderr();

        return $this->errorOutput;
    }

    private function drainStderr(): void
    {
        if ($this->stderr === null) {
            return;
        }

        // The pipe is non-blocking, so this returns immediately with
        // whatever ssh has written so far.
        $chunk = @stream_get_contents($this->stderr);
        if (is_string($chunk) && $chunk !== '') {
            $this->errorOutput .= $chunk;
        }
    }
}
