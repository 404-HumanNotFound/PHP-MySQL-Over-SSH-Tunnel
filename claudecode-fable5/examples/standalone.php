<?php

/**
 * Standalone / plain-PHP usage.
 *
 * Require this file (or paste its contents) at the very top of your
 * bootstrap, BEFORE any database connection is opened. After it runs, the
 * constants MYSQL_SSH_TUNNEL_LOCAL_PORT and MYSQL_SSH_TUNNEL_ACTIVE are
 * defined and you can build your PDO/mysqli connection from them.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php'; // adjust to your project layout

use HumanNotFound\MysqlSshTunnel\TunnelManager;

TunnelManager::boot([
    'local_port'          => 3307,             // or 'random'
    'server'              => 'remote.server.com',
    'ssh_user'            => 'someuser',
    'remote_port'         => 3306,             // MySQL port on the remote side
    'ssh_binary_path'     => '/usr/bin/ssh',   // `command -v ssh` to find yours
    'ssh_key_path'        => getenv('HOME') . '/.ssh/id_ed25519', // optional; must be passphrase-less or agent-loaded
    'environments'        => ['development', 'local'],
    'current_environment' => getenv('APP_ENV') ?: 'development',
]);

// Branch on MYSQL_SSH_TUNNEL_ACTIVE: when the tunnel is up connect to
// 127.0.0.1, when the library fell back connect to the remote host directly.
// MYSQL_SSH_TUNNEL_LOCAL_PORT already carries the right port either way.
$dbHost = MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.server.com';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, MYSQL_SSH_TUNNEL_LOCAL_PORT, 'my_database'),
    'db_user',
    'db_password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

var_dump($pdo->query('SELECT 1')->fetchColumn()); // "1" — connection works
