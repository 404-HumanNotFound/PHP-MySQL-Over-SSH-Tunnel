<?php

/**
 * CodeIgniter 3 adapter — wire the tunnel in via a pre_system Hook so it
 * runs before the database library loads.
 *
 * 1. Copy this file to application/hooks/MysqlSshTunnelHook.php
 * 2. Enable hooks in application/config/config.php:
 *        $config['enable_hooks'] = TRUE;
 * 3. Register the hook in application/config/hooks.php:
 *
 *        $hook['pre_system'][] = [
 *            'class'    => 'MysqlSshTunnelHook',
 *            'function' => 'boot',
 *            'filename' => 'MysqlSshTunnelHook.php',
 *            'filepath' => 'hooks',
 *        ];
 *
 * 4. Point application/config/database.php at the constants:
 *
 *        $db['default']['hostname'] = MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.server.com';
 *        $db['default']['port']     = MYSQL_SSH_TUNNEL_LOCAL_PORT;
 *
 *    (pre_system fires before database.php is loaded, so the constants are
 *    available there.)
 *
 * CI3 predates Composer-first workflows; if your CI3 app does not already
 * load vendor/autoload.php, set $config['composer_autoload'] in config.php
 * or require it in index.php.
 */

defined('BASEPATH') or exit('No direct script access allowed');

use HumanNotFound\MysqlSshTunnel\TunnelException;
use HumanNotFound\MysqlSshTunnel\TunnelManager;

class MysqlSshTunnelHook
{
    public function boot(): void
    {
        try {
            TunnelManager::boot([
                'local_port'          => 3307, // or 'random'
                'server'              => 'remote.server.com',
                'ssh_user'            => 'someuser',
                'remote_port'         => 3306,
                'ssh_binary_path'     => '/usr/bin/ssh',
                'ssh_key_path'        => '/home/user/.ssh/id_ed25519', // optional
                'environments'        => ['development'],
                // CI3's ENVIRONMENT constant is defined in index.php.
                'current_environment' => defined('ENVIRONMENT') ? ENVIRONMENT : 'production',
            ]);
        } catch (TunnelException $e) {
            // Config errors are programmer errors — fail loudly at boot.
            log_message('error', 'MySQL SSH tunnel config error: ' . $e->getMessage());
            throw $e;
        }
    }
}
