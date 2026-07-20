<?php

require __DIR__ . '/../vendor/autoload.php';

use PhpMySqlOverSshTunnel\TunnelManager;

// Example standalone bootstrap
$result = TunnelManager::boot([
    'local_port' => 'random',
    'server' => 'remote.example.com',
    'ssh_user' => 'deploy',
    'ssh_port' => 22,
    'remote_port' => 3306,
    'ssh_binary_path' => '/usr/bin/ssh',
    'current_environment' => getenv('APP_ENV') ?: 'development',
]);

echo 'Tunnel active: ' . (MYSQL_SSH_TUNNEL_ACTIVE ? 'yes' : 'no') . PHP_EOL;
echo 'Connect host: ' . ($result->active ? '127.0.0.1' : $result->host) . ':' . $result->port . PHP_EOL;
