<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests\Unit;

use HumanNotFound\MysqlSshTunnel\Exception\ConfigValidationException;
use HumanNotFound\MysqlSshTunnel\TunnelConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TunnelConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'local_port' => 3306,
            'server' => 'remote.server.com',
            'ssh_user' => 'someuser',
            'ssh_port' => 22,
            'remote_port' => 3306,
            'ssh_binary_path' => PHP_BINARY, // any existing executable for tests
        ], $overrides);
    }

    public function testBuildsFromValidConfigAndAppliesDefaults(): void
    {
        $c = TunnelConfig::fromArray(self::baseConfig());

        self::assertSame('remote.server.com', $c->server);
        self::assertSame('someuser', $c->sshUser);
        self::assertSame(22, $c->sshPort);
        self::assertSame(3306, $c->remotePort);
        self::assertSame(3306, $c->localPort);
        self::assertNull($c->sshKeyPath);
        self::assertNull($c->environments);
        self::assertSame(10.0, $c->connectTimeout);
        self::assertTrue($c->strictHostKeyChecking);
        self::assertFalse($c->isRandomPort());
    }

    public function testDefaultsSshPortTo22WhenOmitted(): void
    {
        $config = self::baseConfig();
        unset($config['ssh_port']);

        $c = TunnelConfig::fromArray($config);

        self::assertSame(22, $c->sshPort);
    }

    public function testAcceptsRandomLocalPort(): void
    {
        $c = TunnelConfig::fromArray(self::baseConfig(['local_port' => 'random']));

        self::assertTrue($c->isRandomPort());
        self::assertSame('random', $c->localPort);
    }

    public function testRandomLocalPortIsCaseInsensitive(): void
    {
        $c = TunnelConfig::fromArray(self::baseConfig(['local_port' => 'RANDOM']));

        self::assertTrue($c->isRandomPort());
    }

    public function testInvalidServerThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        TunnelConfig::fromArray(self::baseConfig(['server' => 'bad host;rm -rf /']));
    }

    public function testMissingServerThrows(): void
    {
        $config = self::baseConfig();
        unset($config['server']);

        $this->expectException(ConfigValidationException::class);
        TunnelConfig::fromArray($config);
    }

    public function testAcceptsIpAddressServer(): void
    {
        $c = TunnelConfig::fromArray(self::baseConfig(['server' => '203.0.113.10']));

        self::assertSame('203.0.113.10', $c->server);
    }

    #[DataProvider('badUsernames')]
    public function testInvalidUsernameThrows(string $user): void
    {
        $this->expectException(ConfigValidationException::class);
        TunnelConfig::fromArray(self::baseConfig(['ssh_user' => $user]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badUsernames(): array
    {
        return [
            'semicolon' => ['user;whoami'],
            'space' => ['bad user'],
            'backtick' => ['user`id`'],
            'dollar' => ['user$(id)'],
            'empty' => [''],
        ];
    }

    #[DataProvider('outOfRangePorts')]
    public function testOutOfRangeLocalPortThrows(mixed $port): void
    {
        $this->expectException(ConfigValidationException::class);
        TunnelConfig::fromArray(self::baseConfig(['local_port' => $port]));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function outOfRangePorts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'too-high' => [70000],
            'not-random-string' => ['banana'],
        ];
    }

    public function testOutOfRangeSshPortThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        TunnelConfig::fromArray(self::baseConfig(['ssh_port' => 99999]));
    }

    public function testMissingSshBinaryPathThrows(): void
    {
        $config = self::baseConfig();
        unset($config['ssh_binary_path']);

        $this->expectException(ConfigValidationException::class);
        TunnelConfig::fromArray($config);
    }

    public function testSshKeyPathThatDoesNotExistThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        TunnelConfig::fromArray(self::baseConfig([
            'ssh_key_path' => '/definitely/not/a/real/key/'.uniqid('', true),
        ]));
    }

    public function testExistingSshKeyPathIsAccepted(): void
    {
        $key = tempnam(sys_get_temp_dir(), 'tunnelkey');
        self::assertIsString($key);

        try {
            $c = TunnelConfig::fromArray(self::baseConfig(['ssh_key_path' => $key]));
            self::assertSame($key, $c->sshKeyPath);
        } finally {
            @unlink($key);
        }
    }

    public function testNonNumericConnectTimeoutThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        TunnelConfig::fromArray(self::baseConfig(['connect_timeout' => 'soon']));
    }

    public function testLoggerWithoutWarningMethodThrows(): void
    {
        $this->expectException(ConfigValidationException::class);
        TunnelConfig::fromArray(self::baseConfig(['logger' => new \stdClass()]));
    }

    public function testEnvironmentAllowedWhenListedAndOmitted(): void
    {
        $allowed = TunnelConfig::fromArray(self::baseConfig([
            'environments' => ['development', 'local'],
            'current_environment' => 'local',
        ]));
        self::assertTrue($allowed->isEnvironmentAllowed());

        $noList = TunnelConfig::fromArray(self::baseConfig());
        self::assertTrue($noList->isEnvironmentAllowed(), 'Omitted environments => allow all');
    }

    public function testEnvironmentNotAllowedWhenNotListedOrUnknown(): void
    {
        $prod = TunnelConfig::fromArray(self::baseConfig([
            'environments' => ['development', 'local'],
            'current_environment' => 'production',
        ]));
        self::assertFalse($prod->isEnvironmentAllowed());

        $unknown = TunnelConfig::fromArray(self::baseConfig([
            'environments' => ['development'],
        ]));
        self::assertFalse($unknown->isEnvironmentAllowed(), 'List set but no current_environment => not allowed');
    }

    public function testIdentityHashIsStableAndPortSensitive(): void
    {
        $a = TunnelConfig::fromArray(self::baseConfig(['local_port' => 3306]));
        $b = TunnelConfig::fromArray(self::baseConfig(['local_port' => 3306]));
        $c = TunnelConfig::fromArray(self::baseConfig(['local_port' => 3307]));

        self::assertSame($a->identityHash(), $b->identityHash());
        self::assertNotSame($a->identityHash(), $c->identityHash());
    }

    public function testRandomPortIdentityIgnoresLocalPort(): void
    {
        $rand = TunnelConfig::fromArray(self::baseConfig(['local_port' => 'random']));
        $fixed = TunnelConfig::fromArray(self::baseConfig(['local_port' => 3306]));

        self::assertNotSame($rand->identityHash(), $fixed->identityHash());
    }
}
