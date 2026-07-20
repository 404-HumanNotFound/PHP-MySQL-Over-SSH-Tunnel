<?php

namespace PhpMySqlOverSshTunnel\Process;

final class NativeProcessRunner implements ProcessRunnerInterface
{
    public function start(array $argv): mixed
    {
        $cmd = array_map('strval', $argv);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        // Close stdin immediately
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    public function isRunning(mixed $handle): bool
    {
        if (!is_array($handle) || !isset($handle['proc'])) {
            return false;
        }
        $status = proc_get_status($handle['proc']);
        return $status['running'] ?? false;
    }

    public function terminate(mixed $handle): void
    {
        if (!is_array($handle) || !isset($handle['proc'])) {
            return;
        }
        @proc_terminate($handle['proc']);
        @proc_close($handle['proc']);
    }
}
