<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

/**
 * Outcome of TunnelManager::ensure().
 *
 * When $active is true the tunnel is up and your application should connect
 * to 127.0.0.1:$localPort. When $active is false the library has fallen back
 * to a direct connection: $localPort carries the *remote* MySQL port and
 * $host carries the remote server hostname — connect to $host:$localPort.
 *
 * $host always contains the correct hostname to use, so `->host` and
 * `->localPort` can be fed straight into a DSN without branching; the
 * MYSQL_SSH_TUNNEL_ACTIVE constant exists for code that only sees the
 * constants and therefore must branch on it to pick the host.
 */
final readonly class TunnelResult
{
    public function __construct(
        public bool $active,
        public int $localPort,
        public string $host,
    ) {
    }
}
