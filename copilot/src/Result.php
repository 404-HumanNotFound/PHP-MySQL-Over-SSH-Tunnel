<?php

namespace PhpMySqlOverSshTunnel;

final class Result
{
    public readonly bool $active;
    public readonly int $port;
    public readonly string $host;

    public function __construct(bool $active, int $port, string $host)
    {
        $this->active = $active;
        $this->port = $port;
        $this->host = $host;
    }

    public function toArray(): array
    {
        return ['active' => $this->active, 'port' => $this->port, 'host' => $this->host];
    }
}
