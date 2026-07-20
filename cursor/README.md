# php-mysql-over-ssh-tunnel

Framework-agnostic PHP library that opens (or reuses) an SSH local-port-forward
to a remote MySQL server and exposes the local port so your app can connect to
`127.0.0.1:PORT` instead of the remote host directly.

**Intended for local / development use against remote database servers. Not
recommended for production traffic.** If you omit the `environments` allow-list,
the tunnel is allowed in every environment — keep that list set in real projects.

Package: `404-humannotfound/php-mysql-over-ssh-tunnel`  
PHP: **≥ 8.2** · License: **MIT**

## Installation

```bash
composer require 404-humannotfound/php-mysql-over-ssh-tunnel
```

## Minimal config

```php
use HumanNotFound\MysqlSshTunnel\TunnelManager;

$result = TunnelManager::boot([
    'local_port'          => 3306,              // or 'random'
    'server'              => 'remote.server.com',
    'ssh_user'            => 'someuser',
    'ssh_port'            => 22,                // sshd port (not MySQL)
    'remote_port'         => 3306,              // MySQL on the far side
    'ssh_binary_path'     => '/usr/bin/ssh',    // required — see below
    'ssh_key_path'        => '/home/user/.ssh/id_ed25519', // optional
    'environments'        => ['development', 'local'],
    'current_environment' => getenv('APP_ENV') ?: 'development',
]);

// Globals (defined once per process):
//   MYSQL_SSH_TUNNEL_LOCAL_PORT  (int)
//   MYSQL_SSH_TUNNEL_ACTIVE      (bool)

// Prefer $result when you need per-call accuracy:
//   $result->active     bool
//   $result->localPort  int
//   $result->host       string  (127.0.0.1 when active, server when not)
```

### Branching on the constants

```php
$host = MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.server.com';
$port = MYSQL_SSH_TUNNEL_LOCAL_PORT;

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=app', $host, $port),
    $user,
    $pass
);
```

When the tunnel is active, connect to `127.0.0.1` + the local forward port.
When it falls back (missing binary, tunnel failure, or disallowed environment),
`MYSQL_SSH_TUNNEL_ACTIVE` is `false`, `localPort`/`MYSQL_SSH_TUNNEL_LOCAL_PORT`
become the remote MySQL port, and `host` is the remote `server` hostname —
use that pair for a direct connection.

> **Note:** Global constants are defined only once per PHP process. A later
> `boot()` in the same process still returns an accurate `TunnelResult`, but
> will not redefine the constants.

---

## CodeIgniter 3 (Hook)

Enable hooks in `application/config/config.php`, then register a `pre_system`
(or `pre_controller`) hook so the tunnel exists before the DB library loads:

```php
// application/config/hooks.php
$hook['pre_system'][] = [
    'class'    => '',
    'function' => 'mysql_ssh_tunnel_boot',
    'filename' => 'mysql_ssh_tunnel_hook.php',
    'filepath' => 'hooks',
];
```

See [`examples/ci3-hook.php`](examples/ci3-hook.php). In `database.php`:

```php
'hostname' => MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.server.com',
'port'     => MYSQL_SSH_TUNNEL_LOCAL_PORT,
```

---

## CodeIgniter 4 (Event)

Listen on `pre_system` in `app/Config/Events.php` (before the Database service
resolves a connection). See [`examples/ci4-event.php`](examples/ci4-event.php).

```php
Events::on('pre_system', static function (): void {
    \HumanNotFound\MysqlSshTunnel\TunnelManager::boot([/* ... */]);
});
```

---

## Laravel (service provider)

A service provider `boot()` method is preferred over `App::before` / middleware:
providers run early for both HTTP and console, before the DB manager typically
resolves a connection from the container.

See [`examples/laravel-provider.php`](examples/laravel-provider.php). Register the
provider, then either mutate `config('database.connections.mysql.*')` from the
`TunnelResult` or branch on the constants in `config/database.php`.

---

## Standalone PHP

```php
require __DIR__ . '/vendor/autoload.php';

use HumanNotFound\MysqlSshTunnel\TunnelManager;

$result = TunnelManager::boot([/* ... */]);
// open PDO / mysqli using $result->host and $result->localPort
```

Runnable sketch: [`examples/standalone.php`](examples/standalone.php).

---

## `ssh_binary_path` (required)

There is no baked-in default: OpenSSH lives in different places (`/usr/bin/ssh`,
`/usr/sbin/ssh`, Homebrew under `/opt/homebrew/bin/ssh`, etc.). Find yours with:

```bash
command -v ssh
# or
which ssh
```

Pass that absolute path as `ssh_binary_path`. If the path is missing or not
executable at boot time, the library **logs a warning and falls back** to a
direct connection (it does not throw). Malformed config (empty path, bad
hostname, missing key *file* when `ssh_key_path` is set, etc.) **does** throw
`InvalidConfigException`.

---

## SSH authentication

Passwords are never accepted. Auth goes through:

1. Optional `ssh_key_path` (path only — the key file is never read or logged by
   this library), and/or
2. Your running `ssh-agent`, and/or
3. Your `~/.ssh/config` / `known_hosts`, i.e. whatever the system `ssh` binary
   would normally use.

**Any key referenced by `ssh_key_path` must be passphrase-less or already
unlocked / loaded into `ssh-agent`.** This library will never prompt for a
passphrase — `proc_open()` has no interactive terminal.

Default host-key policy is strict. Only set `'strict_host_key_checking' => false`
if you understand that this disables MITM protection (`StrictHostKeyChecking=no`).

---

## Random local port race

When `local_port` is `'random'`, the library binds `127.0.0.1:0`, reads the
OS-assigned port, **closes the socket**, then starts `ssh -L` on that number.
Between release and `ssh` binding, another process could claim the port. That
small race is accepted and documented rather than holding the socket open
(which would block `ssh`). Prefer a fixed free port when you need zero race
risk. Random-port tunnels register a shutdown function that terminates `ssh`
and removes the lockfile; fixed-port tunnels are left running for reuse.

---

## Environment restriction

```php
'environments'        => ['development', 'local'],
'current_environment' => getenv('APP_ENV') ?: 'development',
```

If `current_environment` is not in `environments`, the library does **not**
start a tunnel, logs a clear warning, and falls back to direct-connection
constants. This is a deliberate safety rail against accidentally shipping the
tunnel into production.

If `environments` is omitted, all environments are allowed — pair that with the
production warning at the top of this README.

---

## Fallback behavior

Environmental failures never throw:

| Situation                         | Behavior                                      |
|-----------------------------------|-----------------------------------------------|
| Missing / non-executable ssh binary | Warning + direct fallback                   |
| `ssh` exits / forward times out   | Warning + direct fallback                     |
| Disallowed environment            | Warning + direct fallback                     |
| Invalid config (hostname, ports, missing key file, …) | Throws `InvalidConfigException` |

Optional keys: `logger` (PSR-3-like `warning()`), `connect_timeout` (seconds),
`strict_host_key_checking` (bool, default `true`).

---

## Lockfiles

Per-config lockfiles live in `sys_get_temp_dir()` as
`mysql-ssh-tunnel-{sha256}.lock` (mode `0600`), holding `pid`, `port`, and
`hash`. See [AGENT.md](AGENT.md) for the full protocol.

---

## Testing

```bash
composer install
composer test                 # unit suite (mocked proc_open) — default
composer test:integration     # optional real SSH + MySQL
```

### Optional integration test

Copy [`.env.testing.example`](.env.testing.example) to `.env.testing`, fill in
values, `source` it, then run `composer test:integration`. Required variables:

- `TUNNEL_TEST_SSH_KEY_PATH`, `TUNNEL_TEST_SSH_USER`, `TUNNEL_TEST_SERVER`
- `TUNNEL_TEST_REMOTE_PORT`, `TUNNEL_TEST_SSH_BINARY_PATH`
- `TUNNEL_TEST_DB_USER`, `TUNNEL_TEST_DB_PASSWORD`, `TUNNEL_TEST_DB_NAME`
- Optional: `TUNNEL_TEST_SSH_PORT`, `TUNNEL_TEST_LOCAL_PORT`

Missing vars cause `markTestSkipped()` — never a hard failure. This suite is
local-only and is not expected to run in CI unless you provision those secrets.

You can also run the human smoke script: `php SMOKE_TEST.php` (same env vars).

---

## Design Decisions

- **Namespace / API:** `HumanNotFound\MysqlSshTunnel\TunnelManager::boot()`
  returns a readonly `TunnelResult` (`active`, `localPort`, `host`).
- **Missing ssh binary:** treated as an environmental fallback (requirement 7 /
  AGENT constraints), not a validation throw — even though the binary path is
  a required config *key*.
- **No `psr/log` hard dependency:** any object with `warning(string, array)`
  works; default is `error_log()`.
- **Suggest `ext-posix`:** liveness uses `posix_kill($pid, 0)` when available,
  else `/proc/{pid}` on Linux, else “unknown” + TCP port check.

## License

MIT — see [LICENSE](LICENSE).
