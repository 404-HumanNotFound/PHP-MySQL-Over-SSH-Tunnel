<?php

/**
 * Laravel adapter — a service provider whose register() method boots the
 * tunnel.
 *
 * Why a service provider (and why register(), not boot() or middleware)?
 * - `App::before` filters were removed in Laravel 5; middleware runs too
 *   late for artisan commands and queue workers, which also need the DB.
 * - A provider's register() phase runs before any provider's boot() and
 *   before the database connection is resolved, so the constants exist by
 *   the time config/database.php values are consumed lazily. This is the
 *   idiomatic "do something before the DB is touched" hook in modern
 *   Laravel.
 *
 * 1. Copy this file to app/Providers/MysqlSshTunnelServiceProvider.php
 *    (adjust the namespace accordingly).
 * 2. Register it in bootstrap/providers.php (Laravel 11+) or the
 *    `providers` array in config/app.php (Laravel 10 and earlier) — ABOVE
 *    any provider of your own that touches the database.
 * 3. In config/database.php:
 *
 *        'mysql' => [
 *            'host' => defined('MYSQL_SSH_TUNNEL_ACTIVE') && MYSQL_SSH_TUNNEL_ACTIVE
 *                ? '127.0.0.1'
 *                : env('DB_HOST', 'remote.server.com'),
 *            'port' => defined('MYSQL_SSH_TUNNEL_LOCAL_PORT')
 *                ? MYSQL_SSH_TUNNEL_LOCAL_PORT
 *                : env('DB_PORT', 3306),
 *            // ...
 *        ],
 *
 *    The defined() guards keep config:cache and tooling that loads the
 *    config without this provider from erroring. Do NOT `php artisan
 *    config:cache` a tunneled configuration in production — the tunnel is a
 *    local development aid (see README).
 */

namespace App\Providers;

use HumanNotFound\MysqlSshTunnel\TunnelManager;
use Illuminate\Support\ServiceProvider;

class MysqlSshTunnelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        TunnelManager::boot([
            'local_port'          => (int) env('DB_TUNNEL_LOCAL_PORT', 3307), // or 'random'
            'server'              => env('DB_TUNNEL_SERVER', 'remote.server.com'),
            'ssh_user'            => env('DB_TUNNEL_SSH_USER', 'someuser'),
            'remote_port'         => (int) env('DB_TUNNEL_REMOTE_PORT', 3306),
            'ssh_binary_path'     => env('DB_TUNNEL_SSH_BINARY', '/usr/bin/ssh'),
            'ssh_key_path'        => env('DB_TUNNEL_SSH_KEY') ?: null, // optional
            'environments'        => ['local', 'development'],
            'current_environment' => $this->app->environment(),
            'logger'              => logger()->driver(), // Laravel's PSR-3 logger
        ]);
    }
}
