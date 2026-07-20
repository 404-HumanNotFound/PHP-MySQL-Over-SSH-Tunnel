<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

/**
 * Thrown ONLY for configuration validation errors (programmer errors):
 * missing required keys, malformed hostnames/usernames, ports out of range,
 * an `ssh_key_path` that points at a non-existent file, and so on.
 *
 * Environmental failures — the ssh binary not being present/executable on
 * this machine, the ssh process dying, the forward not coming up in time,
 * or the current environment not being in the allowed list — must NEVER
 * surface as this exception. Those degrade to a logged warning plus a
 * direct-connection fallback (see TunnelManager::ensure()).
 */
final class TunnelException extends \InvalidArgumentException
{
}
