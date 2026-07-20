<?php
// Manual test: simulate existing tunnel (lockfile + listening port + dummy pid)
// and verify that TunnelManager::boot() reuses it and that shutdown handler
// kills the dummy pid on script exit.

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../compat/compat.php';

use PhpMySqlOverSshTunnel\TunnelManager;

// 1) start a dummy background process (sleep) and capture its PID
$cmd = "sleep 300 > /dev/null 2>&1 & echo $!";
$pid = (int)trim(shell_exec($cmd));
if ($pid <= 0) {
    echo "Failed to start dummy sleep process\n"; exit(2);
}
echo "Started dummy pid: $pid\n";

// 2) open a listening socket on localhost on an ephemeral port
$sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($sock === false) {
    echo "Failed to open listening socket: $errno $errstr\n"; posix_kill($pid, 9); exit(3);
}
$name = stream_socket_get_name($sock, false); // e.g. 127.0.0.1:12345
$parts = explode(':', $name);
$port = (int)array_pop($parts);
echo "Listening on port $port\n";

// 3) create a lockfile that matches our config hash
$config = [
    'server' => 'example.com',
    'ssh_user' => 'tester',
    'ssh_port' => 22,
    'remote_port' => 3306,
    'local_port' => $port,
    'ssh_binary_path' => '/usr/bin/ssh',
    'current_environment' => 'development',
    'environments' => ['development'],
];
$hashData = [
    'server' => $config['server'],
    'ssh_user' => $config['ssh_user'],
    'ssh_port' => $config['ssh_port'],
    'remote_port' => $config['remote_port'],
];
$hash = hash('sha256', json_encode($hashData));
$lockFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'php-mysql-ssh-tunnel-' . $hash . '.lock';

file_put_contents($lockFile, json_encode(['pid' => $pid, 'port' => $port]));
@chmod($lockFile, 0600);
echo "Wrote lockfile $lockFile with pid=$pid port=$port\n";

// 4) Run TunnelManager::boot() which should detect the lockfile and reuse
$result = TunnelManager::boot($config);

echo "TunnelManager returned: active=" . var_export($result->active, true) . " port={$result->port} host={$result->host}\n";

// 5) exit the script normally to trigger shutdown function; before exiting,
// ensure the socket remains open so isPortOpen would have succeeded earlier.

// Close listener but keep script running briefly then exit
fclose($sock);

// Allow some time for shutdown function to run after exit
echo "Exiting script to trigger shutdown\n";
exit(0);
