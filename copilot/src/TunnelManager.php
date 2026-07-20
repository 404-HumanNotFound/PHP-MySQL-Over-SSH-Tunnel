<?php

namespace PhpMySqlOverSshTunnel;

use PhpMySqlOverSshTunnel\Exception\ConfigValidationException;
use PhpMySqlOverSshTunnel\Process\ProcessRunnerInterface;
use PhpMySqlOverSshTunnel\Process\NativeProcessRunner;
use Psr\Log\LoggerInterface;

final class TunnelManager
{
    public static function boot(array $config): Result
    {
        $cfg = new Config($config);

        $logger = $cfg->logger;

        // Environment restriction
        if (!empty($cfg->environments) && $cfg->current_environment !== null && !in_array($cfg->current_environment, $cfg->environments, true)) {
            self::logWarning($logger, sprintf('Current environment "%s" not in allowed environments list; skipping tunnel and falling back to direct connection.', $cfg->current_environment));
            $port = $cfg->remote_port;
            self::defineConstants(false, $port);
            return new Result(false, $port, $cfg->server);
        }

        // compute hash of stable config (only for persistent tunnels)
        $persistentHashData = [
            'server' => $cfg->server,
            'ssh_user' => $cfg->ssh_user,
            'ssh_port' => $cfg->ssh_port,
            'remote_port' => $cfg->remote_port,
        ];
        $hash = hash('sha256', json_encode($persistentHashData));
        $lockFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php-mysql-ssh-tunnel-' . $hash . '.lock';

        // If temporary requested, skip reuse logic and always start a fresh ephemeral tunnel
        if ($cfg->temporary) {
            // try allocate ephemeral port later
        } else {
            // detect existing tunnel via lockfile
            $lockFd = @fopen($lockFile, 'c+');
            if ($lockFd !== false) {
                // acquire exclusive lock while we check/start
                if (flock($lockFd, LOCK_EX)) {
                    // read existing content
                    clearstatcache();
                    $content = stream_get_contents($lockFd);
                    $existing = null;
                    if ($content !== false && strlen(trim($content)) > 0) {
                        $existing = json_decode($content, true);
                    }

                    // if existing, validate liveness
                    if (is_array($existing) && isset($existing['pid']) && isset($existing['port'])) {
                        $pid = (int)$existing['pid'];
                        $port = (int)$existing['port'];
                        if (self::isPidAlive($pid, $logger) && self::isPortOpen('127.0.0.1', $port, $cfg->connect_timeout)) {
                            // reuse
                            flock($lockFd, LOCK_UN);
                            fclose($lockFd);
                            self::defineConstants(true, $port);
                            return new Result(true, $port, '127.0.0.1');
                        }
                    }

                    // release lock and close
                    flock($lockFd, LOCK_UN);
                    fclose($lockFd);
                } else {
                    // cannot lock; fallback to checking port directly
                    fclose($lockFd);
                }
            }

            // If no lockfile or not reusable, if a local port was requested and is already accepting
            // connections, assume an existing tunnel is present and reuse it.
            if ($cfg->local_port !== 'random' && is_int($cfg->local_port) && self::isPortOpen('127.0.0.1', $cfg->local_port, 1)) {
                $port = (int)$cfg->local_port;
                self::logWarning($logger, sprintf('Local port %d already accepts connections; assuming existing tunnel and reusing it.', $port));
                self::defineConstants(true, $port);
                return new Result(true, $port, '127.0.0.1');
            }
        }


        // need to start a new tunnel
        // choose local port
        if ($cfg->temporary || $cfg->local_port === 'random') {
            $localPort = self::allocateEphemeralPort($logger);
            if ($localPort === null) {
                self::logWarning($logger, 'Could not allocate ephemeral local port; falling back');
                // If we had a lockfd open earlier, release it safely
                if (isset($lockFd) && is_resource($lockFd)) {
                    flock($lockFd, LOCK_UN);
                    fclose($lockFd);
                }
                self::defineConstants(false, $cfg->remote_port);
                return new Result(false, $cfg->remote_port, $cfg->server);
            }
            // register shutdown to remove tunnel later
            $isRandom = true;
        } else {
            $localPort = (int)$cfg->local_port;
            $isRandom = false;
        }

        // build ssh argv
        $argv = [];
        $argv[] = $cfg->ssh_binary_path;
        $argv[] = '-p';
        $argv[] = (string)$cfg->ssh_port;
        $argv[] = '-N';
        $argv[] = '-L';
        $argv[] = sprintf('%d:127.0.0.1:%d', $localPort, $cfg->remote_port);
        $argv[] = '-o';
        $argv[] = 'BatchMode=yes';
        $argv[] = '-o';
        $argv[] = 'ExitOnForwardFailure=yes';
        if (!$cfg->strict_host_key_checking) {
            // explicitly allow opt-out
            $argv[] = '-o';
            $argv[] = 'StrictHostKeyChecking=no';
        }
        if ($cfg->ssh_key_path !== null) {
            $argv[] = '-i';
            $argv[] = $cfg->ssh_key_path;
        }
        $argv[] = sprintf('%s@%s', $cfg->ssh_user, $cfg->server);

        // escape every arg for logging only; proc_open expects array form
        $processRunner = $cfg->process_runner ?? null;
        if ($processRunner === null) {
            $runner = new NativeProcessRunner();
            $processRunner = fn(array $argv) => $runner->start($argv);
        }

        $handle = null;
        try {
            $handle = $processRunner($argv);
        } catch (\Throwable $e) {
            // spawning failed
            self::logWarning($logger, 'Failed to start ssh process: ' . $e->getMessage());
        }

        // check whether the process came up and port is open
        $attempts = 0;
        $maxAttempts = max(1, (int)$cfg->connect_timeout);
        $succeeded = false;
        while ($attempts < $maxAttempts) {
            $attempts++;
            // if we have a runner object implementing ProcessRunnerInterface, use it
            $running = false;
            if ($handle !== null) {
                if (is_array($handle) && isset($handle['proc'])) {
                    $status = proc_get_status($handle['proc']);
                    $running = $status['running'] ?? false;
                } elseif (is_object($handle) || is_array($handle)) {
                    // cannot be sure; optimistically assume running
                    $running = true;
                }
            }
            if ($running && self::isPortOpen('127.0.0.1', $localPort, 1)) {
                $succeeded = true;
                break;
            }
            // small sleep
            usleep(250000);
        }

        if (!$succeeded) {
            // cleanup if we started something
            if (is_array($handle) && isset($handle['proc'])) {
                @proc_terminate($handle['proc']);
                @proc_close($handle['proc']);
            }
            self::logWarning($logger, 'SSH tunnel could not be established within timeout; falling back to direct connection');
            flock($lockFd, LOCK_UN);
            fclose($lockFd);
            self::defineConstants(false, $cfg->remote_port);
            return new Result(false, $cfg->remote_port, $cfg->server);
        }

        // write lockfile with PID and port
        $pid = null;
        if (is_array($handle) && isset($handle['proc'])) {
            $st = proc_get_status($handle['proc']);
            $pid = $st['pid'] ?? null;
        }
        $data = ['pid' => $pid, 'port' => $localPort];

        // Ensure we have a lock file path - for temporary tunnels, create a unique lockfile
        if ($cfg->temporary) {
            $lockFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php-mysql-ssh-tunnel-' . $hash . '-' . bin2hex(random_bytes(6)) . '.lock';
        }

        // attempt to write lockfile if possible
        $wfd = @fopen($lockFile, 'c+');
        if (is_resource($wfd)) {
            ftruncate($wfd, 0);
            rewind($wfd);
            fwrite($wfd, json_encode($data));
            fflush($wfd);
            @chmod($lockFile, 0600);
            fclose($wfd);
        }

        // register shutdown for random/temporary local_port
        if ($isRandom) {
            register_shutdown_function(function () use ($lockFile, $handle, $logger) {
                if ($handle !== null) {
                    if (is_array($handle) && isset($handle['proc'])) {
                        @proc_terminate($handle['proc']);
                        @proc_close($handle['proc']);
                    }
                }
                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
            });
        }

        self::defineConstants(true, $localPort);
        return new Result(true, $localPort, '127.0.0.1');
    }

    private static function defineConstants(bool $active, int $port): void
    {
        if (!defined('MYSQL_SSH_TUNNEL_ACTIVE')) {
            define('MYSQL_SSH_TUNNEL_ACTIVE', $active);
        }
        if (!defined('MYSQL_SSH_TUNNEL_LOCAL_PORT')) {
            define('MYSQL_SSH_TUNNEL_LOCAL_PORT', $port);
        }
    }

    private static function logWarning(?LoggerInterface $logger, string $message): void
    {
        if ($logger instanceof LoggerInterface) {
            $logger->warning($message);
            return;
        }
        error_log('[php-mysql-over-ssh-tunnel] ' . $message);
    }

    private static function isPidAlive(int $pid, ?LoggerInterface $logger): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            try {
                return posix_kill($pid, 0);
            } catch (\Throwable $e) {
                return false;
            }
        }
        // fallback: check /proc on linux
        if (PHP_OS_FAMILY === 'Linux') {
            return is_dir('/proc/' . $pid);
        }
        // unknown: assume not alive to be safe
        return false;
    }

    private static function isPortOpen(string $host, int $port, int $timeout = 2): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp === false) {
            return false;
        }
        fclose($fp);
        return true;
    }

    private static function allocateEphemeralPort(?LoggerInterface $logger): ?int
    {
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            self::logWarning($logger, 'Unable to bind ephemeral port: ' . $errstr);
            return null;
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        if ($name === false) {
            return null;
        }
        // name is "127.0.0.1:xxxxx"
        $parts = explode(':', $name);
        $port = (int)array_pop($parts);
        return $port > 0 ? $port : null;
    }
}
