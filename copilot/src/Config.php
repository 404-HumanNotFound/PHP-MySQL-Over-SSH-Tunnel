<?php

namespace PhpMySqlOverSshTunnel;

use PhpMySqlOverSshTunnel\Exception\ConfigValidationException;
use Psr\Log\LoggerInterface;

final class Config
{
    public readonly int|string $local_port;
    public readonly string $server;
    public readonly string $ssh_user;
    public readonly int $ssh_port;
    public readonly int $remote_port;
    public readonly string $ssh_binary_path;
    public readonly ?string $ssh_key_path;
    public readonly array $environments;
    public readonly ?string $current_environment;
    public readonly ?LoggerInterface $logger;
    public readonly int $connect_timeout;
    public readonly bool $strict_host_key_checking;
    /**
     * Optional process runner callable (for testing). Kept untyped for
     * maximum runtime compatibility across PHP versions.
     * @var mixed
     */
    public readonly mixed $process_runner;
    public readonly bool $temporary;

    public function __construct(array $config)
    {
        $this->local_port = $config['local_port'] ?? 3306;
        $this->server = $config['server'] ?? throw new ConfigValidationException('server is required');
        $this->ssh_user = $config['ssh_user'] ?? throw new ConfigValidationException('ssh_user is required');
        $this->ssh_port = isset($config['ssh_port']) ? (int)$config['ssh_port'] : 22;
        $this->remote_port = isset($config['remote_port']) ? (int)$config['remote_port'] : 3306;
        $this->ssh_binary_path = $config['ssh_binary_path'] ?? throw new ConfigValidationException('ssh_binary_path is required');
        $this->ssh_key_path = $config['ssh_key_path'] ?? null;
        $this->environments = $config['environments'] ?? [];
        $this->current_environment = $config['current_environment'] ?? getenv('APP_ENV') ?: null;
        $this->logger = $config['logger'] ?? null;
        $this->connect_timeout = isset($config['connect_timeout']) ? (int)$config['connect_timeout'] : 5;
        $this->strict_host_key_checking = $config['strict_host_key_checking'] ?? true;
        $this->process_runner = $config['process_runner'] ?? null;
        $this->temporary = $config['temporary'] ?? false;

        $this->validate();
    }

    private function validate(): void
    {
        // hostname/user allowlist: letters, digits, dashes, dots and underscores
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $this->server)) {
            throw new ConfigValidationException('Invalid server hostname');
        }
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $this->ssh_user)) {
            throw new ConfigValidationException('Invalid ssh_user');
        }

        if (!($this->local_port === 'random' || (is_int($this->local_port) && $this->local_port >=1 && $this->local_port <= 65535))) {
            throw new ConfigValidationException('local_port must be integer 1-65535 or the string "random"');
        }

        if ($this->ssh_port < 1 || $this->ssh_port > 65535) {
            throw new ConfigValidationException('ssh_port must be between 1 and 65535');
        }
        if ($this->remote_port < 1 || $this->remote_port > 65535) {
            throw new ConfigValidationException('remote_port must be between 1 and 65535');
        }

        if (!is_executable($this->ssh_binary_path)) {
            throw new ConfigValidationException('ssh_binary_path does not exist or is not executable: ' . $this->ssh_binary_path);
        }

        if ($this->ssh_key_path !== null && !file_exists($this->ssh_key_path)) {
            throw new ConfigValidationException('ssh_key_path set but file does not exist: ' . $this->ssh_key_path);
        }

        if ($this->logger !== null && !($this->logger instanceof LoggerInterface)) {
            throw new ConfigValidationException('logger must implement Psr\\Log\\LoggerInterface');
        }

        if ($this->process_runner !== null && !is_callable($this->process_runner)) {
            throw new ConfigValidationException('process_runner must be a callable accepting (array $cmd): resource|array');
        }

        if (!is_bool($this->temporary)) {
            throw new ConfigValidationException('temporary must be a boolean');
        }
    }
}
