<?php

// Laravel service provider example (simplified). Drop into app/Providers and register.

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use PhpMySqlOverSshTunnel\TunnelManager;

class MysqlSshTunnelServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // run early in boot to ensure DB connections use tunneled port
        TunnelManager::boot([
            'local_port' => 3306,
            'server' => env('TUNNEL_SERVER'),
            'ssh_user' => env('TUNNEL_SSH_USER'),
            'ssh_port' => (int)(env('TUNNEL_SSH_PORT') ?: 22),
            'remote_port' => (int)(env('TUNNEL_REMOTE_PORT') ?: 3306),
            'ssh_binary_path' => env('TUNNEL_SSH_BINARY', '/usr/bin/ssh'),
            'current_environment' => app()->environment(),
        ]);
    }
}
