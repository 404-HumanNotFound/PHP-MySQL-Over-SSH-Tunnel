<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

use Psr\Log\AbstractLogger;

/**
 * Default logger used when no PSR-3 logger is supplied in config: writes to
 * error_log() so warnings are visible in the SAPI/web-server error log even
 * in a completely un-bootstrapped standalone script.
 */
final class ErrorLogLogger extends AbstractLogger
{
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $rendered = (string) $message;

        // Minimal PSR-3 {placeholder} interpolation.
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof \Stringable || $value === null) {
                $rendered = str_replace('{' . $key . '}', (string) $value, $rendered);
            }
        }

        error_log(sprintf('[mysql-ssh-tunnel] %s: %s', strtoupper((string) $level), $rendered));
    }
}
