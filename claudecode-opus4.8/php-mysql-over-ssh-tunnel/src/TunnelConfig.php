<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

use HumanNotFound\MysqlSshTunnel\Exception\ConfigValidationException;

/**
 * Immutable, validated configuration value object.
 *
 * Build one with {@see TunnelConfig::fromArray()}, which performs ALL config
 * validation up front and throws {@see ConfigValidationException} on any
 * programmer error (bad hostname, out-of-range port, missing required key,
 * `ssh_key_path` pointing at a non-existent file, ...).
 *
 * SECURITY / AUTH NOTE: this library never handles SSH passwords or key
 * passphrases. Any key referenced by `ssh_key_path` MUST be passphrase-less or
 * already unlocked in a running `ssh-agent`, because the tunnel is spawned via
 * `proc_open()` with no controlling terminal and therefore cannot prompt for a
 * passphrase. The key file's contents are never read or logged — only its path
 * is passed to `ssh -i`.
 */
final readonly class TunnelConfig
{
    /**
     * Strict allow-list for hostnames. Rejects any shell metacharacter by only
     * permitting letters, digits, dots and hyphens (RFC-1123-ish). IP literals
     * are accepted via a separate {@see filter_var()} check in fromArray().
     */
    private const HOSTNAME_REGEX = '/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?)*$/';

    /** Strict allow-list for POSIX-ish usernames. No shell metacharacters. */
    private const USERNAME_REGEX = '/^[A-Za-z0-9._-]{1,64}$/';

    /**
     * @param int|string  $localPort           An int 1-65535, or the string 'random'.
     * @param string      $server              Remote host running sshd.
     * @param string      $sshUser             SSH username.
     * @param int         $sshPort             Port sshd listens on (NOT the MySQL port).
     * @param int         $remotePort          MySQL port on the far side of the SSH connection.
     * @param string      $sshBinaryPath       Absolute path to the ssh executable.
     * @param string|null $sshKeyPath          Optional path to a private key (passphrase-less / agent-loaded only).
     * @param string[]|null $environments      Allowed environments, or null to allow all.
     * @param string|null $currentEnvironment  The current environment name (caller-supplied).
     * @param object|null $logger              Optional PSR-3-compatible logger (any object exposing warning()).
     * @param float       $connectTimeout      Seconds to wait for the forward to come up.
     * @param bool        $strictHostKeyChecking Keep ssh's default strict host-key checking (recommended).
     */
    private function __construct(
        public int|string $localPort,
        public string $server,
        public string $sshUser,
        public int $sshPort,
        public int $remotePort,
        public string $sshBinaryPath,
        public ?string $sshKeyPath,
        public ?array $environments,
        public ?string $currentEnvironment,
        public ?object $logger,
        public float $connectTimeout,
        public bool $strictHostKeyChecking,
    ) {
    }

    /**
     * Validate a raw config array and build an immutable config object.
     *
     * @param array<string, mixed> $config
     *
     * @throws ConfigValidationException on any invalid/missing configuration.
     */
    public static function fromArray(array $config): self
    {
        // --- server (required) ---------------------------------------------
        $server = $config['server'] ?? null;
        if (!is_string($server) || $server === '') {
            throw new ConfigValidationException('Config "server" is required and must be a non-empty string.');
        }
        if (
            preg_match(self::HOSTNAME_REGEX, $server) !== 1
            && filter_var($server, FILTER_VALIDATE_IP) === false
        ) {
            throw new ConfigValidationException(sprintf(
                'Config "server" (%s) is not a valid hostname or IP address.',
                $server
            ));
        }

        // --- ssh_user (required) -------------------------------------------
        $sshUser = $config['ssh_user'] ?? null;
        if (!is_string($sshUser) || preg_match(self::USERNAME_REGEX, $sshUser) !== 1) {
            throw new ConfigValidationException(
                'Config "ssh_user" is required and must match ^[A-Za-z0-9._-]{1,64}$ (no shell metacharacters).'
            );
        }

        // --- ssh_port (optional, default 22) -------------------------------
        $sshPort = self::validatePort($config['ssh_port'] ?? 22, 'ssh_port');

        // --- remote_port (optional, default 3306) --------------------------
        $remotePort = self::validatePort($config['remote_port'] ?? 3306, 'remote_port');

        // --- local_port (optional, default 3306, or 'random') --------------
        $localPortRaw = $config['local_port'] ?? 3306;
        if (is_string($localPortRaw) && strtolower($localPortRaw) === 'random') {
            $localPort = 'random';
        } else {
            $localPort = self::validatePort($localPortRaw, 'local_port');
        }

        // --- ssh_binary_path (required key) --------------------------------
        // NOTE (design decision): a MISSING/empty `ssh_binary_path` key is a
        // programmer error and throws here — there is deliberately no baked-in
        // default because the ssh binary's location varies by OS/distro. The
        // separate question of whether the *provided* path actually exists and
        // is executable is an ENVIRONMENTAL concern and is checked later at
        // boot time, where a bad path degrades to a logged warning + direct
        // connection instead of throwing (see TunnelManager::ensure()). The
        // PROMPT is internally inconsistent on this single point (§2 says a
        // non-executable binary should throw, while §7 and the Public API
        // Contract say it must NOT throw and must fall back); we follow §7 /
        // the API Contract — the two authoritative "never throw on
        // environmental failure" statements — and treat only the missing
        // *config key* as a throwable programmer error.
        $sshBinaryPath = $config['ssh_binary_path'] ?? null;
        if (!is_string($sshBinaryPath) || $sshBinaryPath === '') {
            throw new ConfigValidationException(
                'Config "ssh_binary_path" is required and must be a non-empty string '
                . '(e.g. run `command -v ssh` to find it — commonly /usr/bin/ssh).'
            );
        }

        // --- ssh_key_path (optional) ---------------------------------------
        // A path-only value. If provided it MUST exist (config error otherwise).
        // We never read or log its contents; it is passed verbatim to `ssh -i`.
        $sshKeyPath = $config['ssh_key_path'] ?? null;
        if ($sshKeyPath !== null) {
            if (!is_string($sshKeyPath) || $sshKeyPath === '') {
                throw new ConfigValidationException('Config "ssh_key_path" must be a non-empty string path when provided.');
            }
            if (!is_file($sshKeyPath)) {
                throw new ConfigValidationException(sprintf(
                    'Config "ssh_key_path" (%s) is set but no such file exists.',
                    $sshKeyPath
                ));
            }
        }

        // --- environments (optional; null => allow all) --------------------
        $environments = null;
        if (array_key_exists('environments', $config) && $config['environments'] !== null) {
            $environments = $config['environments'];
            if (!is_array($environments)) {
                throw new ConfigValidationException('Config "environments" must be an array of strings or omitted.');
            }
            foreach ($environments as $env) {
                if (!is_string($env) || $env === '') {
                    throw new ConfigValidationException('Config "environments" must contain only non-empty strings.');
                }
            }
            $environments = array_values($environments);
        }

        // --- current_environment (optional) --------------------------------
        $currentEnvironment = $config['current_environment'] ?? null;
        if ($currentEnvironment !== null && !is_string($currentEnvironment)) {
            throw new ConfigValidationException('Config "current_environment" must be a string or omitted.');
        }

        // --- logger (optional; must be PSR-3-ish) --------------------------
        $logger = $config['logger'] ?? null;
        if ($logger !== null) {
            if (!is_object($logger) || !method_exists($logger, 'warning')) {
                throw new ConfigValidationException(
                    'Config "logger" must be a PSR-3-compatible logger object exposing a warning() method, or omitted.'
                );
            }
        }

        // --- connect_timeout (optional, default 10.0) ----------------------
        $connectTimeout = $config['connect_timeout'] ?? 10.0;
        if (!is_int($connectTimeout) && !is_float($connectTimeout)) {
            throw new ConfigValidationException('Config "connect_timeout" must be a number (seconds).');
        }
        $connectTimeout = (float) $connectTimeout;
        if ($connectTimeout <= 0) {
            throw new ConfigValidationException('Config "connect_timeout" must be greater than 0.');
        }

        // --- strict_host_key_checking (optional, default true) -------------
        $strict = $config['strict_host_key_checking'] ?? true;
        if (!is_bool($strict)) {
            throw new ConfigValidationException('Config "strict_host_key_checking" must be a boolean.');
        }

        return new self(
            localPort: $localPort,
            server: $server,
            sshUser: $sshUser,
            sshPort: $sshPort,
            remotePort: $remotePort,
            sshBinaryPath: $sshBinaryPath,
            sshKeyPath: $sshKeyPath,
            environments: $environments,
            currentEnvironment: $currentEnvironment,
            logger: $logger,
            connectTimeout: $connectTimeout,
            strictHostKeyChecking: $strict,
        );
    }

    /**
     * @throws ConfigValidationException
     */
    private static function validatePort(mixed $value, string $key): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1 || $value > 65535) {
            throw new ConfigValidationException(sprintf(
                'Config "%s" must be an integer between 1 and 65535%s.',
                $key,
                $key === 'local_port' ? ' or the string "random"' : ''
            ));
        }

        return $value;
    }

    public function isRandomPort(): bool
    {
        return is_string($this->localPort) && strtolower($this->localPort) === 'random';
    }

    /**
     * Is the current environment permitted to open a tunnel?
     *
     * - `environments === null` (omitted) => all environments allowed.
     * - Otherwise the caller-supplied `current_environment` must be listed.
     *   A set allow-list with an unknown/missing current environment is
     *   treated as NOT allowed (fail safe toward "no tunnel").
     */
    public function isEnvironmentAllowed(): bool
    {
        if ($this->environments === null) {
            return true;
        }

        return $this->currentEnvironment !== null
            && in_array($this->currentEnvironment, $this->environments, true);
    }

    /**
     * A stable identity for this tunnel, used as the lockfile key.
     *
     * Derived from server, ssh_user, ssh_port, remote_port and — only when the
     * local port is fixed — local_port. Random-port tunnels are intentionally
     * excluded from sharing a fixed identity (each gets its own ephemeral port
     * and its own shutdown-managed lockfile).
     */
    public function identityHash(): string
    {
        $parts = [
            $this->server,
            $this->sshUser,
            (string) $this->sshPort,
            (string) $this->remotePort,
        ];
        if (!$this->isRandomPort()) {
            $parts[] = (string) $this->localPort;
        }

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }
}
