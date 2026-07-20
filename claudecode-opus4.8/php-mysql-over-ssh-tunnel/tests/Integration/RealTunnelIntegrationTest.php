<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests\Integration;

use HumanNotFound\MysqlSshTunnel\TunnelManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * OPTIONAL, LOCAL-ONLY real-world integration test.
 *
 * Excluded from the default suite (tagged @group integration). It opens a REAL
 * ssh tunnel and a REAL PDO connection, driven entirely by environment
 * variables — nothing is hardcoded. If any required variable is missing the
 * test is skipped (never failed), so a developer who hasn't set integration
 * testing up is never blocked.
 *
 * Run it with:  composer test:integration
 * See .env.testing.example and the README "Integration testing" section.
 *
 * This test is NOT expected to run in CI unless the CI provisions its own SSH
 * host + throwaway low-privilege MySQL user.
 */
#[Group('integration')]
final class RealTunnelIntegrationTest extends TestCase
{
    /** @var array<string, string> */
    private array $env = [];

    protected function setUp(): void
    {
        foreach ([
            'TUNNEL_TEST_SSH_KEY_PATH',
            'TUNNEL_TEST_SSH_USER',
            'TUNNEL_TEST_SERVER',
            'TUNNEL_TEST_REMOTE_PORT',
            'TUNNEL_TEST_DB_USER',
            'TUNNEL_TEST_DB_PASSWORD',
            'TUNNEL_TEST_DB_NAME',
        ] as $var) {
            $value = getenv($var);
            if ($value === false || $value === '') {
                $this->markTestSkipped(sprintf(
                    'Integration test skipped: environment variable %s is not set. '
                    . 'Copy .env.testing.example and export the values to run this test.',
                    $var
                ));
            }
            $this->env[$var] = $value;
        }

        if (!extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('Integration test skipped: ext-pdo_mysql is not loaded.');
        }
    }

    public function testOpensRealTunnelAndRunsSelectOne(): void
    {
        $sshBinary = getenv('TUNNEL_TEST_SSH_BINARY') ?: (trim((string) shell_exec('command -v ssh')) ?: '/usr/bin/ssh');

        $result = TunnelManager::boot([
            'local_port' => 'random', // random => auto teardown on shutdown
            'server' => $this->env['TUNNEL_TEST_SERVER'],
            'ssh_user' => $this->env['TUNNEL_TEST_SSH_USER'],
            'ssh_port' => (int) (getenv('TUNNEL_TEST_SSH_PORT') ?: 22),
            'remote_port' => (int) $this->env['TUNNEL_TEST_REMOTE_PORT'],
            'ssh_binary_path' => $sshBinary,
            'ssh_key_path' => $this->env['TUNNEL_TEST_SSH_KEY_PATH'],
            'connect_timeout' => 15.0,
        ]);

        self::assertTrue($result->active, 'Tunnel should be active for the integration test.');
        self::assertTrue(MYSQL_SSH_TUNNEL_ACTIVE);
        self::assertSame('127.0.0.1', MYSQL_SSH_TUNNEL_HOST);

        $dsn = sprintf(
            'mysql:host=127.0.0.1;port=%d;dbname=%s',
            MYSQL_SSH_TUNNEL_LOCAL_PORT,
            $this->env['TUNNEL_TEST_DB_NAME']
        );

        $pdo = new \PDO(
            $dsn,
            $this->env['TUNNEL_TEST_DB_USER'],
            $this->env['TUNNEL_TEST_DB_PASSWORD'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $value = $pdo->query('SELECT 1')->fetchColumn();
        self::assertSame(1, (int) $value);

        // The random-port shutdown function will close the tunnel when the
        // process ends; no explicit teardown is required.
    }
}
