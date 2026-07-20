<?php

// CodeIgniter 3 pre_system hook example
// Place in application/hooks/ and add to $config['hooks'] in CI3

require __DIR__ . '/../../vendor/autoload.php';

use PhpMySqlOverSshTunnel\TunnelManager;

TunnelManager::boot([
    'local_port' => 3306,
    'server' => 'remote.example.com',
    'ssh_user' => 'deploy',
    'ssh_port' => 22,
    'remote_port' => 3306,
    'ssh_binary_path' => '/usr/bin/ssh',
    'current_environment' => defined('ENV') ? ENV : getenv('APP_ENV') ?: 'development',
]);
