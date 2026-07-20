<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Lockfile;

/**
 * Lockfile / PID protocol for tunnel reuse.
 *
 * Location: sys_get_temp_dir() . '/mysql-ssh-tunnel-{configHash}.lock'
 *
 * Format (line-oriented key=value, UTF-8):
 *   pid={int}
 *   port={int}
 *   hash={sha256 config hash}
 *
 * Permissions: 0600 after write.
 *
 * Coordination: flock(LOCK_EX) on the lockfile handle during check-then-start
 * so two PHP processes racing at the same instant do not spawn duplicate ssh
 * children.
 */
final class TunnelLockfile
{
    public function __construct(
        private string $directory,
    ) {
    }

    public static function inSystemTemp(): self
    {
        return new self(sys_get_temp_dir());
    }

    public function pathForHash(string $configHash): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'mysql-ssh-tunnel-'
            . $configHash
            . '.lock';
    }

    /**
     * @return array{pid: int, port: int, hash: string}|null
     */
    public function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $data[trim($k)] = trim($v);
        }

        if (!isset($data['pid'], $data['port'], $data['hash'])) {
            return null;
        }

        if (!ctype_digit($data['pid']) || !ctype_digit($data['port'])) {
            return null;
        }

        return [
            'pid' => (int) $data['pid'],
            'port' => (int) $data['port'],
            'hash' => $data['hash'],
        ];
    }

    /**
     * @param resource $handle Open file handle already flock()'d
     */
    public function write(mixed $handle, int $pid, int $port, string $hash): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        $body = "pid={$pid}\nport={$port}\nhash={$hash}\n";
        fwrite($handle, $body);
        fflush($handle);

        $meta = stream_get_meta_data($handle);
        $uri = $meta['uri'] ?? null;
        if (is_string($uri) && $uri !== '') {
            @chmod($uri, 0600);
        }
    }

    public function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Open lockfile for exclusive coordination. Caller must flock + fclose.
     *
     * @return resource|false
     */
    public function open(string $path): mixed
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return @fopen($path, 'c+');
    }
}
