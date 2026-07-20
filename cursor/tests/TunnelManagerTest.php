<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests;

use HumanNotFound\MysqlSshTunnel\Contract\LoggerInterface;
use HumanNotFound\MysqlSshTunnel\Lockfile\TunnelLockfile;
use HumanNotFound\MysqlSshTunnel\Process\ProcessHandleInterface;
use HumanNotFound\MysqlSshTunnel\Process\ProcessRunnerInterface;
use HumanNotFound\MysqlSshTunnel\TunnelConfig;
use HumanNotFound\MysqlSshTunnel\TunnelManager;
use HumanNotFound\MysqlSshTunnel\TunnelResult;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ArrayLogger implements LoggerInterface
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $warnings = [];

    public function warning(string $message, array $context = []): void
    {
        $this->warnings[] = ['message' => $message, 'context' => $context];
    }
}

final class FakeProcessHandle implements ProcessHandleInterface
{
    public function __construct(
        private ?int $pid,
        private bool $running = true,
    ) {
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function terminate(): void
    {
        $this->running = false;
    }

    public function stop(): void
    {
        $this->running = false;
    }
}

/**
 * Fake runner that optionally binds a real local TCP listener so port checks pass.
 */
final class FakeProcessRunner implements ProcessRunnerInterface
{
    /** @var list<list<string>> */
    public array $started = [];

    public ?FakeProcessHandle $lastHandle = null;

    /** @var resource|null */
    private mixed $listener = null;

    private int $listenPort = 0;

    private bool $bindListener;

    private bool $exitImmediately;

    public function __construct(bool $bindListener = true, bool $exitImmediately = false)
    {
        $this->bindListener = $bindListener;
        $this->exitImmediately = $exitImmediately;
    }

    public function start(array $arguments): ProcessHandleInterface
    {
        $this->started[] = $arguments;

        if ($this->exitImmediately) {
            $this->lastHandle = new FakeProcessHandle(9_001, false);

            return $this->lastHandle;
        }

        // Parse -L local:host:remote
        $localPort = 0;
        foreach ($arguments as $i => $arg) {
            if ($arg === '-L' && isset($arguments[$i + 1])) {
                $localPort = (int) explode(':', $arguments[$i + 1])[0];
                break;
            }
        }

        if ($this->bindListener && $localPort > 0) {
            $this->listenPort = $localPort;
            $this->listener = @stream_socket_server('tcp://127.0.0.1:' . $localPort, $errno, $errstr);
        }

        $this->lastHandle = new FakeProcessHandle(9_001, true);

        return $this->lastHandle;
    }

    public function closeListener(): void
    {
        if (is_resource($this->listener)) {
            fclose($this->listener);
            $this->listener = null;
        }
    }

    public function __destruct()
    {
        $this->closeListener();
    }
}

final class TunnelManagerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        TunnelManager::reset();
        $this->tmpDir = sys_get_temp_dir() . '/mysql-ssh-tunnel-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0700, true);
        TunnelManager::setLockfileStore(new TunnelLockfile($this->tmpDir));
    }

    protected function tearDown(): void
    {
        TunnelManager::reset();
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    public function testFallbackWhenBinaryMissing(): void
    {
        $logger = new ArrayLogger();
        $result = TunnelManager::boot($this->baseConfig([
            'ssh_binary_path' => '/this/path/does/not/exist/ssh',
            'logger' => $logger,
        ]));

        self::assertInstanceOf(TunnelResult::class, $result);
        self::assertFalse($result->active);
        self::assertSame('remote.example.com', $result->host);
        self::assertSame(3306, $result->localPort);
        self::assertNotEmpty($logger->warnings);
    }

    public function testDisallowedEnvironmentFallsBackWithoutStartingProcess(): void
    {
        $runner = new FakeProcessRunner();
        TunnelManager::setProcessRunner($runner);
        $logger = new ArrayLogger();

        $result = TunnelManager::boot($this->baseConfig([
            'environments' => ['production'],
            'current_environment' => 'development',
            'logger' => $logger,
        ]));

        self::assertFalse($result->active);
        self::assertSame([], $runner->started);
        self::assertStringContainsString('environment', strtolower($logger->warnings[0]['message']));
    }

    public function testStartsTunnelAndBuildsEscapedArgv(): void
    {
        $runner = new FakeProcessRunner(true);
        TunnelManager::setProcessRunner($runner);

        // Pick a free port for the fake listener.
        $port = $this->freePort();

        $result = TunnelManager::boot($this->baseConfig([
            'local_port' => $port,
            'ssh_port' => 2222,
            'ssh_binary_path' => $this->existingBinary(),
        ]));

        self::assertTrue($result->active);
        self::assertSame('127.0.0.1', $result->host);
        self::assertSame($port, $result->localPort);
        self::assertCount(1, $runner->started);

        $argv = $runner->started[0];
        self::assertSame($this->existingBinary(), $argv[0]);
        self::assertContains('-p', $argv);
        self::assertContains('2222', $argv);
        self::assertContains('-N', $argv);
        self::assertContains('-L', $argv);
        self::assertContains("{$port}:127.0.0.1:3306", $argv);
        self::assertContains('BatchMode=yes', $argv);
        self::assertContains('ExitOnForwardFailure=yes', $argv);
        self::assertContains('deploy@remote.example.com', $argv);
        self::assertNotContains('StrictHostKeyChecking=no', $argv);

        $runner->closeListener();
    }

    public function testReusesLiveTunnelFromLockfile(): void
    {
        $port = $this->freePort();
        $listener = stream_socket_server('tcp://127.0.0.1:' . $port);
        self::assertNotFalse($listener);

        $config = TunnelConfig::fromArray($this->baseConfig([
            'local_port' => $port,
            'ssh_binary_path' => $this->existingBinary(),
        ]));
        $hash = $config->configHash();
        $store = new TunnelLockfile($this->tmpDir);
        $path = $store->pathForHash($hash);
        $fh = $store->open($path);
        self::assertNotFalse($fh);
        // Use current PHP PID so posix_kill(0) reports alive.
        $store->write($fh, getmypid(), $port, $hash);
        flock($fh, LOCK_UN);
        fclose($fh);

        $runner = new FakeProcessRunner();
        TunnelManager::setProcessRunner($runner);

        $result = TunnelManager::boot($this->baseConfig([
            'local_port' => $port,
            'ssh_binary_path' => $this->existingBinary(),
        ]));

        self::assertTrue($result->active);
        self::assertSame($port, $result->localPort);
        self::assertSame([], $runner->started);

        fclose($listener);
    }

    public function testStaleLockfileStartsNewTunnel(): void
    {
        $port = $this->freePort();
        $config = TunnelConfig::fromArray($this->baseConfig([
            'local_port' => $port,
            'ssh_binary_path' => $this->existingBinary(),
        ]));
        $hash = $config->configHash();
        $store = new TunnelLockfile($this->tmpDir);
        $path = $store->pathForHash($hash);
        $fh = $store->open($path);
        self::assertNotFalse($fh);
        // PID 1 may be alive on Unix — use an almost-certainly-dead high PID
        // and leave the port closed so the stale path is taken.
        $store->write($fh, 2_147_483_646, $port, $hash);
        flock($fh, LOCK_UN);
        fclose($fh);

        $runner = new FakeProcessRunner(true);
        TunnelManager::setProcessRunner($runner);

        $result = TunnelManager::boot($this->baseConfig([
            'local_port' => $port,
            'ssh_binary_path' => $this->existingBinary(),
        ]));

        self::assertTrue($result->active);
        self::assertCount(1, $runner->started);
        $runner->closeListener();
    }

    public function testTunnelStartFailureFallsBack(): void
    {
        $runner = new FakeProcessRunner(false, true);
        TunnelManager::setProcessRunner($runner);
        $logger = new ArrayLogger();
        $port = $this->freePort();

        $result = TunnelManager::boot($this->baseConfig([
            'local_port' => $port,
            'ssh_binary_path' => $this->existingBinary(),
            'connect_timeout' => 0.3,
            'logger' => $logger,
        ]));

        self::assertFalse($result->active);
        self::assertSame('remote.example.com', $result->host);
    }

    public function testRandomPortAllocationAndShutdownRegistration(): void
    {
        $runner = new FakeProcessRunner(true);
        TunnelManager::setProcessRunner($runner);

        $before = TunnelManager::shutdownRegistrationCount();

        $result = TunnelManager::boot($this->baseConfig([
            'local_port' => 'random',
            'ssh_binary_path' => $this->existingBinary(),
            'connect_timeout' => 2.0,
        ]));

        self::assertTrue($result->active);
        self::assertGreaterThan(0, $result->localPort);
        self::assertSame('127.0.0.1', $result->host);
        self::assertCount(1, $runner->started);
        self::assertSame($before + 1, TunnelManager::shutdownRegistrationCount());

        $runner->closeListener();
    }

    public function testFixedPortDoesNotIncreaseShutdownCount(): void
    {
        $port = $this->freePort();
        $runner = new FakeProcessRunner(true);
        TunnelManager::setProcessRunner($runner);

        $before = TunnelManager::shutdownRegistrationCount();
        TunnelManager::boot($this->baseConfig([
            'local_port' => $port,
            'ssh_binary_path' => $this->existingBinary(),
        ]));

        self::assertSame($before, TunnelManager::shutdownRegistrationCount());
        $runner->closeListener();
    }

    public function testOptInDisablesStrictHostKeyChecking(): void
    {
        $port = $this->freePort();
        $runner = new FakeProcessRunner(true);
        TunnelManager::setProcessRunner($runner);

        TunnelManager::boot($this->baseConfig([
            'local_port' => $port,
            'ssh_binary_path' => $this->existingBinary(),
            'strict_host_key_checking' => false,
        ]));

        self::assertContains('StrictHostKeyChecking=no', $runner->started[0]);
        $runner->closeListener();
    }

    #[RunInSeparateProcess]
    public function testDefinesGlobalConstants(): void
    {
        $port = $this->freePort();
        $runner = new FakeProcessRunner(true);
        TunnelManager::setProcessRunner($runner);
        TunnelManager::setLockfileStore(new TunnelLockfile($this->tmpDir));

        TunnelManager::boot($this->baseConfig([
            'local_port' => $port,
            'ssh_binary_path' => $this->existingBinary(),
        ]));

        self::assertTrue(defined('MYSQL_SSH_TUNNEL_ACTIVE'));
        self::assertTrue(MYSQL_SSH_TUNNEL_ACTIVE);
        self::assertSame($port, MYSQL_SSH_TUNNEL_LOCAL_PORT);
        $runner->closeListener();
    }

    /** @param array<string, mixed> $overrides */
    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'local_port' => 3306,
            'server' => 'remote.example.com',
            'ssh_user' => 'deploy',
            'remote_port' => 3306,
            'ssh_binary_path' => $this->existingBinary(),
            'environments' => ['development'],
            'current_environment' => 'development',
        ], $overrides);
    }

    private function existingBinary(): string
    {
        foreach (['/usr/bin/ssh', '/bin/ssh', '/usr/sbin/ssh', '/bin/sh'] as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        self::markTestSkipped('No executable binary found for ssh_binary_path fixture');
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($name);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
