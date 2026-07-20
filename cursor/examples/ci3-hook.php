<?php

/**
 * CodeIgniter 3 — pre_system / pre_controller Hook example.
 *
 * Register in application/config/hooks.php:
 *
 *   $hook['pre_system'][] = [
 *       'class'    => '',
 *       'function' => 'mysql_ssh_tunnel_boot',
 *       'filename' => 'mysql_ssh_tunnel_hook.php',
 *       'filepath' => 'hooks',
 *   ];
 *
 * Place this file at application/hooks/mysql_ssh_tunnel_hook.php
 * (or require it from your existing hook file). Enable hooks in
 * application/config/config.php: $config['enable_hooks'] = TRUE;
 */

declare(strict_types=1);

use HumanNotFound\MysqlSshTunnel\TunnelManager;

if (!function_exists('mysql_ssh_tunnel_boot')) {
    function mysql_ssh_tunnel_boot(): void
    {
        // Composer autoload — adjust path to your CI3 project layout.
        require_once FCPATH . '../vendor/autoload.php';

        $result = TunnelManager::boot([
            'local_port'          => 3306,
            'server'              => 'remote.server.com',
            'ssh_user'            => 'someuser',
            'ssh_port'            => 22,
            'remote_port'         => 3306,
            'ssh_binary_path'     => '/usr/bin/ssh',
            'ssh_key_path'        => '/home/user/.ssh/id_ed25519',
            'environments'        => ['development', 'local'],
            'current_environment' => ENVIRONMENT, // CI3 defines ENVIRONMENT
        ]);

        // Point database.php at $result->host / $result->localPort, or use:
        //   host: MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.server.com'
        //   port: MYSQL_SSH_TUNNEL_LOCAL_PORT
        unset($result); // constants are defined for database.php to read
    }
}
