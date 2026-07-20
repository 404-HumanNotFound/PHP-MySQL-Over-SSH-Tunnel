<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests;

use HumanNotFound\MysqlSshTunnel\Exception\InvalidConfigException;
use HumanNotFound\MysqlSshTunnel\TunnelConfig;
use PHPUnit\Framework\TestCase;

final class TunnelConfigTest extends TestCase
{
    public function testValidMinimalConfig(): void
    {
        $config = TunnelConfig::fromArray($this->baseConfig());

        self::assertSame('remote.example.com', $config->server);
        self::assertSame('deploy', $config->sshUser);
        self::assertSame(22, $config->sshPort);
        self::assertSame(3306, $config->remotePort);
        self::assertFalse($config->isRandomPort);
        self::assertTrue($config->strictHostKeyChecking);
    }

    public function testRandomLocalPort(): void
    {
        $config = TunnelConfig::fromArray($this->baseConfig(['local_port' => 'random']));

        self::assertTrue($config->isRandomPort);
        self::assertSame('random', $config->localPort);
    }

    public function testSshPortDefaultAndValidation(): void
    {
        $config = TunnelConfig::fromArray($this->baseConfig());
        self::assertSame(22, $config->sshPort);

        $config = TunnelConfig::fromArray($this->baseConfig(['ssh_port' => 2222]));
        self::assertSame(2222, $config->sshPort);

        $this->expectException(InvalidConfigException::class);
        TunnelConfig::fromArray($this->baseConfig(['ssh_port' => 70000]));
    }

    public function testRejectsShellMetacharactersInServer(): void
    {
        $this->expectException(InvalidConfigException::class);
        TunnelConfig::fromArray($this->baseConfig(['server' => 'evil.com; rm -rf /']));
    }

    public function testRejectsInvalidUsername(): void
    {
        $this->expectException(InvalidConfigException::class);
        TunnelConfig::fromArray($this->baseConfig(['ssh_user' => 'user with spaces']));
    }

    public function testRejectsMissingRequiredKeys(): void
    {
        $this->expectException(InvalidConfigException::class);
        TunnelConfig::fromArray(['local_port' => 3306]);
    }

    public function testRejectsMissingSshKeyFile(): void
    {
        $this->expectException(InvalidConfigException::class);
        TunnelConfig::fromArray($this->baseConfig([
            'ssh_key_path' => '/tmp/definitely-does-not-exist-ssh-key-' . uniqid('', true),
        ]));
    }

    public function testConfigHashStableForFixedPort(): void
    {
        $a = TunnelConfig::fromArray($this->baseConfig());
        $b = TunnelConfig::fromArray($this->baseConfig());
        self::assertSame($a->configHash(), $b->configHash());
    }

    public function testConfigHashIgnoresRandomLocalPort(): void
    {
        $a = TunnelConfig::fromArray($this->baseConfig(['local_port' => 'random']));
        $b = TunnelConfig::fromArray($this->baseConfig(['local_port' => 'random']));
        self::assertSame($a->configHash(), $b->configHash());
    }

    public function testEnvironmentGating(): void
    {
        $allowed = TunnelConfig::fromArray($this->baseConfig([
            'environments' => ['development'],
            'current_environment' => 'development',
        ]));
        self::assertTrue($allowed->environmentAllowed());

        $denied = TunnelConfig::fromArray($this->baseConfig([
            'environments' => ['production'],
            'current_environment' => 'development',
        ]));
        self::assertFalse($denied->environmentAllowed());

        $open = TunnelConfig::fromArray($this->baseConfig());
        self::assertTrue($open->environmentAllowed());
    }

    public function testIpv4ServerAccepted(): void
    {
        $config = TunnelConfig::fromArray($this->baseConfig(['server' => '192.168.1.10']));
        self::assertSame('192.168.1.10', $config->server);
    }

    /** @param array<string, mixed> $overrides */
    private function baseConfig(array $overrides = []): array
    {
        $binary = is_executable('/bin/sh') ? '/bin/sh' : (is_executable('/usr/bin/ssh') ? '/usr/bin/ssh' : __FILE__);

        return array_merge([
            'local_port' => 3306,
            'server' => 'remote.example.com',
            'ssh_user' => 'deploy',
            'remote_port' => 3306,
            'ssh_binary_path' => $binary,
        ], $overrides);
    }
}
