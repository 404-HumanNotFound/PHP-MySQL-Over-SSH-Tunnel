<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Process;

/**
 * Handle for a started background process (typically ssh).
 */
interface ProcessHandleInterface
{
    public function getPid(): ?int;

    public function isRunning(): bool;

    public function terminate(): void;
}
