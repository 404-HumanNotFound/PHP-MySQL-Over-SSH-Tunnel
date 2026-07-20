<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

use Psr\Log\LoggerInterface;

/**
 * Core tunnel lifecycle: detect an already-running tunnel for this config,
 * reuse it, or start a new `ssh -N -L` local port forward — and expose the
 * resolved port via the MYSQL_SSH_TUNNEL_LOCAL_PORT / MYSQL_SSH_TUNNEL_ACTIVE
 * constants.
 *
 * Contract (see also TunnelException):
 *  - Config validation errors throw (from TunnelConfig::fromArray()).
 *  - Environmental failures (binary missing on this machine, ssh dies, the
 *    forward never comes up, environment not in the allowed list) NEVER
 *    throw: they log a warning and fall back to direct-connection constants.
 *
 * Lockfile / PID protocol
 * -----------------------
 * One lockfile per tunnel identity, at:
 *     sys_get_temp_dir() . '/php-mysql-ssh-tunnel-' . <config hash> . '.lock'
 * where <config hash> = sha256("server|ssh_user|remote_port|local_port"),
 * with the literal string "random" in place of the local port when
 * local_port is 'random' (see TunnelConfig::hash()).
 *
 * The file contains one JSON object: {"pid": int, "port": int, "created_at": int}
 * and is chmod 0600 (it reveals live process/port info). A tunnel is
 * considered live and reusable when the PID is alive (or liveness is
 * unknowable on this system) AND 127.0.0.1:port accepts a TCP connection.
 * The whole check-then-start sequence runs under flock(LOCK_EX) on the
 * lockfile so two PHP processes booting at the same instant cannot both
 * spawn a tunnel for the same config.
 */
final class TunnelManager
{
    public const CONST_LOCAL_PORT = 'MYSQL_SSH_TUNNEL_LOCAL_PORT';
    public const CONST_ACTIVE = 'MYSQL_SSH_TUNNEL_ACTIVE';

    private const LOCKFILE_PREFIX = 'php-mysql-ssh-tunnel-';

    /** Seconds allowed for fsockopen() when probing the local port. */
    private const PORT_PROBE_TIMEOUT = 1.0;

    private readonly LoggerInterface $logger;

    private readonly ProcessRunnerInterface $processRunner;

    /** @var callable(callable): void */
    private $shutdownRegistrar;

    /**
     * @param callable(callable): void|null $shutdownRegistrar test seam;
     *        defaults to register_shutdown_function()
     */
    public function __construct(
        private readonly TunnelConfig $config,
        ?ProcessRunnerInterface $processRunner = null,
        ?callable $shutdownRegistrar = null,
    ) {
        $this->logger = $config->logger ?? new ErrorLogLogger();
        $this->processRunner = $processRunner ?? new ProcOpenProcessRunner();
        $this->shutdownRegistrar = $shutdownRegistrar ?? 'register_shutdown_function';
    }

    /**
     * One-call bootstrap: validate config, ensure the tunnel, define the
     * constants. This is what framework adapters and standalone scripts use.
     *
     * @param array<string, mixed> $config see TunnelConfig::fromArray()
     *
     * @throws TunnelException only for config validation errors
     */
    public static function boot(array $config): TunnelResult
    {
        $manager = new self(TunnelConfig::fromArray($config));
        $result = $manager->ensure();
        $manager->defineConstants($result);

        return $result;
    }

    /**
     * Ensure a tunnel is available for this config: reuse a live one, or
     * start a new one. Never throws — environmental failures return a
     * fallback (direct-connection) result. Does not define constants; call
     * defineConstants() or use boot() for that.
     */
    public function ensure(): TunnelResult
    {
        if (!$this->config->isEnvironmentAllowed()) {
            // Deliberate safety rail, distinct from environmental failure:
            // makes it hard to accidentally leave the tunnel wired into a
            // production deployment.
            $this->logger->warning(
                'SSH tunnel skipped: current environment "{env}" is not in the allowed environments list [{allowed}]. '
                . 'Falling back to a direct connection to {server}:{port}. '
                . 'This is a deliberate safety rail — add the environment to the "environments" config key if this is intentional.',
                [
                    'env' => $this->config->currentEnvironment ?? '(unset)',
                    'allowed' => implode(', ', $this->config->environments ?? []),
                    'server' => $this->config->server,
                    'port' => $this->config->remotePort,
                ]
            );

            return $this->fallbackResult();
        }

        if (!is_file($this->config->sshBinaryPath) || !is_executable($this->config->sshBinaryPath)) {
            // Environmental, not config: the same committed config can be
            // valid on one machine and not another. Warn and fall back.
            $this->logger->warning(
                'SSH tunnel could not be established: ssh binary "{binary}" does not exist or is not executable on this machine. '
                . 'Falling back to a direct connection to {server}:{port}. '
                . 'Check the "ssh_binary_path" config value (try `command -v ssh`).',
                [
                    'binary' => $this->config->sshBinaryPath,
                    'server' => $this->config->server,
                    'port' => $this->config->remotePort,
                ]
            );

            return $this->fallbackResult();
        }

        $lockPath = $this->lockfilePath();

        $handle = @fopen($lockPath, 'c+');
        if ($handle === false) {
            $this->logger->warning(
                'SSH tunnel could not be established: unable to open lockfile "{path}". '
                . 'Falling back to a direct connection to {server}:{port}.',
                [
                    'path' => $lockPath,
                    'server' => $this->config->server,
                    'port' => $this->config->remotePort,
                ]
            );

            return $this->fallbackResult();
        }

        // Restrictive perms: the file reveals a live PID + open local port.
        @chmod($lockPath, 0o600);

        // Serialise the whole check-then-start sequence so two PHP processes
        // booting at the same instant cannot both spawn a tunnel.
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            $this->logger->warning(
                'SSH tunnel could not be established: unable to acquire lock on "{path}". '
                . 'Falling back to a direct connection to {server}:{port}.',
                [
                    'path' => $lockPath,
                    'server' => $this->config->server,
                    'port' => $this->config->remotePort,
                ]
            );

            return $this->fallbackResult();
        }

        try {
            $existing = $this->readLockfile($handle);
            if ($existing !== null && $this->isTunnelLive($existing['pid'], $existing['port'])) {
                return new TunnelResult(active: true, localPort: $existing['port'], host: '127.0.0.1');
            }

            return $this->startTunnel($handle, $lockPath);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Expose the result as global constants. defined() guards allow multiple
     * bootstrap paths (e.g. a framework hook plus a stray require) to call
     * this in the same request without a fatal error.
     */
    public function defineConstants(TunnelResult $result): void
    {
        if (!defined(self::CONST_LOCAL_PORT)) {
            define(self::CONST_LOCAL_PORT, $result->localPort);
        }
        if (!defined(self::CONST_ACTIVE)) {
            define(self::CONST_ACTIVE, $result->active);
        }
    }

    public function lockfilePath(): string
    {
        return sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . self::LOCKFILE_PREFIX
            . $this->config->hash()
            . '.lock';
    }

    // ------------------------------------------------------------------
    //  Internals
    // ------------------------------------------------------------------

    /**
     * Direct-connection fallback: the LOCAL_PORT constant carries the
     * *remote* port and ACTIVE is false, so consuming code must branch on
     * MYSQL_SSH_TUNNEL_ACTIVE to pick the right host (see README).
     */
    private function fallbackResult(): TunnelResult
    {
        return new TunnelResult(
            active: false,
            localPort: $this->config->remotePort,
            host: $this->config->server,
        );
    }

    /**
     * @param resource $handle open, locked lockfile handle
     *
     * @return array{pid: int, port: int}|null
     */
    private function readLockfile($handle): ?array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $data = json_decode(trim($raw), true);
        if (!is_array($data) || !isset($data['pid'], $data['port'])
            || !is_int($data['pid']) || !is_int($data['port'])
        ) {
            return null; // corrupt/foreign content: treat as absent
        }

        return ['pid' => $data['pid'], 'port' => $data['port']];
    }

    /**
     * A recorded tunnel is live when its PID is alive (or liveness cannot be
     * determined on this system) AND the local port accepts connections.
     */
    private function isTunnelLive(int $pid, int $port): bool
    {
        if ($this->isPidAlive($pid) === false) {
            return false;
        }

        return $this->isPortAcceptingConnections($port, self::PORT_PROBE_TIMEOUT);
    }

    /**
     * PID liveness with documented fallbacks:
     *  1. posix_kill($pid, 0) when ext-posix is available. EPERM (errno 1)
     *     still means "a process with this PID exists".
     *  2. /proc/{pid} on Linux systems without ext-posix.
     *  3. Otherwise: null = unknown. The caller then relies on the port
     *     probe alone — a stale lockfile whose port no longer answers is
     *     still detected and replaced.
     */
    private function isPidAlive(int $pid): ?bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            if (@posix_kill($pid, 0)) {
                return true;
            }

            return function_exists('posix_get_last_error') && posix_get_last_error() === 1; // EPERM => exists
        }

        if (PHP_OS_FAMILY === 'Linux' && is_dir('/proc')) {
            return is_dir('/proc/' . $pid);
        }

        return null;
    }

    private function isPortAcceptingConnections(int $port, float $timeout): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /**
     * @param resource $handle open, locked lockfile handle
     */
    private function startTunnel($handle, string $lockPath): TunnelResult
    {
        $localPort = $this->config->wantsRandomPort()
            ? $this->allocateEphemeralPort()
            : $this->config->localPort;

        if (!is_int($localPort)) {
            $this->logger->warning(
                'SSH tunnel could not be established: failed to allocate an ephemeral local port. '
                . 'Falling back to a direct connection to {server}:{port}.',
                ['server' => $this->config->server, 'port' => $this->config->remotePort]
            );

            return $this->fallbackResult();
        }

        $process = $this->processRunner->start($this->buildCommand($localPort));
        if ($process === null) {
            $this->logger->warning(
                'SSH tunnel could not be established: failed to start "{binary}". '
                . 'Falling back to a direct connection to {server}:{port}.',
                [
                    'binary' => $this->config->sshBinaryPath,
                    'server' => $this->config->server,
                    'port' => $this->config->remotePort,
                ]
            );

            return $this->fallbackResult();
        }

        if (!$this->waitForForward($process, $localPort)) {
            $stderr = trim($process->getErrorOutput());
            $process->terminate();
            $this->logger->warning(
                'SSH tunnel to {user}@{server} could not be established within {timeout}s '
                . '(process exited early or the forward never came up). '
                . 'Falling back to a direct connection to {server}:{port}.{stderr}',
                [
                    'user' => $this->config->sshUser,
                    'server' => $this->config->server,
                    'timeout' => $this->config->connectTimeout,
                    'port' => $this->config->remotePort,
                    'stderr' => $stderr === '' ? '' : ' ssh stderr: ' . $stderr,
                ]
            );

            return $this->fallbackResult();
        }

        $this->writeLockfile($handle, $process->getPid(), $localPort);

        if ($this->config->wantsRandomPort()) {
            // Random-port tunnels are request-scoped: nothing else can know
            // the port after this process exits, so tear the tunnel down at
            // shutdown. Fixed-port tunnels are intentionally left running to
            // be reused across requests.
            ($this->shutdownRegistrar)(static function () use ($process, $lockPath): void {
                $process->terminate();
                @unlink($lockPath);
            });
        }

        return new TunnelResult(active: true, localPort: $localPort, host: '127.0.0.1');
    }

    /**
     * Every argument is escaped individually with escapeshellarg() — the
     * command is never built by interpolating raw config into one string.
     */
    private function buildCommand(int $localPort): string
    {
        $args = [
            $this->config->sshBinaryPath,
            '-N',
            '-o', 'BatchMode=yes',
            '-o', 'ExitOnForwardFailure=yes',
        ];

        if (!$this->config->strictHostKeyChecking) {
            // Explicit opt-in only. Disabling host key checking exposes the
            // tunnel to man-in-the-middle attacks — see README.
            $args[] = '-o';
            $args[] = 'StrictHostKeyChecking=no';
        }

        if ($this->config->sshKeyPath !== null) {
            $args[] = '-i';
            $args[] = $this->config->sshKeyPath;
        }

        $args[] = '-L';
        $args[] = sprintf('%d:127.0.0.1:%d', $localPort, $this->config->remotePort);
        $args[] = sprintf('%s@%s', $this->config->sshUser, $this->config->server);

        return implode(' ', array_map('escapeshellarg', $args));
    }

    /**
     * Bind 127.0.0.1:0, read back the OS-assigned ephemeral port, then
     * release it immediately so the ssh process can bind it.
     *
     * Known tradeoff: between the fclose() here and ssh binding the port
     * there is a small race window in which another process could grab the
     * port. In that case ssh exits (ExitOnForwardFailure) and the library
     * falls back to a direct connection with a warning — it does not retry.
     * Documented in the README under "The 'random' port race window".
     */
    private function allocateEphemeralPort(): ?int
    {
        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            return null;
        }

        $name = stream_socket_get_name($server, false);
        fclose($server);

        if (!is_string($name) || !str_contains($name, ':')) {
            return null;
        }

        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        return ($port >= 1 && $port <= 65535) ? $port : null;
    }

    /**
     * Poll until the forward accepts connections, the process dies, or the
     * configured connect_timeout elapses.
     */
    private function waitForForward(ProcessHandleInterface $process, int $localPort): bool
    {
        $deadline = microtime(true) + $this->config->connectTimeout;

        while (microtime(true) < $deadline) {
            if (!$process->isRunning()) {
                return false;
            }

            if ($this->isPortAcceptingConnections($localPort, 0.2)) {
                return true;
            }

            usleep(100_000); // 100 ms between probes
        }

        return false;
    }

    /**
     * @param resource $handle open, locked lockfile handle
     */
    private function writeLockfile($handle, int $pid, int $port): void
    {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode([
            'pid' => $pid,
            'port' => $port,
            'created_at' => time(),
        ], JSON_THROW_ON_ERROR));
        fflush($handle);
    }
}
