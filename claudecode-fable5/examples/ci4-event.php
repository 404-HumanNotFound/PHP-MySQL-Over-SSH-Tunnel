<?php

/**
 * CodeIgniter 4 adapter — wire the tunnel in via the pre_system Event so it
 * runs before any model/controller opens a database connection.
 *
 * Add this to app/Config/Events.php (or keep it in a separate file that
 * Events.php requires):
 *
 *     use CodeIgniter\Events\Events;
 *     Events::on('pre_system', static function () { ... body below ... });
 *
 * Then point app/Config/Database.php at the constants:
 *
 *     public array $default = [
 *         // ...
 *         'hostname' => MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.server.com',
 *         'port'     => MYSQL_SSH_TUNNEL_LOCAL_PORT,
 *     ];
 *
 * Because Config classes are instantiated lazily (after pre_system), the
 * constants exist by the time Database.php is evaluated. If you prefer not
 * to touch the Config class, you can instead set them dynamically wherever
 * you build the connection.
 */

use CodeIgniter\Events\Events;
use HumanNotFound\MysqlSshTunnel\TunnelManager;

Events::on('pre_system', static function (): void {
    TunnelManager::boot([
        'local_port'          => 3307, // or 'random'
        'server'              => 'remote.server.com',
        'ssh_user'            => 'someuser',
        'remote_port'         => 3306,
        'ssh_binary_path'     => '/usr/bin/ssh',
        'ssh_key_path'        => '/home/user/.ssh/id_ed25519', // optional
        'environments'        => ['development'],
        // ENVIRONMENT is CI4's environment constant (from .env CI_ENVIRONMENT).
        'current_environment' => ENVIRONMENT,
    ]);
});
