<?php

// CodeIgniter 4 pre_system event example
require __DIR__ . '/../../vendor/autoload.php';

use PhpMySqlOverSshTunnel\TunnelManager;

TunnelManager::boot([
    'local_port' => 3306,
    'server' => 'remote.example.com',
    'ssh_user' => 'deploy',
    'ssh_port' => 22,
    'remote_port' => 3306,
    'ssh_binary_path' => '/usr/bin/ssh',
    'current_environment' => getenv('CI_ENVIRONMENT') ?: getenv('APP_ENV') ?: 'development',
]);
