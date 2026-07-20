<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests;

use HumanNotFound\MysqlSshTunnel\TunnelConfig;
use HumanNotFound\MysqlSshTunnel\TunnelManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Optional real-world integration test — opens a REAL ssh tunnel and a REAL
 * PDO connection. Excluded from the default test run; execute it with:
 *
 *     composer test:integration
 *
 * Configuration comes entirely from environment variables (see
 * .env.testing.example) — never hardcode a personal server, key path, or
 * credential here:
 *
 *     TUNNEL_TEST_SSH_KEY_PATH   path to a passphrase-less (or agent-loaded) test key
 *     TUNNEL_TEST_SSH_USER       ssh username on the remote server
 *     TUNNEL_TEST_SERVER         remote server hostname
 *     TUNNEL_TEST_REMOTE_PORT    MySQL port on the remote side (usually 3306)
 *     TUNNEL_TEST_DB_USER        dedicated, low-privilege test MySQL user
 *     TUNNEL_TEST_DB_PASSWORD    its password
 *     TUNNEL_TEST_DB_NAME        a database that user can SELECT from
 *
 * If any variable is missing the test is skipped with a message naming it —
 * it never fails for a developer who hasn't set up integration testing.
 *
 * @group integration
 */
#[Group('integration')]
final class TunnelIntegrationTest extends TestCase
{
    private const REQUIRED_ENV = [
        'TUNNEL_TEST_SSH_KEY_PATH',
        'TUNNEL_TEST_SSH_USER',
        'TUNNEL_TEST_SERVER',
        'TUNNEL_TEST_REMOTE_PORT',
        'TUNNEL_TEST_DB_USER',
        'TUNNEL_TEST_DB_PASSWORD',
        'TUNNEL_TEST_DB_NAME',
    ];

    /** @var array<string, string> */
    private array $env = [];

    private ?TunnelManager $manager = null;

    protected function setUp(): void
    {
        foreach (self::REQUIRED_ENV as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                self::markTestSkipped(sprintf(
                    'Integration test skipped: environment variable %s is not set. '
                    . 'See .env.testing.example for how to configure the integration suite locally.',
                    $name
                ));
            }
            $this->env[$name] = $value;
        }

        if (!extension_loaded('pdo_mysql')) {
            self::markTestSkipped('Integration test skipped: ext-pdo_mysql is not loaded.');
        }
    }

    protected function tearDown(): void
    {
        // Explicit teardown as a belt-and-braces alongside the library's own
        // shutdown function (which also fires, harmlessly, at process exit):
        // remove the lockfile so repeated runs start clean.
        if ($this->manager !== null) {
            @unlink($this->manager->lockfilePath());
        }
    }

    public function testRealTunnelEndToEnd(): void
    {
        $sshBinary = trim((string) shell_exec('command -v ssh'));
        if ($sshBinary === '') {
            self::markTestSkipped('Integration test skipped: no `ssh` binary found on PATH.');
        }

        $config = TunnelConfig::fromArray([
            'local_port'      => 'random', // random => the shutdown function closes the tunnel
            'server'          => $this->env['TUNNEL_TEST_SERVER'],
            'ssh_user'        => $this->env['TUNNEL_TEST_SSH_USER'],
            'remote_port'     => (int) $this->env['TUNNEL_TEST_REMOTE_PORT'],
            'ssh_binary_path' => $sshBinary,
            'ssh_key_path'    => $this->env['TUNNEL_TEST_SSH_KEY_PATH'],
            'connect_timeout' => 15.0,
        ]);

        $this->manager = new TunnelManager($config);
        $result = $this->manager->ensure();
        $this->manager->defineConstants($result);

        self::assertTrue(
            MYSQL_SSH_TUNNEL_ACTIVE,
            'Tunnel did not come up — check ssh connectivity and that the key is passphrase-less or agent-loaded.'
        );
        self::assertSame($result->localPort, MYSQL_SSH_TUNNEL_LOCAL_PORT);

        $pdo = new \PDO(
            sprintf(
                'mysql:host=127.0.0.1;port=%d;dbname=%s',
                MYSQL_SSH_TUNNEL_LOCAL_PORT,
                $this->env['TUNNEL_TEST_DB_NAME']
            ),
            $this->env['TUNNEL_TEST_DB_USER'],
            $this->env['TUNNEL_TEST_DB_PASSWORD'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 10]
        );

        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
    }
}
