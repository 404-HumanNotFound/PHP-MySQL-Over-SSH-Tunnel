<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests\Unit;

use HumanNotFound\MysqlSshTunnel\Tests\Support\FakeSystem;
use HumanNotFound\MysqlSshTunnel\Tests\Support\RecordingLogger;
use HumanNotFound\MysqlSshTunnel\TunnelConfig;
use HumanNotFound\MysqlSshTunnel\TunnelManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The MYSQL_SSH_TUNNEL_* constants are process-global and can only be defined
 * once, so each scenario runs in its own isolated process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ConstantsTest extends TestCase
{
    private function lockDir(): string
    {
        $dir = sys_get_temp_dir().'/tunnel-const-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        return $dir;
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'local_port' => 3306,
            'server' => 'db.internal.example',
            'ssh_user' => 'tunneluser',
            'ssh_port' => 2222,
            'remote_port' => 3306,
            'ssh_binary_path' => PHP_BINARY,
            'connect_timeout' => 2.0,
            // Route warnings away from error_log(): in an isolated process any
            // stray stderr output is treated as unexpected test output.
            'logger' => new RecordingLogger(),
        ], $overrides);
    }

    public function testActiveTunnelDefinesLocalConstants(): void
    {
        $system = new FakeSystem();
        $system->freePort = 45678;

        (new TunnelManager(
            TunnelConfig::fromArray($this->config(['local_port' => 'random'])),
            $system,
            $this->lockDir(),
        ))->ensure();

        self::assertTrue(defined('MYSQL_SSH_TUNNEL_ACTIVE'));
        self::assertTrue(MYSQL_SSH_TUNNEL_ACTIVE);
        self::assertSame('127.0.0.1', MYSQL_SSH_TUNNEL_HOST);
        self::assertSame(45678, MYSQL_SSH_TUNNEL_LOCAL_PORT);
    }

    public function testFallbackDefinesDirectConnectionConstants(): void
    {
        $system = new FakeSystem();
        $system->executable = false; // force fallback

        (new TunnelManager(
            TunnelConfig::fromArray($this->config()),
            $system,
            $this->lockDir(),
        ))->ensure();

        self::assertFalse(MYSQL_SSH_TUNNEL_ACTIVE);
        self::assertSame('db.internal.example', MYSQL_SSH_TUNNEL_HOST);
        self::assertSame(3306, MYSQL_SSH_TUNNEL_LOCAL_PORT, 'Fallback exposes the remote port');
    }

    public function testConstantsAreGuardedAgainstDoubleDefinition(): void
    {
        // Pre-define with sentinel values; the manager must not redefine them.
        define('MYSQL_SSH_TUNNEL_ACTIVE', true);
        define('MYSQL_SSH_TUNNEL_HOST', '127.0.0.1');
        define('MYSQL_SSH_TUNNEL_LOCAL_PORT', 13306);

        $system = new FakeSystem();
        $system->executable = false; // would otherwise define fallback values

        (new TunnelManager(
            TunnelConfig::fromArray($this->config()),
            $system,
            $this->lockDir(),
        ))->ensure();

        self::assertTrue(MYSQL_SSH_TUNNEL_ACTIVE, 'Existing constant must be preserved');
        self::assertSame(13306, MYSQL_SSH_TUNNEL_LOCAL_PORT);
    }
}
