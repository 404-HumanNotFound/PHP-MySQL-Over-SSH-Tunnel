<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests\Unit;

use HumanNotFound\MysqlSshTunnel\Lockfile;
use HumanNotFound\MysqlSshTunnel\Tests\Support\FakeSystem;
use HumanNotFound\MysqlSshTunnel\Tests\Support\RecordingLogger;
use HumanNotFound\MysqlSshTunnel\TunnelConfig;
use HumanNotFound\MysqlSshTunnel\TunnelManager;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the full detect/reuse/start/fallback/shutdown logic using
 * {@see FakeSystem} — no real ssh, no real sockets. Lockfiles are written to an
 * isolated temp dir per test.
 *
 * These assert on the returned {@see TunnelResult} (which does not depend on the
 * process-global constants), so they can all share one process. The global
 * constants themselves are asserted separately in {@see ConstantsTest} under
 * isolated processes.
 */
final class TunnelManagerTest extends TestCase
{
    private string $lockDir;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir().'/tunnel-tests-'.bin2hex(random_bytes(6));
        mkdir($this->lockDir, 0700, true);
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->lockDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->lockDir);
    }

    /**
     * @return array<string, mixed>
     */
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
            'logger' => $this->logger,
        ], $overrides);
    }

    private function manager(array $overrides, FakeSystem $system): TunnelManager
    {
        return new TunnelManager(
            TunnelConfig::fromArray($this->config($overrides)),
            $system,
            $this->lockDir,
        );
    }

    public function testStartsNewFixedPortTunnelSuccessfully(): void
    {
        $system = new FakeSystem();
        $system->listening = true;

        $result = $this->manager([], $system)->ensure();

        self::assertTrue($result->active);
        self::assertSame('127.0.0.1', $result->host);
        self::assertSame(3306, $result->port);
        self::assertFalse($result->reused);
        self::assertCount(1, $system->spawned, 'ssh should have been spawned once');

        // Fixed-port tunnels are NOT torn down on shutdown.
        self::assertCount(0, $system->shutdownCallbacks);

        // A lockfile with the pid+port should now exist.
        $lock = new Lockfile(
            TunnelConfig::fromArray($this->config())->identityHash(),
            $this->lockDir,
        );
        self::assertFileExists($lock->path());
    }

    public function testBuildsCorrectSshArgv(): void
    {
        $system = new FakeSystem();
        $this->manager(['ssh_port' => 2222, 'remote_port' => 3306], $system)->ensure();

        $argv = $system->spawned[0]['argv'];

        self::assertSame(PHP_BINARY, $argv[0]);
        self::assertContains('-N', $argv);
        self::assertContains('BatchMode=yes', $argv);
        self::assertContains('ExitOnForwardFailure=yes', $argv);

        // ssh_port passed via -p (distinct from remote_port).
        $p = array_search('-p', $argv, true);
        self::assertNotFalse($p);
        self::assertSame('2222', $argv[$p + 1]);

        // -L local:127.0.0.1:remote
        $l = array_search('-L', $argv, true);
        self::assertNotFalse($l);
        self::assertSame('3306:127.0.0.1:3306', $argv[$l + 1]);

        // user@server is the final argument.
        self::assertSame('tunneluser@db.internal.example', $argv[array_key_last($argv)]);
    }

    public function testIncludesIdentityFileWhenKeyPathProvided(): void
    {
        $key = tempnam(sys_get_temp_dir(), 'k');
        self::assertIsString($key);

        try {
            $system = new FakeSystem();
            $this->manager(['ssh_key_path' => $key], $system)->ensure();
            $argv = $system->spawned[0]['argv'];

            $i = array_search('-i', $argv, true);
            self::assertNotFalse($i);
            self::assertSame($key, $argv[$i + 1]);
            self::assertContains('IdentitiesOnly=yes', $argv);
        } finally {
            @unlink($key);
        }
    }

    public function testDefaultDoesNotDisableStrictHostKeyChecking(): void
    {
        $system = new FakeSystem();
        $this->manager([], $system)->ensure();
        $argv = $system->spawned[0]['argv'];

        self::assertNotContains('StrictHostKeyChecking=no', $argv);
    }

    public function testStrictHostKeyCheckingCanBeOptedOut(): void
    {
        $system = new FakeSystem();
        $this->manager(['strict_host_key_checking' => false], $system)->ensure();
        $argv = $system->spawned[0]['argv'];

        self::assertContains('StrictHostKeyChecking=no', $argv);
    }

    public function testRandomPortAllocatesAndRegistersShutdown(): void
    {
        $system = new FakeSystem();
        $system->freePort = 54321;

        $result = $this->manager(['local_port' => 'random'], $system)->ensure();

        self::assertTrue($result->active);
        self::assertSame(54321, $result->port);

        // -L uses the allocated ephemeral port.
        $argv = $system->spawned[0]['argv'];
        $l = array_search('-L', $argv, true);
        self::assertSame('54321:127.0.0.1:3306', $argv[$l + 1]);

        // Random-port tunnels DO register a shutdown teardown.
        self::assertCount(1, $system->shutdownCallbacks);
    }

    public function testReusesLiveMatchingTunnel(): void
    {
        // Seed a lockfile with a live pid + listening port.
        $cfg = TunnelConfig::fromArray($this->config());
        $lock = new Lockfile($cfg->identityHash(), $this->lockDir);
        $h = $lock->openForLocking();
        $lock->write($h, 9999, 3306);
        $lock->releaseLock($h);

        $system = new FakeSystem();
        $system->alive[9999] = true;
        $system->listening = true;

        $result = $this->manager([], $system)->ensure();

        self::assertTrue($result->active);
        self::assertTrue($result->reused);
        self::assertSame(3306, $result->port);
        self::assertCount(0, $system->spawned, 'A live tunnel must be reused, not re-spawned');
    }

    public function testStaleDeadPidTriggersRestart(): void
    {
        $cfg = TunnelConfig::fromArray($this->config());
        $lock = new Lockfile($cfg->identityHash(), $this->lockDir);
        $h = $lock->openForLocking();
        $lock->write($h, 1234, 3306);
        $lock->releaseLock($h);

        $system = new FakeSystem();
        $system->alive[1234] = false; // stale
        $system->nextPid = 5678;
        $system->listening = true;

        $result = $this->manager([], $system)->ensure();

        self::assertTrue($result->active);
        self::assertFalse($result->reused);
        self::assertCount(1, $system->spawned, 'Stale lockfile must trigger a fresh spawn');
    }

    public function testFallsBackWhenTunnelNeverComesUp(): void
    {
        $system = new FakeSystem();
        $system->nextPid = 7777;
        $system->alive[7777] = false; // ssh "exited immediately"
        $system->listening = false;

        $result = $this->manager([], $system)->ensure();

        self::assertFalse($result->active);
        self::assertSame('db.internal.example', $result->host, 'Fallback host is the remote server');
        self::assertSame(3306, $result->port, 'Fallback port is the remote port');
        self::assertContains(7777, $system->terminated, 'Failed ssh should be terminated');
        self::assertNotEmpty($this->logger->warnings);
    }

    public function testFallsBackWhenBinaryNotExecutable(): void
    {
        $system = new FakeSystem();
        $system->executable = false;

        $result = $this->manager([], $system)->ensure();

        self::assertFalse($result->active);
        self::assertSame('db.internal.example', $result->host);
        self::assertCount(0, $system->spawned, 'Must not spawn ssh when the binary is unusable');
        self::assertNotEmpty($this->logger->warnings);
    }

    public function testFallsBackWhenEnvironmentNotAllowed(): void
    {
        $system = new FakeSystem();

        $result = $this->manager([
            'environments' => ['development', 'local'],
            'current_environment' => 'production',
        ], $system)->ensure();

        self::assertFalse($result->active);
        self::assertSame('db.internal.example', $result->host);
        self::assertCount(0, $system->spawned, 'Disallowed environment must not spawn ssh');
        self::assertNotEmpty($this->logger->warnings);
        self::assertStringContainsString('environment', strtolower($this->logger->warnings[0]));
    }

    public function testFallbackNeverThrows(): void
    {
        $system = new FakeSystem();
        $system->executable = false;

        // The whole point: environmental failure returns a result, never throws.
        $result = $this->manager([], $system)->ensure();
        self::assertInstanceOf(\HumanNotFound\MysqlSshTunnel\TunnelResult::class, $result);
    }
}
