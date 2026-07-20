<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests;

use HumanNotFound\MysqlSshTunnel\TunnelConfig;
use HumanNotFound\MysqlSshTunnel\TunnelManager;
use HumanNotFound\MysqlSshTunnel\Tests\Support\ArrayLogger;
use HumanNotFound\MysqlSshTunnel\Tests\Support\FakeProcessHandle;
use HumanNotFound\MysqlSshTunnel\Tests\Support\FakeProcessRunner;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class TunnelManagerTest extends TestCase
{
    private ArrayLogger $logger;

    /** @var list<string> lockfiles created during a test, removed in tearDown */
    private array $lockfiles = [];

    /** @var list<resource> sockets held open during a test, closed in tearDown */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->logger = new ArrayLogger();
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            @fclose($socket);
        }
        $this->sockets = [];

        foreach ($this->lockfiles as $path) {
            @unlink($path);
        }
        $this->lockfiles = [];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeConfig(array $overrides = []): TunnelConfig
    {
        // Unique server per test => unique config hash => unique lockfile,
        // so tests cannot interfere with each other (or with a real tunnel).
        return TunnelConfig::fromArray(array_merge([
            'local_port'      => 3307,
            'server'          => 'unit-' . bin2hex(random_bytes(6)) . '.example.test',
            'ssh_user'        => 'testuser',
            'remote_port'     => 3306,
            'ssh_binary_path' => $this->existingExecutable(),
            'connect_timeout' => 0.5, // keep failure-path tests fast
            'logger'          => $this->logger,
        ], $overrides));
    }

    private function makeManager(
        TunnelConfig $config,
        ?FakeProcessRunner $runner = null,
        ?callable $shutdownRegistrar = null,
    ): TunnelManager {
        $manager = new TunnelManager(
            $config,
            $runner ?? new FakeProcessRunner(),
            $shutdownRegistrar ?? static function (callable $fn): void {
                // swallow: never register real shutdown functions from tests
            },
        );
        $this->lockfiles[] = $manager->lockfilePath();

        return $manager;
    }

    /** A binary that exists and is executable on any POSIX system. */
    private function existingExecutable(): string
    {
        foreach (['/bin/sh', '/bin/ls', '/usr/bin/env'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        self::fail('No known executable found to stand in for the ssh binary.');
    }

    /**
     * Bind a listening socket on an OS-assigned port and hold it open for
     * the duration of the test — simulates a live ssh forward.
     *
     * @return array{0: resource, 1: int} [socket, port]
     */
    private function listenOnEphemeralPort(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($socket, 'Failed to bind test socket: ' . $errstr);
        $this->sockets[] = $socket;

        $name = stream_socket_get_name($socket, false);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

        return [$socket, $port];
    }

    private function listenOnPort(int $port): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);
        self::assertNotFalse($socket, "Failed to bind test socket on port {$port}: {$errstr}");
        $this->sockets[] = $socket;
    }

    /** A PID that is certainly not a live process. */
    private function deadPid(): int
    {
        // Both Linux (default pid_max 4194304 can be raised, so probe) and
        // macOS (pid_max 99998) recycle PIDs; probe for one that's dead.
        for ($pid = 4_000_000; $pid > 3_999_900; $pid--) {
            if (function_exists('posix_kill')) {
                if (!@posix_kill($pid, 0) && posix_get_last_error() !== 1) {
                    return $pid;
                }
            } elseif (!is_dir('/proc/' . $pid)) {
                return $pid;
            }
        }

        self::fail('Could not find a dead PID to test with.');
    }

    private function writeLockfile(TunnelManager $manager, int $pid, int $port): void
    {
        file_put_contents($manager->lockfilePath(), json_encode([
            'pid' => $pid,
            'port' => $port,
            'created_at' => time(),
        ]));
        chmod($manager->lockfilePath(), 0o600);
    }

    // ------------------------------------------------------------------
    //  Environment restriction
    // ------------------------------------------------------------------

    public function testDisallowedEnvironmentSkipsTunnelAndFallsBack(): void
    {
        $runner = new FakeProcessRunner();
        $config = $this->makeConfig([
            'environments'        => ['development', 'local'],
            'current_environment' => 'production',
        ]);
        $result = $this->makeManager($config, $runner)->ensure();

        self::assertFalse($result->active);
        self::assertSame($config->remotePort, $result->localPort);
        self::assertSame($config->server, $result->host);
        self::assertFalse($runner->wasCalled(), 'No ssh process may be started in a disallowed environment');
        self::assertTrue($this->logger->hasWarningContaining('not in the allowed environments list'));
    }

    public function testAllowedEnvironmentProceeds(): void
    {
        [, $port] = $this->listenOnEphemeralPort();
        $config = $this->makeConfig([
            'local_port'          => $port,
            'environments'        => ['development'],
            'current_environment' => 'development',
        ]);
        $result = $this->makeManager($config, new FakeProcessRunner())->ensure();

        self::assertTrue($result->active);
    }

    // ------------------------------------------------------------------
    //  Missing binary → fallback (never throw)
    // ------------------------------------------------------------------

    public function testMissingSshBinaryFallsBackWithWarning(): void
    {
        $runner = new FakeProcessRunner();
        $config = $this->makeConfig(['ssh_binary_path' => '/nonexistent/ssh_' . uniqid()]);
        $result = $this->makeManager($config, $runner)->ensure();

        self::assertFalse($result->active);
        self::assertSame($config->remotePort, $result->localPort);
        self::assertSame($config->server, $result->host);
        self::assertFalse($runner->wasCalled());
        self::assertTrue($this->logger->hasWarningContaining('does not exist or is not executable'));
    }

    // ------------------------------------------------------------------
    //  Starting a tunnel
    // ------------------------------------------------------------------

    public function testStartsTunnelOnFixedPortAndWritesLockfile(): void
    {
        [, $port] = $this->listenOnEphemeralPort(); // simulates the forward being up
        $runner = new FakeProcessRunner();
        $config = $this->makeConfig(['local_port' => $port]);
        $manager = $this->makeManager($config, $runner);

        $result = $manager->ensure();

        self::assertTrue($result->active);
        self::assertSame($port, $result->localPort);
        self::assertSame('127.0.0.1', $result->host);
        self::assertTrue($runner->wasCalled());

        $lock = json_decode((string) file_get_contents($manager->lockfilePath()), true);
        self::assertSame(12345, $lock['pid']);
        self::assertSame($port, $lock['port']);
    }

    public function testLockfileHasRestrictivePermissions(): void
    {
        [, $port] = $this->listenOnEphemeralPort();
        $manager = $this->makeManager($this->makeConfig(['local_port' => $port]), new FakeProcessRunner());
        $manager->ensure();

        $mode = fileperms($manager->lockfilePath()) & 0o777;
        self::assertSame(0o600, $mode, sprintf('Expected 0600, got %o', $mode));
    }

    public function testCommandIsBuiltFromEscapedArguments(): void
    {
        $key = tempnam(sys_get_temp_dir(), 'tunnel-test-key-');
        try {
            $runner = new FakeProcessRunner(
                // handle that is not running => fast failure, command still recorded
                static fn (): FakeProcessHandle => new FakeProcessHandle(running: false)
            );
            $config = $this->makeConfig(['local_port' => 3307, 'ssh_key_path' => $key]);
            $this->makeManager($config, $runner)->ensure();

            self::assertCount(1, $runner->commands);
            $command = $runner->commands[0];

            self::assertStringContainsString("'-N'", $command);
            self::assertStringContainsString("'BatchMode=yes'", $command);
            self::assertStringContainsString("'ExitOnForwardFailure=yes'", $command);
            self::assertStringContainsString("'-i' '" . $key . "'", $command);
            self::assertStringContainsString("'3307:127.0.0.1:3306'", $command);
            self::assertStringContainsString("'" . $config->sshUser . '@' . $config->server . "'", $command);
            self::assertStringNotContainsString('StrictHostKeyChecking', $command, 'Strict checking is the default — no override flag');
        } finally {
            @unlink($key);
        }
    }

    public function testStrictHostKeyCheckingCanBeExplicitlyDisabled(): void
    {
        $runner = new FakeProcessRunner(static fn (): FakeProcessHandle => new FakeProcessHandle(running: false));
        $config = $this->makeConfig(['strict_host_key_checking' => false]);
        $this->makeManager($config, $runner)->ensure();

        self::assertStringContainsString("'StrictHostKeyChecking=no'", $runner->commands[0]);
    }

    // ------------------------------------------------------------------
    //  Random port allocation
    // ------------------------------------------------------------------

    public function testRandomLocalPortIsAllocatedAndForwarded(): void
    {
        $runner = new FakeProcessRunner(function (string $command): FakeProcessHandle {
            // Simulate ssh binding the forward on the port it was asked for.
            $this->listenOnPort(FakeProcessRunner::localPortFromCommand($command));

            return new FakeProcessHandle();
        });
        $config = $this->makeConfig(['local_port' => 'random']);
        $result = $this->makeManager($config, $runner)->ensure();

        self::assertTrue($result->active);
        self::assertGreaterThanOrEqual(1024, $result->localPort, 'Ephemeral ports are unprivileged');
        self::assertLessThanOrEqual(65535, $result->localPort);
        self::assertNotSame($config->remotePort, $result->localPort);
        self::assertSame($result->localPort, FakeProcessRunner::localPortFromCommand($runner->commands[0]));
    }

    // ------------------------------------------------------------------
    //  Shutdown-function registration
    // ------------------------------------------------------------------

    public function testShutdownFunctionRegisteredOnlyForRandomPort(): void
    {
        $registered = [];
        $registrar = static function (callable $fn) use (&$registered): void {
            $registered[] = $fn;
        };

        $handle = new FakeProcessHandle();
        $runner = new FakeProcessRunner(function (string $command) use ($handle): FakeProcessHandle {
            $this->listenOnPort(FakeProcessRunner::localPortFromCommand($command));

            return $handle;
        });
        $manager = $this->makeManager($this->makeConfig(['local_port' => 'random']), $runner, $registrar);
        $manager->ensure();

        self::assertCount(1, $registered, 'Random-port tunnels must register a shutdown function');

        // Running the registered callback terminates the process and
        // removes the lockfile.
        self::assertFileExists($manager->lockfilePath());
        ($registered[0])();
        self::assertTrue($handle->terminated);
        self::assertFileDoesNotExist($manager->lockfilePath());
    }

    public function testNoShutdownFunctionForFixedPort(): void
    {
        $registered = [];
        $registrar = static function (callable $fn) use (&$registered): void {
            $registered[] = $fn;
        };

        [, $port] = $this->listenOnEphemeralPort();
        $manager = $this->makeManager(
            $this->makeConfig(['local_port' => $port]),
            new FakeProcessRunner(),
            $registrar,
        );
        $result = $manager->ensure();

        self::assertTrue($result->active);
        self::assertCount(0, $registered, 'Fixed-port tunnels are left running for reuse across requests');
    }

    // ------------------------------------------------------------------
    //  Lockfile detection: live vs stale
    // ------------------------------------------------------------------

    public function testReusesLiveTunnelFromLockfileWithoutStartingProcess(): void
    {
        [, $port] = $this->listenOnEphemeralPort(); // "the tunnel" is listening
        $runner = new FakeProcessRunner();
        $manager = $this->makeManager($this->makeConfig(['local_port' => $port]), $runner);

        // Live PID (our own) + accepting port = reusable tunnel.
        $this->writeLockfile($manager, (int) getmypid(), $port);

        $result = $manager->ensure();

        self::assertTrue($result->active);
        self::assertSame($port, $result->localPort);
        self::assertFalse($runner->wasCalled(), 'A live tunnel must be reused, not restarted');
    }

    public function testStaleLockfileWithDeadPidStartsNewTunnel(): void
    {
        $runner = new FakeProcessRunner(function (string $command): FakeProcessHandle {
            $this->listenOnPort(FakeProcessRunner::localPortFromCommand($command));

            return new FakeProcessHandle();
        });
        [, $port] = $this->listenOnEphemeralPort();
        fclose(array_pop($this->sockets)); // port now known-free and closed
        $manager = $this->makeManager($this->makeConfig(['local_port' => $port]), $runner);

        $this->writeLockfile($manager, $this->deadPid(), $port);

        $result = $manager->ensure();

        self::assertTrue($result->active);
        self::assertTrue($runner->wasCalled(), 'A stale lockfile must not prevent a new tunnel');
    }

    public function testStaleLockfileWithClosedPortStartsNewTunnel(): void
    {
        // PID alive (ours) but nothing listening on the recorded port: the
        // process may exist but it is not serving this tunnel any more.
        $runner = new FakeProcessRunner(function (string $command): FakeProcessHandle {
            $this->listenOnPort(FakeProcessRunner::localPortFromCommand($command));

            return new FakeProcessHandle();
        });
        [, $port] = $this->listenOnEphemeralPort();
        fclose(array_pop($this->sockets));
        $manager = $this->makeManager($this->makeConfig(['local_port' => $port]), $runner);

        $this->writeLockfile($manager, (int) getmypid(), $port);

        $result = $manager->ensure();

        self::assertTrue($result->active);
        self::assertTrue($runner->wasCalled());
    }

    public function testCorruptLockfileIsIgnoredAndTunnelStarted(): void
    {
        [, $port] = $this->listenOnEphemeralPort();
        $runner = new FakeProcessRunner();
        $manager = $this->makeManager($this->makeConfig(['local_port' => $port]), $runner);

        file_put_contents($manager->lockfilePath(), 'this is not json {{{');

        $result = $manager->ensure();

        self::assertTrue($result->active);
        self::assertTrue($runner->wasCalled());
    }

    // ------------------------------------------------------------------
    //  Tunnel failure → fallback (never throw)
    // ------------------------------------------------------------------

    public function testProcessThatExitsImmediatelyFallsBack(): void
    {
        $runner = new FakeProcessRunner(
            static fn (): FakeProcessHandle => new FakeProcessHandle(running: false, errorOutput: 'Permission denied (publickey).')
        );
        $config = $this->makeConfig();
        $result = $this->makeManager($config, $runner)->ensure();

        self::assertFalse($result->active);
        self::assertSame($config->remotePort, $result->localPort);
        self::assertSame($config->server, $result->host);
        self::assertTrue($this->logger->hasWarningContaining('could not be established'));
    }

    public function testForwardNeverComingUpFallsBackAfterTimeout(): void
    {
        $handle = new FakeProcessHandle(running: true); // runs, but never binds the port
        $runner = new FakeProcessRunner(static fn (): FakeProcessHandle => $handle);
        $config = $this->makeConfig(['connect_timeout' => 0.3]);

        $start = microtime(true);
        $result = $this->makeManager($config, $runner)->ensure();
        $elapsed = microtime(true) - $start;

        self::assertFalse($result->active);
        self::assertTrue($handle->terminated, 'A half-started ssh process must be cleaned up');
        self::assertGreaterThanOrEqual(0.3, $elapsed);
        self::assertLessThan(5.0, $elapsed, 'Must respect the configured timeout');
        self::assertTrue($this->logger->hasWarningContaining('could not be established'));
    }

    public function testRunnerFailingToStartFallsBack(): void
    {
        $runner = new FakeProcessRunner(static fn (): ?FakeProcessHandle => null);
        $config = $this->makeConfig();
        $result = $this->makeManager($config, $runner)->ensure();

        self::assertFalse($result->active);
        self::assertTrue($this->logger->hasWarningContaining('failed to start'));
    }

    // ------------------------------------------------------------------
    //  Constants
    // ------------------------------------------------------------------

    #[RunInSeparateProcess]
    public function testDefineConstantsDefinesAndGuardsAgainstRedefinition(): void
    {
        [, $port] = $this->listenOnEphemeralPort();
        $manager = $this->makeManager($this->makeConfig(['local_port' => $port]), new FakeProcessRunner());

        $result = $manager->ensure();
        $manager->defineConstants($result);

        self::assertTrue(defined('MYSQL_SSH_TUNNEL_LOCAL_PORT'));
        self::assertTrue(defined('MYSQL_SSH_TUNNEL_ACTIVE'));
        self::assertSame($port, MYSQL_SSH_TUNNEL_LOCAL_PORT);
        self::assertTrue(MYSQL_SSH_TUNNEL_ACTIVE);

        // Second call (e.g. a second bootstrap path in the same request)
        // must be a no-op, not a fatal "constant already defined" error.
        $manager->defineConstants($result);
        self::assertSame($port, MYSQL_SSH_TUNNEL_LOCAL_PORT);
    }

    #[RunInSeparateProcess]
    public function testFallbackConstantsCarryRemotePortAndInactiveFlag(): void
    {
        $config = $this->makeConfig([
            'environments'        => ['development'],
            'current_environment' => 'production',
        ]);
        $manager = $this->makeManager($config, new FakeProcessRunner());

        $manager->defineConstants($manager->ensure());

        self::assertFalse(MYSQL_SSH_TUNNEL_ACTIVE);
        self::assertSame($config->remotePort, MYSQL_SSH_TUNNEL_LOCAL_PORT);
    }
}
