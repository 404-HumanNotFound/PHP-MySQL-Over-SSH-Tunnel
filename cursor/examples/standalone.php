<?php

/**
 * Standalone PHP — call TunnelManager::boot() before opening any DB handle.
 *
 *   php examples/standalone.php
 *
 * Adjust config paths for your machine. This example uses a random local
 * port and prints the resolved host/port (it does not open a real SSH
 * connection unless you fill in valid credentials and paths).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use HumanNotFound\MysqlSshTunnel\TunnelManager;

$result = TunnelManager::boot([
    'local_port'          => 'random',
    'server'              => getenv('TUNNEL_TEST_SERVER') ?: 'remote.server.com',
    'ssh_user'            => getenv('TUNNEL_TEST_SSH_USER') ?: 'someuser',
    'ssh_port'            => (int) (getenv('TUNNEL_TEST_SSH_PORT') ?: 22),
    'remote_port'         => (int) (getenv('TUNNEL_TEST_REMOTE_PORT') ?: 3306),
    'ssh_binary_path'     => getenv('TUNNEL_TEST_SSH_BINARY_PATH') ?: '/usr/bin/ssh',
    'ssh_key_path'        => getenv('TUNNEL_TEST_SSH_KEY_PATH') ?: null,
    'environments'        => ['development', 'local'],
    'current_environment' => getenv('APP_ENV') ?: 'development',
]);

echo "Tunnel active: " . ($result->active ? 'yes' : 'no') . PHP_EOL;
echo "Connect to:    {$result->host}:{$result->localPort}" . PHP_EOL;
echo "Constant port: " . MYSQL_SSH_TUNNEL_LOCAL_PORT . PHP_EOL;
echo "Constant on:   " . (MYSQL_SSH_TUNNEL_ACTIVE ? 'true' : 'false') . PHP_EOL;

$host = MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : $result->host;
$port = MYSQL_SSH_TUNNEL_LOCAL_PORT;

// Example PDO (only runs when DB env vars are set):
$dbUser = getenv('TUNNEL_TEST_DB_USER');
$dbPass = getenv('TUNNEL_TEST_DB_PASSWORD');
$dbName = getenv('TUNNEL_TEST_DB_NAME');

if ($dbUser && $dbName && $result->active) {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s', $host, $port, $dbName),
        $dbUser,
        $dbPass ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo 'SELECT 1 => ' . $pdo->query('SELECT 1')->fetchColumn() . PHP_EOL;
}
