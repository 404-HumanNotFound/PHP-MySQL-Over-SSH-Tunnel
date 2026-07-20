<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

use HumanNotFound\MysqlSshTunnel\Exception\ConfigValidationException;
use HumanNotFound\MysqlSshTunnel\Support\Logging;
use HumanNotFound\MysqlSshTunnel\System\NativeSystem;
use HumanNotFound\MysqlSshTunnel\System\SystemInterface;

/**
 * The single public entry point.
 *
 * A framework adapter or a standalone bootstrap script calls
 * {@see TunnelManager::boot()} with the config array. That one call:
 *   1. validates config (throws {@see ConfigValidationException} on bad config),
 *   2. detects/reuses/starts the tunnel — or falls back — without ever throwing
 *      on an environmental failure,
 *   3. defines the global constants
 *      (MYSQL_SSH_TUNNEL_ACTIVE / MYSQL_SSH_TUNNEL_HOST / MYSQL_SSH_TUNNEL_LOCAL_PORT),
 *   4. returns a {@see TunnelResult} carrying the same info directly.
 *
 * THROW-VS-FALLBACK CONTRACT:
 *   - ONLY config validation errors throw (bad hostname, out-of-range port,
 *     ssh_key_path pointing at a missing file, missing required ssh_binary_path key).
 *   - Environmental failures — disallowed environment, non-executable ssh
 *     binary, tunnel timeout / immediate ssh exit — are logged as warnings and
 *     degrade to a direct-connection fallback. They never throw and never halt
 *     the app.
 */
final class TunnelManager
{
    public const CONST_ACTIVE = 'MYSQL_SSH_TUNNEL_ACTIVE';
    public const CONST_HOST = 'MYSQL_SSH_TUNNEL_HOST';
    public const CONST_LOCAL_PORT = 'MYSQL_SSH_TUNNEL_LOCAL_PORT';

    private SystemInterface $system;
    private ?string $lockDir;

    public function __construct(
        private TunnelConfig $config,
        ?SystemInterface $system = null,
        ?string $lockDir = null,
    ) {
        $this->system = $system ?? new NativeSystem();
        $this->lockDir = $lockDir;
    }

    /**
     * Validate config, ensure the tunnel, define constants, return the outcome.
     *
     * @param array<string, mixed> $config
     *
     * @throws ConfigValidationException on invalid configuration only.
     */
    public static function boot(array $config, ?SystemInterface $system = null): TunnelResult
    {
        $manager = new self(TunnelConfig::fromArray($config), $system);

        return $manager->ensure();
    }

    /**
     * Ensure a tunnel is available (detect/reuse/start) or fall back, then
     * define the constants and return the outcome.
     */
    public function ensure(): TunnelResult
    {
        $c = $this->config;

        // (1) Environment restriction — a deliberate safety rail, not an error.
        if (!$c->isEnvironmentAllowed()) {
            $this->warn(sprintf(
                'Tunnel skipped: current environment "%s" is not in the allowed list [%s]. '
                . 'Falling back to a direct connection to %s:%d.',
                $c->currentEnvironment ?? '(none supplied)',
                implode(', ', $c->environments ?? []),
                $c->server,
                $c->remotePort
            ));

            return $this->fallback('environment-not-allowed');
        }

        // (2) Environmental check: is the configured ssh binary usable?
        if (!$this->system->isExecutable($c->sshBinaryPath)) {
            $this->warn(sprintf(
                'Tunnel skipped: ssh binary "%s" does not exist or is not executable. '
                . 'Falling back to a direct connection to %s:%d.',
                $c->sshBinaryPath,
                $c->server,
                $c->remotePort
            ));

            return $this->fallback('ssh-binary-not-executable');
        }

        // (3) Detect / reuse / start, guarded by an exclusive lock.
        $lockfile = new Lockfile($c->identityHash(), $this->lockDir);
        $handle = $lockfile->openForLocking();

        try {
            $existing = $lockfile->read($handle);

            if (
                $existing !== null
                && $this->system->isAlive($existing['pid'])
                && $this->system->isListening('127.0.0.1', $existing['port'], $c->connectTimeout)
            ) {
                // A live, matching tunnel already exists — reuse it.
                return $this->active($existing['port'], reused: true);
            }

            if ($existing !== null) {
                // Stale entry (dead PID or dead port) — clear before restarting.
                $lockfile->clear($handle);
            }

            return $this->start($lockfile, $handle);
        } finally {
            $lockfile->releaseLock($handle);
        }
    }

    /**
     * Start a brand-new ssh tunnel, poll for it to come up, and either record
     * it (success) or fall back (failure). Never throws.
     *
     * @param resource $handle
     */
    private function start(Lockfile $lockfile, $handle): TunnelResult
    {
        $c = $this->config;

        $localPort = $c->isRandomPort()
            ? $this->system->findFreePort()
            : (int) $c->localPort;

        if ($localPort < 1) {
            $this->warn('Could not allocate a local port for the tunnel. Falling back to a direct connection.');

            return $this->fallback('local-port-allocation-failed');
        }

        $argv = $this->buildSshArgv($localPort);
        $pid = $this->system->spawn($argv, $lockfile->logPath());

        // Poll until the forward is accepting connections or we time out /
        // the ssh process dies (ExitOnForwardFailure makes it exit fast).
        $deadline = microtime(true) + $c->connectTimeout;
        $up = false;
        do {
            if (!$this->system->isAlive($pid)) {
                break;
            }
            if ($this->system->isListening('127.0.0.1', $localPort, 0.5)) {
                $up = true;
                break;
            }
            usleep(100_000); // 100ms
        } while (microtime(true) < $deadline);

        if (!$up) {
            $this->system->terminate($pid);
            $lockfile->clear($handle);
            $this->warn(sprintf(
                'Tunnel failed to establish on 127.0.0.1:%d within %.1fs (ssh exited or the forward never came up; '
                . 'see %s). Falling back to a direct connection to %s:%d.',
                $localPort,
                $c->connectTimeout,
                $lockfile->logPath(),
                $c->server,
                $c->remotePort
            ));

            return $this->fallback('tunnel-establish-timeout');
        }

        $lockfile->write($handle, $pid, $localPort);

        // (4) Shutdown behavior: ONLY random-port tunnels are torn down on
        // shutdown. Fixed-port tunnels are intentionally left running so they
        // can be reused across requests.
        if ($c->isRandomPort()) {
            $this->system->registerShutdown(function () use ($pid, $lockfile): void {
                $this->system->terminate($pid);
                $lockfile->delete();
            });
        }

        return $this->active($localPort, reused: false);
    }

    /**
     * Build the raw ssh argument vector. Escaping happens in the SystemInterface
     * implementation (per-argument escapeshellarg) — this method only assembles
     * the list, never a string.
     *
     * @return list<string>
     */
    public function buildSshArgv(int $localPort): array
    {
        $c = $this->config;

        $argv = [
            $c->sshBinaryPath,
            '-p', (string) $c->sshPort,
            '-N', // no remote command — forward only
            '-o', 'BatchMode=yes',            // never prompt (no TTY under proc_open)
            '-o', 'ExitOnForwardFailure=yes', // fail fast if the -L bind fails
            '-o', sprintf('ConnectTimeout=%d', max(1, (int) ceil($c->connectTimeout))),
        ];

        if (!$c->strictHostKeyChecking) {
            // Opt-in insecure mode — documented risk in the README.
            $argv[] = '-o';
            $argv[] = 'StrictHostKeyChecking=no';
            $argv[] = '-o';
            $argv[] = 'UserKnownHostsFile=/dev/null';
        }

        if ($c->sshKeyPath !== null) {
            $argv[] = '-i';
            $argv[] = $c->sshKeyPath;
            $argv[] = '-o';
            $argv[] = 'IdentitiesOnly=yes'; // use only the supplied key
        }

        $argv[] = '-L';
        $argv[] = sprintf('%d:127.0.0.1:%d', $localPort, $c->remotePort);
        $argv[] = sprintf('%s@%s', $c->sshUser, $c->server);

        return $argv;
    }

    private function active(int $port, bool $reused): TunnelResult
    {
        $this->define(true, '127.0.0.1', $port);

        return new TunnelResult(
            active: true,
            host: '127.0.0.1',
            port: $port,
            reused: $reused,
            message: $reused ? 'reused-existing-tunnel' : 'started-new-tunnel',
        );
    }

    private function fallback(string $reason): TunnelResult
    {
        $c = $this->config;
        $this->define(false, $c->server, $c->remotePort);

        return new TunnelResult(
            active: false,
            host: $c->server,
            port: $c->remotePort,
            reused: false,
            message: $reason,
        );
    }

    /**
     * (6) Define the global constants, guarding against double-definition since
     * multiple bootstrap paths in the same request may trigger this.
     */
    private function define(bool $active, string $host, int $port): void
    {
        if (!defined(self::CONST_ACTIVE)) {
            define(self::CONST_ACTIVE, $active);
        }
        if (!defined(self::CONST_HOST)) {
            define(self::CONST_HOST, $host);
        }
        if (!defined(self::CONST_LOCAL_PORT)) {
            define(self::CONST_LOCAL_PORT, $port);
        }
    }

    private function warn(string $message): void
    {
        Logging::warn($this->config->logger, $message);
    }
}
