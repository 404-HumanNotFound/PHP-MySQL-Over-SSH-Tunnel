<?php

use PHPUnit\Framework\TestCase;
use PhpMySqlOverSshTunnel\TunnelManager;
use PhpMySqlOverSshTunnel\Config;

final class TunnelManagerTest extends TestCase
{
    public function testConfigValidationFailsForBadHost(): void
    {
        $this->expectException(\PhpMySqlOverSshTunnel\Exception\ConfigValidationException::class);
        new \PhpMySqlOverSshTunnel\Config(['server' => 'bad host', 'ssh_user' => 'u', 'ssh_binary_path' => '/bin/true']);
    }

    public function testFallbackWhenSshBinaryMissing(): void
    {
        // use a temporary file as "ssh" that is executable
        $tmp = sys_get_temp_dir() . '/fake-ssh-' . uniqid();
        file_put_contents($tmp, "#!/bin/sh\nexit 0\n");
        chmod($tmp, 0755);

        $result = TunnelManager::boot([
            'local_port' => 3307,
            'server' => 'example.com',
            'ssh_user' => 'user',
            'ssh_port' => 22,
            'remote_port' => 3306,
            'ssh_binary_path' => $tmp,
            // process_runner returns null to simulate failure to start
            'process_runner' => function(array $argv) { return null; },
            'current_environment' => 'development',
        ]);

        $this->assertFalse($result->active);
        $this->assertEquals(3306, $result->port);

        @unlink($tmp);
    }

    public function testRandomPortAllocationAndShutdownRegistration(): void
    {
        $tmp = sys_get_temp_dir() . '/fake-ssh-' . uniqid();
        file_put_contents($tmp, "#!/bin/sh\n# sleep to simulate long running\nsleep 2 &\nexit 0\n");
        chmod($tmp, 0755);

        // fake process runner that simulates a started process resource
        $fakeHandle = ['proc' => tmpfile()];
        $called = false;
        $processRunner = function(array $argv) use ($fakeHandle, &$called) {
            $called = true;
            return $fakeHandle;
        };

        $result = TunnelManager::boot([
            'local_port' => 'random',
            'server' => 'example.com',
            'ssh_user' => 'user',
            'ssh_port' => 22,
            'remote_port' => 3306,
            'ssh_binary_path' => $tmp,
            'process_runner' => $processRunner,
            'current_environment' => 'development',
            'connect_timeout' => 1,
        ]);

        $this->assertIsInt($result->port);
        $this->assertIsBool(MYSQL_SSH_TUNNEL_ACTIVE);

        fclose($fakeHandle['proc']);
        @unlink($tmp);
    }
}
