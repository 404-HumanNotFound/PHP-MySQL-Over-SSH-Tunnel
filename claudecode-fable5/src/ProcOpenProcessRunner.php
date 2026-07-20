<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

/**
 * Default ProcessRunnerInterface implementation: spawns the (already fully
 * escapeshellarg()-escaped) ssh command via proc_open().
 *
 * stdin is closed immediately: combined with `-o BatchMode=yes` this
 * guarantees ssh can never sit waiting for an interactive password or
 * passphrase prompt — it fails fast instead, and the manager falls back.
 */
final class ProcOpenProcessRunner implements ProcessRunnerInterface
{
    public function start(string $command): ?ProcessHandleInterface
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        // No interactive terminal exists to answer prompts through; close
        // stdin so ssh sees EOF instead of blocking. stdout is unused for a
        // `-N` (no remote command) tunnel.
        fclose($pipes[0]);
        fclose($pipes[1]);
        stream_set_blocking($pipes[2], false);

        return new ProcOpenProcessHandle($process, $pipes[2]);
    }
}
