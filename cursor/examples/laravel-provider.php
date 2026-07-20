<?php

/**
 * Laravel — service provider boot() example.
 *
 * Preferred over App::before: service providers run early in the HTTP and
 * console kernels, before DB connections are typically resolved from the
 * container. Register this provider in config/app.php (Laravel ≤10) or
 * bootstrap/providers.php (Laravel 11+).
 *
 *   composer require 404-humannotfound/php-mysql-over-ssh-tunnel
 *
 * Copy/adapt into app/Providers/MysqlSshTunnelServiceProvider.php
 */

declare(strict_types=1);

namespace App\Providers;

use HumanNotFound\MysqlSshTunnel\TunnelManager;
use Illuminate\Support\ServiceProvider;

class MysqlSshTunnelServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $result = TunnelManager::boot([
            'local_port'          => env('SSH_TUNNEL_LOCAL_PORT', 'random'),
            'server'              => env('SSH_TUNNEL_SERVER'),
            'ssh_user'            => env('SSH_TUNNEL_USER'),
            'ssh_port'            => (int) env('SSH_TUNNEL_SSH_PORT', 22),
            'remote_port'         => (int) env('SSH_TUNNEL_REMOTE_PORT', 3306),
            'ssh_binary_path'     => env('SSH_TUNNEL_SSH_BINARY', '/usr/bin/ssh'),
            'ssh_key_path'        => env('SSH_TUNNEL_KEY_PATH'),
            'environments'        => ['local', 'development'],
            'current_environment' => $this->app->environment(),
        ]);

        /*
         * config/database.php (mysql connection):
         *
         *   'host' => env('DB_HOST', MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : env('SSH_TUNNEL_SERVER')),
         *   'port' => env('DB_PORT', (string) MYSQL_SSH_TUNNEL_LOCAL_PORT),
         *
         * Or set DB_HOST / DB_PORT from $result before the DB manager resolves:
         */
        if ($result->active) {
            config([
                'database.connections.mysql.host' => $result->host,
                'database.connections.mysql.port' => (string) $result->localPort,
            ]);
        }
    }
}
