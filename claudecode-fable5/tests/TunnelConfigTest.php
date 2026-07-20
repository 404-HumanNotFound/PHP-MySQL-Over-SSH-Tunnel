<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests;

use HumanNotFound\MysqlSshTunnel\TunnelConfig;
use HumanNotFound\MysqlSshTunnel\TunnelException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TunnelConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validConfig(array $overrides = []): array
    {
        return array_merge([
            'local_port'      => 3307,
            'server'          => 'remote.server.com',
            'ssh_user'        => 'someuser',
            'remote_port'     => 3306,
            'ssh_binary_path' => '/usr/bin/ssh',
        ], $overrides);
    }

    public function testValidConfigIsAccepted(): void
    {
        $config = TunnelConfig::fromArray($this->validConfig());

        self::assertSame(3307, $config->localPort);
        self::assertSame('remote.server.com', $config->server);
        self::assertSame('someuser', $config->sshUser);
        self::assertSame(3306, $config->remotePort);
        self::assertSame('/usr/bin/ssh', $config->sshBinaryPath);
        self::assertNull($config->sshKeyPath);
        self::assertTrue($config->strictHostKeyChecking);
        self::assertFalse($config->wantsRandomPort());
    }

    public function testRandomLocalPortIsAccepted(): void
    {
        $config = TunnelConfig::fromArray($this->validConfig(['local_port' => 'random']));

        self::assertTrue($config->wantsRandomPort());
    }

    public function testLocalPortDefaultsToRandomWhenOmitted(): void
    {
        $raw = $this->validConfig();
        unset($raw['local_port']);

        self::assertTrue(TunnelConfig::fromArray($raw)->wantsRandomPort());
    }

    #[DataProvider('missingRequiredKeyProvider')]
    public function testMissingRequiredKeyThrows(string $key): void
    {
        $raw = $this->validConfig();
        unset($raw[$key]);

        $this->expectException(TunnelException::class);
        $this->expectExceptionMessage($key);
        TunnelConfig::fromArray($raw);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingRequiredKeyProvider(): iterable
    {
        yield 'server' => ['server'];
        yield 'ssh_user' => ['ssh_user'];
        yield 'remote_port' => ['remote_port'];
        yield 'ssh_binary_path' => ['ssh_binary_path'];
    }

    #[DataProvider('invalidServerProvider')]
    public function testInvalidServerThrows(mixed $server): void
    {
        $this->expectException(TunnelException::class);
        TunnelConfig::fromArray($this->validConfig(['server' => $server]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidServerProvider(): iterable
    {
        yield 'shell metachars' => ['remote.server.com; rm -rf /'];
        yield 'command substitution' => ['$(hostname)'];
        yield 'backticks' => ['`hostname`'];
        yield 'leading dash (ssh option injection)' => ['-oProxyCommand=evil'];
        yield 'embedded space' => ['remote server.com'];
        yield 'empty' => [''];
        yield 'not a string' => [42];
    }

    #[DataProvider('invalidUserProvider')]
    public function testInvalidSshUserThrows(mixed $user): void
    {
        $this->expectException(TunnelException::class);
        TunnelConfig::fromArray($this->validConfig(['ssh_user' => $user]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidUserProvider(): iterable
    {
        yield 'shell metachars' => ['user;whoami'];
        yield 'leading dash' => ['-user'];
        yield 'embedded space' => ['some user'];
        yield 'empty' => [''];
        yield 'not a string' => [true];
    }

    #[DataProvider('invalidPortProvider')]
    public function testLocalPortOutOfRangeThrows(mixed $port): void
    {
        $this->expectException(TunnelException::class);
        TunnelConfig::fromArray($this->validConfig(['local_port' => $port]));
    }

    #[DataProvider('invalidPortProvider')]
    public function testRemotePortOutOfRangeThrows(mixed $port): void
    {
        $this->expectException(TunnelException::class);
        TunnelConfig::fromArray($this->validConfig(['remote_port' => $port]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPortProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'too high' => [65536];
        yield 'numeric string' => ['3306'];
        yield 'arbitrary string' => ['lots'];
    }

    public function testMissingSshKeyFileThrows(): void
    {
        $this->expectException(TunnelException::class);
        $this->expectExceptionMessage('ssh_key_path');
        TunnelConfig::fromArray($this->validConfig([
            'ssh_key_path' => '/nonexistent/path/to/key_' . uniqid(),
        ]));
    }

    public function testExistingSshKeyFileIsAccepted(): void
    {
        $key = tempnam(sys_get_temp_dir(), 'tunnel-test-key-');
        try {
            $config = TunnelConfig::fromArray($this->validConfig(['ssh_key_path' => $key]));
            self::assertSame($key, $config->sshKeyPath);
        } finally {
            @unlink($key);
        }
    }

    public function testSshPasswordConfigIsRejected(): void
    {
        $this->expectException(TunnelException::class);
        $this->expectExceptionMessage('not accepted');
        TunnelConfig::fromArray($this->validConfig(['ssh_password' => 'hunter2']));
    }

    public function testEnvironmentAllowedWhenListOmitted(): void
    {
        $config = TunnelConfig::fromArray($this->validConfig(['current_environment' => 'production']));

        self::assertTrue($config->isEnvironmentAllowed());
    }

    public function testEnvironmentAllowedWhenCurrentInList(): void
    {
        $config = TunnelConfig::fromArray($this->validConfig([
            'environments'        => ['development', 'local'],
            'current_environment' => 'local',
        ]));

        self::assertTrue($config->isEnvironmentAllowed());
    }

    public function testEnvironmentDisallowedWhenCurrentNotInList(): void
    {
        $config = TunnelConfig::fromArray($this->validConfig([
            'environments'        => ['development', 'local'],
            'current_environment' => 'production',
        ]));

        self::assertFalse($config->isEnvironmentAllowed());
    }

    public function testHashIsStableAndIgnoresRandomPortValue(): void
    {
        $a = TunnelConfig::fromArray($this->validConfig(['local_port' => 'random']));
        $b = TunnelConfig::fromArray($this->validConfig(['local_port' => 'random']));
        $fixed = TunnelConfig::fromArray($this->validConfig(['local_port' => 3307]));

        self::assertSame($a->hash(), $b->hash());
        self::assertNotSame($a->hash(), $fixed->hash());
    }

    public function testHashDiffersByTarget(): void
    {
        $a = TunnelConfig::fromArray($this->validConfig());
        $b = TunnelConfig::fromArray($this->validConfig(['server' => 'other.server.com']));

        self::assertNotSame($a->hash(), $b->hash());
    }
}
