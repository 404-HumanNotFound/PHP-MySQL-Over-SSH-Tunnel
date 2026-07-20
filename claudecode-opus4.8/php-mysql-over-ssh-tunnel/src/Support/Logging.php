<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Support;

/**
 * Tiny logging seam.
 *
 * Keeps the library dependency-free: rather than importing
 * psr/log we duck-type against any object exposing PSR-3's
 * `warning(string|\Stringable $message, array $context = [])`. A real PSR-3
 * LoggerInterface satisfies this, and so does a hand-rolled stub in tests.
 *
 * When no logger is supplied we fall back to {@see error_log()}.
 */
final class Logging
{
    public const PREFIX = '[php-mysql-over-ssh-tunnel] ';

    public static function warn(?object $logger, string $message): void
    {
        if ($logger !== null && method_exists($logger, 'warning')) {
            $logger->warning(self::PREFIX . $message);

            return;
        }

        error_log(self::PREFIX . $message);
    }
}
