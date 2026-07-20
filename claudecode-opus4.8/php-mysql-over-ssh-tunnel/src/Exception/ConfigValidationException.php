<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Exception;

use InvalidArgumentException;

/**
 * Thrown ONLY for genuine configuration/programmer errors — e.g. an invalid
 * hostname, a `local_port` outside 1-65535, or an `ssh_key_path` that points at
 * a file which does not exist.
 *
 * Environmental failures (a missing/non-executable ssh binary, a tunnel that
 * fails to come up, or a disallowed environment) MUST NOT throw this — they are
 * handled by logging a warning and falling back to a direct connection. See
 * {@see \HumanNotFound\MysqlSshTunnel\TunnelManager} and the project README /
 * AGENT.md for the full throw-vs-fallback contract.
 */
final class ConfigValidationException extends InvalidArgumentException
{
}
