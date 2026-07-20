<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests\Support;

/**
 * Minimal PSR-3-shaped logger that just records warning() messages. Proves the
 * library's duck-typed logging seam works without pulling in psr/log.
 */
final class RecordingLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->warnings[] = (string) $message;
    }
}
