# php-mysql-over-ssh-tunnel

A small, framework-agnostic PHP library that establishes or reuses an SSH local-port-forward to a remote MySQL server and exposes the local port as a PHP constant so applications can connect to 127.0.0.1:PORT.

Requirements
- PHP >= 8.2

Installation

Install via Composer (example):

composer require 404-humannotfound/php-mysql-over-ssh-tunnel

Minimal usage

use PhpMySqlOverSshTunnel\TunnelManager;

$result = TunnelManager::boot([
    'local_port' => 'random',
    'server' => 'remote.example.com',
    'ssh_user' => 'deploy',
    'ssh_port' => 22,
    'remote_port' => 3306,
    'ssh_binary_path' => '/usr/bin/ssh',
    'current_environment' => getenv('APP_ENV') ?: 'development',
]);

// Constants available:
// MYSQL_SSH_TUNNEL_LOCAL_PORT - int
// MYSQL_SSH_TUNNEL_ACTIVE - bool

Connecting PDO example

$host = MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.example.com';
$port = MYSQL_SSH_TUNNEL_LOCAL_PORT;
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=mydb', $host, $port), 'dbuser', 'dbpass');

SSH binary notes

Provide the exact path to your ssh binary via `ssh_binary_path`. Find it with `which ssh` or `command -v ssh`. The package validates that the path exists and is executable.

SSH auth

Only key-based auth is supported (ssh-agent or `ssh_key_path`). Keys must either be passphrase-less or unlocked in your ssh-agent; this library will not prompt for passphrases.

Random port race-condition

If you request `local_port => 'random'`, the library binds an ephemeral port and releases it before spawning ssh. There is a small race window where another process could take that port; consumers should be aware. This tradeoff is documented here and in code comments.

Ownership & shutdown behavior

This library only attempts to terminate (kill) SSH tunnel processes that it itself started and is tracking. If the manager detects and reuses an existing listener or an externally created tunnel (for example, a pre-existing ssh -L you started manually), it will not terminate that process on shutdown. This is deliberate and safer than unconditionally killing PIDs the library did not create.

How the library decides whether to shut down a tunnel:
- Tunnels started by this process (ephemeral/random ports or when `temporary => true`) are tracked and will be terminated on PHP shutdown.
- Persistent, fixed-port tunnels are intended to be reusable across requests and are not torn down automatically.
- If a lockfile exists and indicates a PID owned by the current user that the package recognizes, the manager may reuse it; it will only remove/terminate lockfile-owned processes that it created. It will not kill unrelated system processes.

If you need a tunnel that is guaranteed to be removed when your script exits, set `temporary => true` or use `local_port => 'random'` so the manager creates and tracks an ephemeral tunnel for your process.

Environments

Provide `environments` and `current_environment` to restrict where the tunnel may be created. When the current environment is not allowed the library will skip creating a tunnel and fall back to a direct connection (constants defined accordingly). If `environments` is omitted, all environments are allowed — but avoid enabling this in production.

Framework adapters

- CodeIgniter 3: use the provided examples/ci3-hook.php as a pre_system hook.
- CodeIgniter 4: use the examples/ci4-event.php as a pre_system hook.
- Laravel: use examples/laravel-provider.php (ServiceProvider) to boot early.
- Standalone: require the example standalone.php and call TunnelManager::boot() early in your bootstrap.

Testing

A PHPUnit test suite is included that mocks process launching to avoid real SSH connections. An optional integration test exists (excluded by default) which uses environment variables to run a live SSH+MySQL check — it is skipped unless env vars are present.

See AGENT.md for notes for future contributors and automated agents.
