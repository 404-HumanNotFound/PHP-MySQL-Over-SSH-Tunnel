<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

use HumanNotFound\MysqlSshTunnel\Exception\InvalidConfigException;
use HumanNotFound\MysqlSshTunnel\Lockfile\TunnelLockfile;
use HumanNotFound\MysqlSshTunnel\Process\PidLiveness;
use HumanNotFound\MysqlSshTunnel\Process\ProcessHandleInterface;
use HumanNotFound\MysqlSshTunnel\Process\ProcessRunnerInterface;
use HumanNotFound\MysqlSshTunnel\Process\ProcOpenRunner;

/**
 * Bootstrap entry point: validate config, ensure an SSH MySQL tunnel, define
 * global constants, and return a TunnelResult.
 *
 * Auth: key-based only via ssh_key_path, ssh-agent, or ~/.ssh/config.
 * Passwords and interactive passphrases are never accepted. Any key at
 * ssh_key_path must be passphrase-less or already unlocked in ssh-agent —
 * proc_open() has no TTY to prompt through.
 */
final class TunnelManager
{
    private static ?ProcessRunnerInterface $runner = null;

    private static ?TunnelLockfile $lockfiles = null;

    /** @var list<ProcessHandleInterface> */
    private static array $ownedHandles = [];

    /** @var int How many random-port shutdown callbacks have been registered (test seam). */
    private static int $shutdownRegistrations = 0;

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigException On programmer/config errors only
     */
    public static function boot(array $config): TunnelResult
    {
        $tunnelConfig = TunnelConfig::fromArray($config);

        return self::ensure($tunnelConfig);
    }

    /**
     * Test seam: inject a fake ProcessRunner (defaults to ProcOpenRunner).
     */
    public static function setProcessRunner(?ProcessRunnerInterface $runner): void
    {
        self::$runner = $runner;
    }

    /**
     * Test seam: inject lockfile storage directory / helper.
     */
    public static function setLockfileStore(?TunnelLockfile $lockfiles): void
    {
        self::$lockfiles = $lockfiles;
    }

    /**
     * Test seam: number of register_shutdown_function() calls for random ports.
     */
    public static function shutdownRegistrationCount(): int
    {
        return self::$shutdownRegistrations;
    }

    /**
     * Reset static test seams and owned handles (for PHPUnit isolation).
     */
    public static function reset(): void
    {
        self::$runner = null;
        self::$lockfiles = null;
        self::$ownedHandles = [];
        self::$shutdownRegistrations = 0;
    }

    /**
     * @throws InvalidConfigException
     */
    private static function ensure(TunnelConfig $config): TunnelResult
    {
        if (!$config->environmentAllowed()) {
            $config->logger->warning(
                'SSH MySQL tunnel skipped: current environment is not in the allowed environments list. Falling back to a direct connection.',
                [
                    'current_environment' => $config->currentEnvironment,
                    'environments' => $config->environments,
                ]
            );

            return self::fallback($config);
        }

        if (!is_file($config->sshBinaryPath) || !is_executable($config->sshBinaryPath)) {
            // Environmental: binary path missing/non-executable → warn + fallback
            // (distinct from InvalidConfigException for malformed config values).
            $config->logger->warning(
                'SSH MySQL tunnel could not be established: ssh_binary_path does not exist or is not executable. Falling back to a direct connection.',
                ['ssh_binary_path' => $config->sshBinaryPath]
            );

            return self::fallback($config);
        }

        $lockfiles = self::$lockfiles ?? TunnelLockfile::inSystemTemp();
        $hash = $config->configHash();
        $lockPath = $lockfiles->pathForHash($hash);

        $handle = $lockfiles->open($lockPath);
        if ($handle === false) {
            $config->logger->warning(
                'SSH MySQL tunnel could not be established: unable to open lockfile. Falling back to a direct connection.',
                ['lockfile' => $lockPath]
            );

            return self::fallback($config);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                $config->logger->warning(
                    'SSH MySQL tunnel could not be established: unable to lock lockfile. Falling back to a direct connection.',
                    ['lockfile' => $lockPath]
                );

                return self::fallback($config);
            }

            $existing = $lockfiles->read($lockPath);
            if ($existing !== null && $existing['hash'] === $hash) {
                $alive = PidLiveness::isAlive($existing['pid']);
                $portOpen = self::portAcceptsConnections($existing['port'], min(1.0, $config->connectTimeout));

                // Reuse when the port is accepting connections. PID liveness
                // false means stale; null (unknown without posix) defers to
                // the port check alone.
                if ($portOpen && $alive !== false) {
                    return self::success($existing['port']);
                }

                // Stale lockfile — truncate in place (do not unlink while the
                // flock'd handle is open; that orphans the inode on Unix).
                ftruncate($handle, 0);
                rewind($handle);
            }

            $localPort = $config->isRandomPort
                ? self::allocateEphemeralPort()
                : (int) $config->localPort;

            if ($localPort === null) {
                $config->logger->warning(
                    'SSH MySQL tunnel could not be established: failed to allocate an ephemeral local port. Falling back to a direct connection.'
                );

                return self::fallback($config);
            }

            try {
                $process = self::startSsh($config, $localPort);
            } catch (\Throwable $e) {
                $config->logger->warning(
                    'SSH MySQL tunnel could not be established: failed to start ssh process. Falling back to a direct connection.',
                    ['error' => $e->getMessage()]
                );

                return self::fallback($config);
            }

            if (!self::waitForPort($localPort, $config->connectTimeout, $process)) {
                $process->terminate();
                $config->logger->warning(
                    'SSH MySQL tunnel could not be established: ssh process exited or forward did not become ready in time. Falling back to a direct connection.',
                    ['local_port' => $localPort]
                );

                return self::fallback($config);
            }

            $pid = $process->getPid() ?? 0;
            $lockfiles->write($handle, $pid, $localPort, $hash);
            @chmod($lockPath, 0600);

            if ($config->isRandomPort) {
                self::$ownedHandles[] = $process;
                self::registerRandomPortShutdown($process, $lockfiles, $lockPath);
            }

            $result = self::success($localPort);
            flock($handle, LOCK_UN);

            return $result;
        } finally {
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                @fclose($handle);
            }
        }
    }

    private static function startSsh(TunnelConfig $config, int $localPort): ProcessHandleInterface
    {
        /*
         * Argument vector (never one interpolated shell string):
         *   {ssh_binary} -p {ssh_port} -N
         *     -L {local_port}:127.0.0.1:{remote_port}
         *     -o BatchMode=yes -o ExitOnForwardFailure=yes
         *     [-o StrictHostKeyChecking=no]  // only if explicitly opted out
         *     [-i {ssh_key_path}]
         *     {ssh_user}@{server}
         */
        $args = [
            $config->sshBinaryPath,
            '-p',
            (string) $config->sshPort,
            '-N',
            '-L',
            sprintf('%d:127.0.0.1:%d', $localPort, $config->remotePort),
            '-o',
            'BatchMode=yes',
            '-o',
            'ExitOnForwardFailure=yes',
        ];

        if (!$config->strictHostKeyChecking) {
            // Opt-in only — disables MITM protection. Documented in README.
            $args[] = '-o';
            $args[] = 'StrictHostKeyChecking=no';
            $args[] = '-o';
            $args[] = 'UserKnownHostsFile=/dev/null';
        }

        if ($config->sshKeyPath !== null) {
            $args[] = '-i';
            $args[] = $config->sshKeyPath;
        }

        $args[] = $config->sshUser . '@' . $config->server;

        $runner = self::$runner ?? new ProcOpenRunner();

        return $runner->start($args);
    }

    /**
     * Bind TCP port 0, read the OS-assigned ephemeral port, then release it
     * before handing the number to ssh.
     *
     * Race window: between fclose() here and ssh binding the same port,
     * another process could claim it. Documented tradeoff — we accept the
     * small race rather than holding the socket open (which would block ssh).
     */
    private static function allocateEphemeralPort(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            return null;
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false || !str_contains($name, ':')) {
            return null;
        }

        $port = (int) substr($name, strrpos($name, ':') + 1);

        return $port > 0 ? $port : null;
    }

    private static function waitForPort(int $port, float $timeout, ProcessHandleInterface $process): bool
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if (!$process->isRunning()) {
                return false;
            }
            if (self::portAcceptsConnections($port, 0.2)) {
                return true;
            }
            usleep(50_000);
        }

        return self::portAcceptsConnections($port, 0.2);
    }

    private static function portAcceptsConnections(int $port, float $timeout): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, $timeout);
        if ($fp === false) {
            return false;
        }
        fclose($fp);

        return true;
    }

    private static function registerRandomPortShutdown(
        ProcessHandleInterface $process,
        TunnelLockfile $lockfiles,
        string $lockPath,
    ): void {
        self::$shutdownRegistrations++;
        register_shutdown_function(static function () use ($process, $lockfiles, $lockPath): void {
            $process->terminate();
            $lockfiles->remove($lockPath);
        });
    }

    private static function success(int $localPort): TunnelResult
    {
        self::defineConstants(true, $localPort);

        return new TunnelResult(true, $localPort, '127.0.0.1');
    }

    private static function fallback(TunnelConfig $config): TunnelResult
    {
        self::defineConstants(false, $config->remotePort);

        return new TunnelResult(false, $config->remotePort, $config->server);
    }

    private static function defineConstants(bool $active, int $port): void
    {
        if (!defined('MYSQL_SSH_TUNNEL_LOCAL_PORT')) {
            define('MYSQL_SSH_TUNNEL_LOCAL_PORT', $port);
        }
        if (!defined('MYSQL_SSH_TUNNEL_ACTIVE')) {
            define('MYSQL_SSH_TUNNEL_ACTIVE', $active);
        }
    }
}
