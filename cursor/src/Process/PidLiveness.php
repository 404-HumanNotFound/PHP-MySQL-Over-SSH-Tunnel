<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Process;

/**
 * PID liveness helpers with posix /proc fallbacks.
 *
 * Design decision: without ext-posix, Linux checks /proc/{pid}; on other
 * platforms liveness is treated as unknown and the caller should re-verify
 * via a TCP connect to the local forward port.
 */
final class PidLiveness
{
    public static function isAlive(int $pid): ?bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            // Signal 0: check existence / permission without delivering a signal.
            return @posix_kill($pid, 0);
        }

        if (is_dir('/proc/' . $pid)) {
            return true;
        }

        // Unknown — caller should treat port reachability as the source of truth.
        if (PHP_OS_FAMILY === 'Linux') {
            return false;
        }

        return null;
    }
}
