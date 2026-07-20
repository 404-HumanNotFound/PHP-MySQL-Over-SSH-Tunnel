<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel;

/**
 * Per-config lockfile / PID file living in the system temp dir.
 *
 * PROTOCOL (documented here and in AGENT.md so future changes stay compatible):
 *  - Location: sys_get_temp_dir()/mysql-ssh-tunnel-{identityHash}.lock
 *  - Permissions: 0600 (reveals active tunnel/process info).
 *  - Contents: a single line of JSON: {"pid": int, "port": int, "created_at": int}
 *  - A sibling ...{identityHash}.log file captures the spawned ssh's stdout/stderr.
 *  - The same file handle is flock(LOCK_EX)'d for the whole
 *    check-then-start sequence so two PHP processes booting at the same instant
 *    can't both spawn a tunnel.
 */
final class Lockfile
{
    private string $path;
    private string $logPath;

    public function __construct(string $identityHash, ?string $dir = null)
    {
        $dir ??= sys_get_temp_dir();
        $base = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mysql-ssh-tunnel-' . $identityHash;
        $this->path = $base . '.lock';
        $this->logPath = $base . '.log';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function logPath(): string
    {
        return $this->logPath;
    }

    /**
     * Open the lockfile and take an exclusive advisory lock for the duration of
     * the check-then-start sequence.
     *
     * @return resource
     */
    public function openForLocking()
    {
        // 'c' => create if missing, don't truncate, position at start.
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open tunnel lockfile: ' . $this->path);
        }

        // Restrictive perms: this file reveals active tunnel/process info.
        @chmod($this->path, 0600);

        flock($handle, LOCK_EX);

        return $handle;
    }

    /**
     * Read and decode the lockfile contents.
     *
     * @param resource $handle
     *
     * @return array{pid: int, port: int, created_at: int}|null Null if empty/corrupt.
     */
    public function read($handle): ?array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $data = json_decode(trim($raw), true);
        if (!is_array($data) || !isset($data['pid'], $data['port'])) {
            return null;
        }

        return [
            'pid' => (int) $data['pid'],
            'port' => (int) $data['port'],
            'created_at' => (int) ($data['created_at'] ?? 0),
        ];
    }

    /**
     * Write the current tunnel identity (pid + port) into the lockfile.
     *
     * @param resource $handle
     */
    public function write($handle, int $pid, int $port): void
    {
        $payload = json_encode([
            'pid' => $pid,
            'port' => $port,
            'created_at' => time(),
        ], JSON_THROW_ON_ERROR);

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $payload);
        fflush($handle);
        @chmod($this->path, 0600);
    }

    /**
     * Empty the lockfile contents (used when a stale entry is found), keeping
     * the handle/lock intact so the caller can immediately write a fresh entry.
     *
     * @param resource $handle
     */
    public function clear($handle): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fflush($handle);
    }

    /**
     * Release the advisory lock and close the handle.
     *
     * @param resource $handle
     */
    public function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Delete the lockfile entirely (used by the random-port shutdown function).
     */
    public function delete(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
