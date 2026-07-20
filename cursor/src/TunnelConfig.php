<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

use HumanNotFound\MysqlSshTunnel\Contract\LoggerInterface;
use HumanNotFound\MysqlSshTunnel\Exception\InvalidConfigException;
use HumanNotFound\MysqlSshTunnel\Logging\ErrorLogLogger;

/**
 * Immutable, validated tunnel configuration.
 *
 * SSH authentication must use ssh_key_path, ssh-agent, or ~/.ssh/config —
 * passwords and interactive passphrases are never accepted. Any private key
 * referenced by ssh_key_path must be passphrase-less or already unlocked /
 * loaded into ssh-agent; this library never prompts for a passphrase because
 * proc_open() has no interactive terminal.
 */
final readonly class TunnelConfig
{
    public const LOCAL_PORT_RANDOM = 'random';

    /** Hostname / IPv4 allow-list — rejects shell metacharacters. */
    private const SERVER_PATTERN = '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*\.?$|^(?:(?:25[0-5]|2[0-4]\d|[01]?\d\d?)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d\d?)$/';

    /** SSH username allow-list. */
    private const USER_PATTERN = '/^[a-zA-Z0-9._-]{1,32}$/';

    public int|string $localPort;
    public string $server;
    public string $sshUser;
    public int $sshPort;
    public int $remotePort;
    public string $sshBinaryPath;
    public ?string $sshKeyPath;
    /** @var list<string>|null */
    public ?array $environments;
    public ?string $currentEnvironment;
    public LoggerInterface $logger;
    public float $connectTimeout;
    public bool $strictHostKeyChecking;
    public bool $isRandomPort;

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigException
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigException
     */
    private function __construct(array $config)
    {
        $required = ['server', 'ssh_user', 'remote_port', 'ssh_binary_path'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $config) || $config[$key] === null || $config[$key] === '') {
                throw new InvalidConfigException("Missing required config key: {$key}");
            }
        }

        if (!array_key_exists('local_port', $config)) {
            throw new InvalidConfigException('Missing required config key: local_port');
        }

        $localPort = $config['local_port'];
        if ($localPort === self::LOCAL_PORT_RANDOM || $localPort === 'random') {
            $this->localPort = self::LOCAL_PORT_RANDOM;
            $this->isRandomPort = true;
        } elseif (is_int($localPort) || (is_string($localPort) && ctype_digit($localPort))) {
            $port = (int) $localPort;
            $this->assertPort($port, 'local_port');
            $this->localPort = $port;
            $this->isRandomPort = false;
        } else {
            throw new InvalidConfigException("local_port must be an integer 1-65535 or the string 'random'");
        }

        $server = (string) $config['server'];
        if (!preg_match(self::SERVER_PATTERN, $server)) {
            throw new InvalidConfigException('server must be a valid hostname or IPv4 address (no shell metacharacters)');
        }
        $this->server = $server;

        $sshUser = (string) $config['ssh_user'];
        if (!preg_match(self::USER_PATTERN, $sshUser)) {
            throw new InvalidConfigException('ssh_user must match [a-zA-Z0-9._-]{1,32}');
        }
        $this->sshUser = $sshUser;

        $sshPort = array_key_exists('ssh_port', $config) ? (int) $config['ssh_port'] : 22;
        $this->assertPort($sshPort, 'ssh_port');
        $this->sshPort = $sshPort;

        $remotePort = (int) $config['remote_port'];
        $this->assertPort($remotePort, 'remote_port');
        $this->remotePort = $remotePort;

        $binary = self::expandHome((string) $config['ssh_binary_path']);
        if ($binary === '') {
            throw new InvalidConfigException('ssh_binary_path must be a non-empty path to the ssh executable');
        }
        // Existence/executability is an environmental concern handled at boot
        // time (fallback), not a validation throw — see TunnelManager.
        $this->sshBinaryPath = $binary;

        $keyPath = null;
        if (array_key_exists('ssh_key_path', $config) && $config['ssh_key_path'] !== null && $config['ssh_key_path'] !== '') {
            $keyPath = self::expandHome((string) $config['ssh_key_path']);
            if (!is_file($keyPath)) {
                throw new InvalidConfigException("ssh_key_path does not exist or is not a file: {$keyPath}");
            }
            if (!is_readable($keyPath)) {
                throw new InvalidConfigException("ssh_key_path is not readable: {$keyPath}");
            }
        }
        $this->sshKeyPath = $keyPath;

        if (array_key_exists('environments', $config) && $config['environments'] !== null) {
            if (!is_array($config['environments'])) {
                throw new InvalidConfigException('environments must be an array of strings when provided');
            }
            $envs = [];
            foreach ($config['environments'] as $env) {
                if (!is_string($env) || $env === '') {
                    throw new InvalidConfigException('environments entries must be non-empty strings');
                }
                $envs[] = $env;
            }
            $this->environments = $envs;
        } else {
            $this->environments = null;
        }

        $current = $config['current_environment'] ?? null;
        if ($current !== null && (!is_string($current) || $current === '')) {
            throw new InvalidConfigException('current_environment must be a non-empty string when provided');
        }
        $this->currentEnvironment = is_string($current) ? $current : null;

        $logger = $config['logger'] ?? null;
        if ($logger === null) {
            $this->logger = new ErrorLogLogger();
        } elseif ($logger instanceof LoggerInterface) {
            $this->logger = $logger;
        } elseif (is_object($logger) && method_exists($logger, 'warning')) {
            // PSR-3 LoggerInterface duck-type (avoids hard require of psr/log).
            $this->logger = new class ($logger) implements LoggerInterface {
                public function __construct(private object $inner)
                {
                }

                public function warning(string $message, array $context = []): void
                {
                    $this->inner->warning($message, $context);
                }
            };
        } else {
            throw new InvalidConfigException('logger must implement warning(string, array): void');
        }

        $timeout = $config['connect_timeout'] ?? 5.0;
        if (!is_numeric($timeout) || (float) $timeout <= 0) {
            throw new InvalidConfigException('connect_timeout must be a positive number of seconds');
        }
        $this->connectTimeout = (float) $timeout;

        $strict = $config['strict_host_key_checking'] ?? true;
        if (!is_bool($strict)) {
            throw new InvalidConfigException('strict_host_key_checking must be a boolean');
        }
        $this->strictHostKeyChecking = $strict;
    }

    /**
     * Stable identity for lockfile naming.
     *
     * Includes local_port only when it is a fixed integer — random-port
     * tunnels share one lockfile per (server, user, remote_port) so a later
     * boot can reuse the still-running process.
     */
    public function configHash(): string
    {
        $parts = [
            $this->server,
            $this->sshUser,
            (string) $this->remotePort,
            (string) $this->sshPort,
        ];
        if (!$this->isRandomPort) {
            $parts[] = (string) $this->localPort;
        }

        return hash('sha256', implode('|', $parts));
    }

    public function environmentAllowed(): bool
    {
        if ($this->environments === null) {
            return true;
        }

        if ($this->currentEnvironment === null) {
            return false;
        }

        return in_array($this->currentEnvironment, $this->environments, true);
    }

    /**
     * @throws InvalidConfigException
     */
    private function assertPort(int $port, string $name): void
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidConfigException("{$name} must be an integer between 1 and 65535");
        }
    }

    private static function expandHome(string $path): string
    {
        if ($path === '' || ($path[0] !== '~')) {
            return $path;
        }

        $home = getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? null) : null);
        if ($home === null || $home === false || $home === '') {
            return $path;
        }

        if ($path === '~') {
            return $home;
        }

        if (str_starts_with($path, '~/')) {
            return $home . substr($path, 1);
        }

        return $path;
    }
}
