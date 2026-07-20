<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Process;

use RuntimeException;

/**
 * Default ProcessRunner using proc_open with individually escaped arguments.
 */
final class ProcOpenRunner implements ProcessRunnerInterface
{
    public function start(array $arguments): ProcessHandleInterface
    {
        if ($arguments === []) {
            throw new RuntimeException('Cannot start process with empty argument list');
        }

        $escaped = array_map(static fn (string $arg): string => escapeshellarg($arg), $arguments);
        $command = implode(' ', $escaped);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);

        if ($process === false) {
            throw new RuntimeException('proc_open failed to start: ' . $arguments[0]);
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
                fclose($pipe);
            }
        }

        $status = proc_get_status($process);
        $pid = is_array($status) ? ($status['pid'] ?? null) : null;

        return new ProcOpenHandle($process, $pid !== null ? (int) $pid : null);
    }
}
