<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Contract;

/**
 * Minimal logger contract compatible with PSR-3's warning() signature.
 *
 * Accepting this (or a PSR-3 LoggerInterface) avoids a hard dependency on
 * psr/log while still allowing real PSR-3 loggers to be passed in.
 */
interface LoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void;
}
