<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Logging;

use HumanNotFound\MysqlSshTunnel\Contract\LoggerInterface;

/**
 * Default logger that writes warnings to PHP's error_log().
 */
final class ErrorLogLogger implements LoggerInterface
{
    public function warning(string $message, array $context = []): void
    {
        $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        error_log('[php-mysql-over-ssh-tunnel] ' . $message . $suffix);
    }
}
