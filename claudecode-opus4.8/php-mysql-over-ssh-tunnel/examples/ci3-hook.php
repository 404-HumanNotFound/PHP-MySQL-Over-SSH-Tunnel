<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * CodeIgniter 3 usage — via a Hook.
 *
 * This library is framework-agnostic: nothing in src/ references CodeIgniter.
 * This file is a thin ADAPTER that wires the core TunnelManager into CI3's hook
 * lifecycle. The tunnel must be established BEFORE the database library
 * connects, so use the `pre_system` hook (earliest available).
 *
 * ---------------------------------------------------------------------------
 * 1) Enable hooks in application/config/config.php:
 *
 *        $config['enable_hooks'] = TRUE;
 *
 * 2) Register the hook in application/config/hooks.php:
 *
 *        $hook['pre_system'][] = [
 *            'class'    => '',
 *            'function' => 'mysql_ssh_tunnel_boot',
 *            'filename' => 'MysqlSshTunnelHook.php',
 *            'filepath' => 'hooks',
 *        ];
 *
 * 3) Place this file at application/hooks/MysqlSshTunnelHook.php
 *    (make sure Composer autoloading is enabled:
 *     $config['composer_autoload'] = TRUE;).
 *
 * 4) In application/config/database.php, branch on the constants:
 *
 *        $active = defined('MYSQL_SSH_TUNNEL_ACTIVE') && MYSQL_SSH_TUNNEL_ACTIVE;
 *        $db['default']['hostname'] = $active ? '127.0.0.1' : 'remote.server.com';
 *        $db['default']['port']     = defined('MYSQL_SSH_TUNNEL_LOCAL_PORT')
 *            ? MYSQL_SSH_TUNNEL_LOCAL_PORT : 3306;
 * ---------------------------------------------------------------------------
 */

use HumanNotFound\MysqlSshTunnel\TunnelManager;

if (!function_exists('mysql_ssh_tunnel_boot')) {
    function mysql_ssh_tunnel_boot(): void
    {
        TunnelManager::boot([
            'local_port'          => 3306,
            'server'              => 'remote.server.com',
            'ssh_user'            => 'someuser',
            'ssh_port'            => 22,
            'remote_port'         => 3306,
            'ssh_binary_path'     => '/usr/bin/ssh',
            'ssh_key_path'        => '/home/someuser/.ssh/id_ed25519',
            'environments'        => ['development', 'local'],
            // CI3 exposes the environment via the ENVIRONMENT constant.
            'current_environment' => defined('ENVIRONMENT') ? ENVIRONMENT : 'development',
        ]);
        // MYSQL_SSH_TUNNEL_ACTIVE / _HOST / _LOCAL_PORT are now defined for
        // database.php to read.
    }
}
