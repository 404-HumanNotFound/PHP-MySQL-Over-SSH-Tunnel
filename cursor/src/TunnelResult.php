<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

/**
 * Outcome of TunnelManager::boot().
 *
 * Prefer reading these properties over the global constants when you need
 * per-call accuracy (constants are defined only once per PHP process).
 */
final readonly class TunnelResult
{
    /**
     * @param bool   $active    Whether an SSH tunnel is actually forwarding traffic
     * @param int    $localPort Port to connect to (tunnel port when active, remote_port when not)
     * @param string $host      Host to connect to (127.0.0.1 when active, remote server when not)
     */
    public function __construct(
        public bool $active,
        public int $localPort,
        public string $host,
    ) {
    }
}
