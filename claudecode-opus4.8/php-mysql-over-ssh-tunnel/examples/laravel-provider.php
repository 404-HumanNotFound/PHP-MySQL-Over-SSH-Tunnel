<?php

declare(strict_types=1);

/**
 * Laravel usage — via a Service Provider's register() method.
 *
 * This library is framework-agnostic: nothing in src/ references Laravel. This
 * file is a thin ADAPTER.
 *
 * WHY register() (and not boot())? The tunnel must be up BEFORE the first DB
 * connection is resolved. Service providers' register() methods run very early,
 * before boot() and before most of the framework — and crucially before any
 * database query fires during boot. Establishing the tunnel in register() means
 * MYSQL_SSH_TUNNEL_* is defined by the time config/database.php is read for the
 * connection. (A middleware / App::before hook runs too late for anything that
 * queries the DB during application boot, e.g. session or auth drivers.)
 *
 * ---------------------------------------------------------------------------
 * 1) Copy this class to app/Providers/MysqlSshTunnelServiceProvider.php
 *    (adjust the namespace to App\Providers) and register it in
 *    bootstrap/providers.php (Laravel 11+) or config/app.php 'providers'
 *    (Laravel <= 10).
 *
 * 2) Point your DB config at the constants in config/database.php:
 *
 *        'mysql' => [
 *            'host' => env('DB_HOST', defined('MYSQL_SSH_TUNNEL_ACTIVE') && MYSQL_SSH_TUNNEL_ACTIVE
 *                ? '127.0.0.1'
 *                : 'remote.server.com'),
 *            'port' => defined('MYSQL_SSH_TUNNEL_LOCAL_PORT') ? MYSQL_SSH_TUNNEL_LOCAL_PORT : 3306,
 *            // ...
 *        ],
 * ---------------------------------------------------------------------------
 */

use HumanNotFound\MysqlSshTunnel\TunnelManager;
use Illuminate\Support\ServiceProvider;

final class MysqlSshTunnelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        TunnelManager::boot([
            'local_port'          => 3306,
            'server'              => (string) config('tunnel.server', 'remote.server.com'),
            'ssh_user'            => (string) config('tunnel.ssh_user', 'someuser'),
            'ssh_port'            => (int) config('tunnel.ssh_port', 22),
            'remote_port'         => (int) config('tunnel.remote_port', 3306),
            'ssh_binary_path'     => (string) config('tunnel.ssh_binary_path', '/usr/bin/ssh'),
            'ssh_key_path'        => config('tunnel.ssh_key_path'), // passphrase-less / agent-loaded
            'environments'        => ['local', 'development'],
            // Laravel exposes the environment via app()->environment().
            'current_environment' => $this->app->environment(),
            // Reuse Laravel's PSR-3 logger for warnings.
            'logger'              => $this->app->make(\Psr\Log\LoggerInterface::class),
        ]);
    }
}
