<?php

declare(strict_types=1);

/**
 * CodeIgniter 4 usage — via an Event.
 *
 * This library is framework-agnostic: nothing in src/ references CodeIgniter.
 * This file is a thin ADAPTER showing how to fire the core TunnelManager from
 * CI4's `pre_system` event, which runs before the database is used.
 *
 * ---------------------------------------------------------------------------
 * Add the following to app/Config/Events.php (inside the file, using CI4's
 * Events class). The core library is autoloaded via Composer, which CI4 already
 * bootstraps.
 * ---------------------------------------------------------------------------
 */

use CodeIgniter\Events\Events;
use HumanNotFound\MysqlSshTunnel\TunnelManager;

Events::on('pre_system', static function (): void {
    TunnelManager::boot([
        'local_port'          => 3306,
        'server'              => 'remote.server.com',
        'ssh_user'            => 'someuser',
        'ssh_port'            => 22,
        'remote_port'         => 3306,
        'ssh_binary_path'     => '/usr/bin/ssh',
        'ssh_key_path'        => '/home/someuser/.ssh/id_ed25519',
        'environments'        => ['development', 'local'],
        // CI4 exposes the environment via ENVIRONMENT / $_SERVER['CI_ENVIRONMENT'].
        'current_environment' => defined('ENVIRONMENT') ? ENVIRONMENT : 'development',
    ]);
});

/**
 * Then, in app/Config/Database.php, branch on the constants when building the
 * default group, e.g.:
 *
 *     $active = defined('MYSQL_SSH_TUNNEL_ACTIVE') && MYSQL_SSH_TUNNEL_ACTIVE;
 *
 *     public array $default = [
 *         'hostname' => '127.0.0.1',   // overwritten below
 *         'port'     => 3306,
 *         // ...
 *     ];
 *
 *     public function __construct()
 *     {
 *         parent::__construct();
 *         $active = defined('MYSQL_SSH_TUNNEL_ACTIVE') && MYSQL_SSH_TUNNEL_ACTIVE;
 *         $this->default['hostname'] = $active ? '127.0.0.1' : 'remote.server.com';
 *         $this->default['port']     = defined('MYSQL_SSH_TUNNEL_LOCAL_PORT')
 *             ? MYSQL_SSH_TUNNEL_LOCAL_PORT : 3306;
 *     }
 */
