<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\System;

/**
 * Real, `proc_open()`-backed implementation of {@see SystemInterface}.
 *
 * Design notes:
 *  - We keep the spawned process resources keyed by PID so that
 *    {@see terminate()} can prefer proc_terminate() and, for random-port
 *    tunnels, the shutdown function can cleanly stop the child.
 *  - Fixed-port tunnels are intentionally NOT closed here: we neither
 *    proc_close() (which would block waiting for the long-lived `ssh -N`) nor
 *    proc_terminate() them. When PHP exits, the child is reparented and keeps
 *    running so the next request can reuse it. Its stdio is redirected to a log
 *    file (never our pipes) precisely so it can outlive us without SIGPIPE.
 */
final class NativeSystem implements SystemInterface
{
    /** @var array<int, resource> proc_open() handles keyed by PID. */
    private array $handles = [];

    public function isExecutable(string $path): bool
    {
        return is_file($path) && is_executable($path);
    }

    public function spawn(array $argv, string $logFile): int
    {
        $command = self::buildCommandLine($argv);

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logFile, 'a'],
            2 => ['file', $logFile, 'a'],
        ];

        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            // Could not even start proc_open — surface a sentinel the manager
            // treats as "not alive", which drives the fallback path.
            return -1;
        }

        $status = proc_get_status($process);
        $pid = (int) $status['pid'];
        $this->handles[$pid] = $process;

        return $pid;
    }

    /**
     * Build a safe command line by passing EVERY argument through
     * escapeshellarg() individually and joining with spaces. This is the only
     * place a command string is assembled, and it never interpolates raw
     * config values.
     *
     * @param list<string> $argv
     */
    public static function buildCommandLine(array $argv): string
    {
        return implode(' ', array_map(
            static fn (string $arg): string => escapeshellarg($arg),
            $argv
        ));
    }

    public function isAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // Preferred: POSIX signal 0 liveness probe.
        if (function_exists('posix_kill')) {
            if (posix_kill($pid, 0)) {
                return true;
            }

            // posix_kill() returned false. Distinguish the two errno cases:
            //   EPERM (1) => the process exists but we may not signal it => ALIVE.
            //   ESRCH (3) => no such process => dead.
            if (function_exists('posix_get_last_error')) {
                $eperm = defined('PCNTL_EPERM') ? PCNTL_EPERM : 1;

                return posix_get_last_error() === $eperm;
            }

            return false;
        }

        // Documented fallback: Linux /proc.
        if (is_dir('/proc') && @is_dir('/proc/' . $pid)) {
            return true;
        }

        // Documented last-resort fallback: if we ourselves spawned it in this
        // request, ask proc_get_status(); otherwise liveness is UNKNOWN and we
        // report false so the caller reconnects rather than trusting a stale
        // lockfile.
        if (isset($this->handles[$pid])) {
            $status = proc_get_status($this->handles[$pid]);

            return (bool) $status['running'];
        }

        return false;
    }

    public function terminate(int $pid): void
    {
        if (isset($this->handles[$pid])) {
            @proc_terminate($this->handles[$pid], defined('SIGTERM') ? SIGTERM : 15);
            unset($this->handles[$pid]);

            return;
        }

        if ($pid > 0 && function_exists('posix_kill')) {
            @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
        }
    }

    public function isListening(string $host, int $port, float $timeout): bool
    {
        $errno = 0;
        $errstr = '';
        $conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($conn !== false) {
            fclose($conn);

            return true;
        }

        return false;
    }

    public function findFreePort(): int
    {
        // Bind to port 0 so the OS assigns a free ephemeral port, read it back,
        // then release immediately before handing it to `ssh`.
        //
        // RACE WINDOW (documented tradeoff): between releasing this socket and
        // `ssh` binding the same port, another process could grab it. This is
        // inherent to the "ask the OS for a free port, then use it in a
        // separate process" approach. ssh is spawned with
        // `-o ExitOnForwardFailure=yes`, so if the port is stolen the forward
        // fails fast and the library falls back to a direct connection rather
        // than silently succeeding on the wrong port.
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            // Extremely unlikely; 0 signals the manager to treat this as a
            // failed start and fall back.
            return 0;
        }

        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        if ($name === false) {
            return 0;
        }

        $pos = strrpos($name, ':');

        return $pos === false ? 0 : (int) substr($name, $pos + 1);
    }

    public function registerShutdown(callable $fn): void
    {
        register_shutdown_function($fn);
    }
}
