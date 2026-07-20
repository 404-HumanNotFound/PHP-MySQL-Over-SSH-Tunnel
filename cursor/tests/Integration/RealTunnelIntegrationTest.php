<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests\Integration;

use HumanNotFound\MysqlSshTunnel\TunnelManager;
use HumanNotFound\MysqlSshTunnel\TunnelResult;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 *
 * Optional real SSH + MySQL check. Skipped unless all TUNNEL_TEST_* env vars
 * are set. Not part of the default `composer test` suite.
 */
final class RealTunnelIntegrationTest extends TestCase
{
    public function testRealTunnelAndPdoSelect(): void
    {
        $required = [
            'TUNNEL_TEST_SERVER',
            'TUNNEL_TEST_SSH_USER',
            'TUNNEL_TEST_SSH_KEY_PATH',
            'TUNNEL_TEST_SSH_BINARY_PATH',
            'TUNNEL_TEST_REMOTE_PORT',
            'TUNNEL_TEST_DB_USER',
            'TUNNEL_TEST_DB_PASSWORD',
            'TUNNEL_TEST_DB_NAME',
        ];

        foreach ($required as $key) {
            if (getenv($key) === false || getenv($key) === '') {
                self::markTestSkipped("Missing required env var: {$key}");
            }
        }

        $localPortOverride = getenv('TUNNEL_TEST_LOCAL_PORT') ?: 'random';
        $localPort = ctype_digit($localPortOverride) ? (int) $localPortOverride : $localPortOverride;

        TunnelManager::reset();

        $result = TunnelManager::boot([
            'local_port' => $localPort,
            'server' => (string) getenv('TUNNEL_TEST_SERVER'),
            'ssh_user' => (string) getenv('TUNNEL_TEST_SSH_USER'),
            'ssh_port' => (int) (getenv('TUNNEL_TEST_SSH_PORT') ?: 22),
            'remote_port' => (int) getenv('TUNNEL_TEST_REMOTE_PORT'),
            'ssh_binary_path' => (string) getenv('TUNNEL_TEST_SSH_BINARY_PATH'),
            'ssh_key_path' => (string) getenv('TUNNEL_TEST_SSH_KEY_PATH'),
            'environments' => ['development', 'local'],
            'current_environment' => 'development',
            'connect_timeout' => 15.0,
        ]);

        self::assertInstanceOf(TunnelResult::class, $result);
        self::assertTrue($result->active, 'Expected tunnel to be active');
        self::assertTrue(defined('MYSQL_SSH_TUNNEL_ACTIVE') && MYSQL_SSH_TUNNEL_ACTIVE === true);
        self::assertSame($result->localPort, MYSQL_SSH_TUNNEL_LOCAL_PORT);

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s',
            $result->host,
            $result->localPort,
            getenv('TUNNEL_TEST_DB_NAME')
        );

        $pdo = new \PDO($dsn, (string) getenv('TUNNEL_TEST_DB_USER'), (string) getenv('TUNNEL_TEST_DB_PASSWORD'), [
            \PDO::ATTR_TIMEOUT => 5,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $value = (int) $pdo->query('SELECT 1')->fetchColumn();
        self::assertSame(1, $value);
    }
}
