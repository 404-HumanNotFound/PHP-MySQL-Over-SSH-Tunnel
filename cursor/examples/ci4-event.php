<?php

/**
 * CodeIgniter 4 — Events (pre_system) example.
 *
 * In app/Config/Events.php, inside the Events class init, add:
 *
 *   Events::on('pre_system', static function (): void {
 *       require APPPATH . 'ThirdParty/mysql_ssh_tunnel_event.php';
 *       // or simply paste the boot call below
 *   });
 *
 * Or require this file from Events.php after the Composer autoloader is ready.
 */

declare(strict_types=1);

use CodeIgniter\Events\Events;
use HumanNotFound\MysqlSshTunnel\TunnelManager;

Events::on('pre_system', static function (): void {
    TunnelManager::boot([
        'local_port'          => 'random',
        'server'              => env('SSH_TUNNEL_SERVER', 'remote.server.com'),
        'ssh_user'            => env('SSH_TUNNEL_USER', 'someuser'),
        'ssh_port'            => (int) env('SSH_TUNNEL_SSH_PORT', 22),
        'remote_port'         => (int) env('SSH_TUNNEL_REMOTE_PORT', 3306),
        'ssh_binary_path'     => env('SSH_TUNNEL_SSH_BINARY', '/usr/bin/ssh'),
        'ssh_key_path'        => env('SSH_TUNNEL_KEY_PATH'),
        'environments'        => ['development', 'local'],
        'current_environment' => env('CI_ENVIRONMENT', 'production'),
    ]);
});

/*
 * In app/Config/Database.php, branch on the constants:
 *
 *   'hostname' => MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.server.com',
 *   'port'     => MYSQL_SSH_TUNNEL_LOCAL_PORT,
 */
