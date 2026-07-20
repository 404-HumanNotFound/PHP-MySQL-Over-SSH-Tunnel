<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

use Psr\Log\LoggerInterface;

/**
 * Immutable configuration value object for the SSH tunnel.
 *
 * Construction performs strict validation and throws {@see TunnelException}
 * for anything that is a *programmer* error (bad hostname, port out of range,
 * ssh_key_path pointing at a missing file, ...). See the class docblock of
 * TunnelException for the throw-vs-fallback contract.
 *
 * Design decision: whether the file at `ssh_binary_path` exists / is
 * executable is deliberately NOT validated here. The *shape* of the value
 * (non-empty string, no shell metacharacters needed since it is always
 * escapeshellarg()'d) is a config concern, but the binary's presence on this
 * particular machine is an environmental concern: the same committed config
 * may be valid on one developer's machine and not another's. TunnelManager
 * checks the binary at runtime and falls back to a direct connection with a
 * logged warning instead of throwing.
 *
 * SSH authentication: this library NEVER accepts, reads, logs, or prompts
 * for SSH passwords or key passphrases. `ssh_key_path` is a file *path*
 * only — it is handed to the ssh binary via `-i` and its contents are never
 * touched by PHP. Because the tunnel process is spawned via proc_open() with
 * no interactive terminal (and `-o BatchMode=yes`), any key referenced by
 * `ssh_key_path` must either be passphrase-less or already unlocked/loaded
 * into a running ssh-agent. Keys with a locked passphrase will simply fail
 * to authenticate and the library will fall back to a direct connection.
 */
final class TunnelConfig
{
    /**
     * Hostnames: must start and end with an alphanumeric character and may
     * contain dots and hyphens in between. This intentionally rejects shell
     * metacharacters AND anything starting with `-` (which could otherwise
     * be parsed as an ssh option — argument injection).
     */
    private const SERVER_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9.\-]{0,251}[A-Za-z0-9])?$/';

    /**
     * POSIX-ish usernames: letters, digits, underscore, dot, hyphen; must
     * start with a letter or underscore. Rejects shell metacharacters and
     * a leading `-`.
     */
    private const USER_PATTERN = '/^[A-Za-z_][A-Za-z0-9._\-]{0,63}$/';

    public const RANDOM_PORT = 'random';

    /**
     * @param int|string        $localPort  1-65535 or the string 'random'
     * @param list<string>|null $environments null = allow all environments
     */
    private function __construct(
        public readonly int|string $localPort,
        public readonly string $server,
        public readonly string $sshUser,
        public readonly int $remotePort,
        public readonly string $sshBinaryPath,
        public readonly ?string $sshKeyPath,
        public readonly ?array $environments,
        public readonly ?string $currentEnvironment,
        public readonly bool $strictHostKeyChecking,
        public readonly float $connectTimeout,
        public readonly ?LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws TunnelException on any config validation error
     */
    public static function fromArray(array $config): self
    {
        foreach (['server', 'ssh_user', 'remote_port', 'ssh_binary_path'] as $required) {
            if (!isset($config[$required])) {
                throw new TunnelException(sprintf('Missing required config key "%s".', $required));
            }
        }

        $server = $config['server'];
        if (!is_string($server) || preg_match(self::SERVER_PATTERN, $server) !== 1) {
            throw new TunnelException(
                'Config key "server" must be a hostname or IPv4 address matching '
                . self::SERVER_PATTERN . ' (no shell metacharacters, no leading "-").'
            );
        }

        $sshUser = $config['ssh_user'];
        if (!is_string($sshUser) || preg_match(self::USER_PATTERN, $sshUser) !== 1) {
            throw new TunnelException(
                'Config key "ssh_user" must be a plain username matching '
                . self::USER_PATTERN . ' (no shell metacharacters, no leading "-").'
            );
        }

        $remotePort = $config['remote_port'];
        if (!is_int($remotePort) || $remotePort < 1 || $remotePort > 65535) {
            throw new TunnelException('Config key "remote_port" must be an integer between 1 and 65535.');
        }

        $localPort = $config['local_port'] ?? self::RANDOM_PORT;
        if ($localPort !== self::RANDOM_PORT
            && (!is_int($localPort) || $localPort < 1 || $localPort > 65535)
        ) {
            throw new TunnelException(
                'Config key "local_port" must be an integer between 1 and 65535, or the string "random".'
            );
        }

        $sshBinaryPath = $config['ssh_binary_path'];
        if (!is_string($sshBinaryPath) || $sshBinaryPath === '') {
            throw new TunnelException('Config key "ssh_binary_path" must be a non-empty string path to the ssh executable.');
        }

        $sshKeyPath = $config['ssh_key_path'] ?? null;
        if ($sshKeyPath !== null) {
            if (!is_string($sshKeyPath) || $sshKeyPath === '') {
                throw new TunnelException('Config key "ssh_key_path" must be a non-empty string path when provided.');
            }
            // A key path that points nowhere is a programmer error (typo in
            // config), not an environmental failure — fail loudly at boot.
            if (!is_file($sshKeyPath)) {
                throw new TunnelException(sprintf(
                    'Config key "ssh_key_path" points to a file that does not exist: "%s".',
                    $sshKeyPath
                ));
            }
        }

        $environments = $config['environments'] ?? null;
        if ($environments !== null) {
            if (!is_array($environments)) {
                throw new TunnelException('Config key "environments" must be an array of environment names.');
            }
            foreach ($environments as $env) {
                if (!is_string($env) || $env === '') {
                    throw new TunnelException('Config key "environments" must contain only non-empty strings.');
                }
            }
            $environments = array_values($environments);
        }

        $currentEnvironment = $config['current_environment'] ?? null;
        if ($currentEnvironment !== null && !is_string($currentEnvironment)) {
            throw new TunnelException('Config key "current_environment" must be a string when provided.');
        }

        $strict = $config['strict_host_key_checking'] ?? true;
        if (!is_bool($strict)) {
            throw new TunnelException('Config key "strict_host_key_checking" must be a boolean.');
        }

        $connectTimeout = $config['connect_timeout'] ?? 5.0;
        if (!is_int($connectTimeout) && !is_float($connectTimeout)) {
            throw new TunnelException('Config key "connect_timeout" must be a number of seconds.');
        }
        $connectTimeout = (float) $connectTimeout;
        if ($connectTimeout <= 0) {
            throw new TunnelException('Config key "connect_timeout" must be greater than zero.');
        }

        $logger = $config['logger'] ?? null;
        if ($logger !== null && !$logger instanceof LoggerInterface) {
            throw new TunnelException('Config key "logger" must implement Psr\Log\LoggerInterface.');
        }

        if (array_key_exists('ssh_password', $config) || array_key_exists('ssh_passphrase', $config)) {
            // Explicitly reject any attempt to pass password-based auth so it
            // never silently ends up in logs or process lists.
            throw new TunnelException(
                'SSH passwords/passphrases are not accepted by this library. '
                . 'Use key-based auth via "ssh_key_path", ssh-agent, or ~/.ssh/config.'
            );
        }

        return new self(
            localPort: $localPort,
            server: $server,
            sshUser: $sshUser,
            remotePort: $remotePort,
            sshBinaryPath: $sshBinaryPath,
            sshKeyPath: $sshKeyPath,
            environments: $environments,
            currentEnvironment: $currentEnvironment,
            strictHostKeyChecking: $strict,
            connectTimeout: $connectTimeout,
            logger: $logger,
        );
    }

    public function wantsRandomPort(): bool
    {
        return $this->localPort === self::RANDOM_PORT;
    }

    /**
     * True when either no `environments` restriction was configured (allow
     * all — see the README warning about that default) or the current
     * environment is in the allowed list.
     */
    public function isEnvironmentAllowed(): bool
    {
        if ($this->environments === null) {
            return true;
        }

        return in_array($this->currentEnvironment, $this->environments, true);
    }

    /**
     * Stable identity hash for this tunnel target, used to name the lockfile.
     * Includes the local port only when it is fixed: all 'random'-port
     * requests for the same target share one lockfile so a still-live random
     * tunnel can be found and reused (its resolved port lives in the file).
     */
    public function hash(): string
    {
        return hash('sha256', implode('|', [
            $this->server,
            $this->sshUser,
            (string) $this->remotePort,
            $this->wantsRandomPort() ? self::RANDOM_PORT : (string) $this->localPort,
        ]));
    }
}
