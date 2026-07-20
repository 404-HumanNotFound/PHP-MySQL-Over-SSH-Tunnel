<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

/**
 * Immutable outcome of a boot attempt.
 *
 * Carries exactly the information a caller needs to open a DB connection
 * WITHOUT having to read the global constants or catch anything:
 *
 *   - {@see $active} — whether the tunnel is actually up.
 *   - {@see $host}   — "127.0.0.1" when active, the remote server hostname on fallback.
 *   - {@see $port}   — the local tunnel port when active, the remote port on fallback.
 *
 * These three fields mirror the MYSQL_SSH_TUNNEL_ACTIVE / MYSQL_SSH_TUNNEL_HOST
 * / MYSQL_SSH_TUNNEL_LOCAL_PORT constants defined by {@see TunnelManager}.
 */
final readonly class TunnelResult
{
    public function __construct(
        public bool $active,
        public string $host,
        public int $port,
        public bool $reused = false,
        public ?string $message = null,
    ) {
    }

    /**
     * @return array{active: bool, host: string, port: int, reused: bool, message: string|null}
     */
    public function toArray(): array
    {
        return [
            'active' => $this->active,
            'host' => $this->host,
            'port' => $this->port,
            'reused' => $this->reused,
            'message' => $this->message,
        ];
    }
}
