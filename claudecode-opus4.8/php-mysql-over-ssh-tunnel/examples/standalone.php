<?php

declare(strict_types=1);

/**
 * Standalone / plain-PHP usage.
 *
 * Put a `require` like this at the very top of your bootstrap file — BEFORE any
 * database connection is opened. After it runs, the MYSQL_SSH_TUNNEL_* constants
 * are defined and you branch on MYSQL_SSH_TUNNEL_ACTIVE to pick the DB host.
 */

require __DIR__ . '/../vendor/autoload.php';

use HumanNotFound\MysqlSshTunnel\TunnelManager;

$result = TunnelManager::boot([
    'local_port'          => 3306,               // int, or 'random'
    'server'              => 'remote.server.com',
    'ssh_user'            => 'someuser',
    'ssh_port'            => 22,                  // sshd port (NOT the MySQL port)
    'remote_port'         => 3306,               // MySQL port on the far side
    'ssh_binary_path'     => '/usr/bin/ssh',     // run `command -v ssh` to find yours
    'ssh_key_path'        => '/home/someuser/.ssh/id_ed25519', // passphrase-less / agent-loaded
    'environments'        => ['development', 'local'],
    'current_environment' => getenv('APP_ENV') ?: 'development',
    // 'connect_timeout'         => 10.0,
    // 'strict_host_key_checking'=> true,
    // 'logger'                  => $psr3Logger,
]);

// Either read the returned result object...
$host = $result->host;   // '127.0.0.1' when tunneled, remote server on fallback
$port = $result->port;   // local tunnel port, or remote port on fallback

// ...or read the global constants (identical information):
//   MYSQL_SSH_TUNNEL_ACTIVE      (bool)
//   MYSQL_SSH_TUNNEL_HOST        (string)
//   MYSQL_SSH_TUNNEL_LOCAL_PORT  (int)

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, 'my_database'),
    'db_user',
    'db_password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

printf(
    "Tunnel active: %s — connecting to %s:%d\n",
    $result->active ? 'yes' : 'no (direct/fallback)',
    $host,
    $port,
);

$stmt = $pdo->query('SELECT 1');
var_dump($stmt->fetchColumn());
