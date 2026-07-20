# php-mysql-over-ssh-tunnel

Framework-agnostic PHP library that transparently establishes (or reuses) an
SSH local-port-forward tunnel to a remote MySQL server, and exposes the local
port as a PHP constant so your application connects to `127.0.0.1:PORT`
instead of the remote host directly.

Works as a bootstrap step *before* any DB connection is opened — in
CodeIgniter 3, CodeIgniter 4, Laravel, and plain/standalone PHP.

> ⚠️ **This package is intended for local/development use against remote
> database servers. It is not recommended for production traffic.** By
> default (when the `environments` config key is omitted) the tunnel is
> allowed in **all** environments — set `environments` explicitly so the
> tunnel cannot accidentally ship to production (see
> [Environment restriction](#environment-restriction)).

## Requirements

- PHP **8.2+**
- An OpenSSH-compatible `ssh` client binary on the machine running PHP
- Key-based SSH auth (key file, `ssh-agent`, or `~/.ssh/config`) — passwords
  are **not** supported, by design
- Suggested: `ext-posix` for reliable PID liveness checks (the library
  degrades gracefully without it — see [Design decisions](#design-decisions))

## Installation

```bash
composer require 404-humannotfound/php-mysql-over-ssh-tunnel
```

## Quick start

```php
use HumanNotFound\MysqlSshTunnel\TunnelManager;

TunnelManager::boot([
    'local_port'          => 3307,                        // int, or the string 'random'
    'server'              => 'remote.server.com',
    'ssh_user'            => 'someuser',
    'remote_port'         => 3306,                        // MySQL port on the remote side
    'ssh_binary_path'     => '/usr/bin/ssh',              // required — see below
    'ssh_key_path'        => '/home/user/.ssh/id_ed25519',// optional
    'environments'        => ['development', 'local'],
    'current_environment' => getenv('APP_ENV') ?: 'production',
]);

// Now branch on the constants when building your DB connection:
$host = MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.server.com';
$pdo  = new PDO("mysql:host={$host};port=" . MYSQL_SSH_TUNNEL_LOCAL_PORT . ';dbname=mydb', $user, $pass);
```

All config keys:

| Key | Type | Required | Default | Meaning |
|---|---|---|---|---|
| `server` | string | ✅ | — | Remote SSH/MySQL server hostname (strictly validated, no shell metacharacters) |
| `ssh_user` | string | ✅ | — | SSH username (strictly validated) |
| `remote_port` | int | ✅ | — | MySQL port on the remote/server side |
| `ssh_binary_path` | string | ✅ | — | Path to the `ssh` executable ([why required](#the-ssh_binary_path-setting)) |
| `local_port` | int \| `'random'` | — | `'random'` | Local port to bind, or an OS-assigned ephemeral port |
| `ssh_key_path` | string | — | none | Path to a private key passed to `ssh -i` ([auth rules](#ssh-authentication-key-based-only)) |
| `environments` | string[] | — | allow all | Environments in which the tunnel may start |
| `current_environment` | string | — | none | Your app's current environment name |
| `strict_host_key_checking` | bool | — | `true` | Only set `false` if you understand [the risk](#host-key-checking) |
| `connect_timeout` | float (seconds) | — | `5.0` | How long to wait for the forward to come up |
| `logger` | `Psr\Log\LoggerInterface` | — | `error_log()` wrapper | Where warnings go |

## The constants: `MYSQL_SSH_TUNNEL_LOCAL_PORT` and `MYSQL_SSH_TUNNEL_ACTIVE`

After `TunnelManager::boot()` runs, two global constants exist (guarded with
`defined()` so multiple bootstrap paths in one request can't collide):

- **`MYSQL_SSH_TUNNEL_ACTIVE`** (bool) — `true` when a tunnel is up (started
  or reused), `false` when the library **fell back to a direct connection**
  (binary missing, tunnel failed, or environment not allowed).
- **`MYSQL_SSH_TUNNEL_LOCAL_PORT`** (int) — the port to connect to. When the
  tunnel is active this is the local forwarded port; **when it is not, this
  is the remote `remote_port`**.

Because the port alone isn't enough information once fallback has occurred,
**consuming code must branch on `MYSQL_SSH_TUNNEL_ACTIVE` to pick the host**:

```php
$host = MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.server.com'; // your `server` value
$port = MYSQL_SSH_TUNNEL_LOCAL_PORT;

// PDO
$pdo = new PDO("mysql:host={$host};port={$port};dbname=mydb;charset=utf8mb4", $user, $pass);

// mysqli
$mysqli = new mysqli($host, $user, $pass, 'mydb', $port);
```

If you hold the `TunnelResult` returned by `boot()`/`ensure()`, you can skip
the branch — `$result->host` and `$result->localPort` are always the correct
pair.

**Fallback is deliberate and non-fatal.** Environmental failures (missing
binary, ssh dying, forward not coming up, disallowed environment) log a
warning through the configured PSR-3 logger and fall back; they never throw
and never halt your app. Only genuine config validation errors (malformed
hostname, port out of range, `ssh_key_path` pointing at a missing file, …)
throw a `TunnelException` at boot — those are programmer errors.

## Framework usage

### CodeIgniter 3 (Hook)

Use a `pre_system` hook so the tunnel exists before the database library
loads. Full example: [`examples/ci3-hook.php`](examples/ci3-hook.php).

```php
// application/config/config.php
$config['enable_hooks'] = TRUE;

// application/config/hooks.php
$hook['pre_system'][] = [
    'class'    => 'MysqlSshTunnelHook',
    'function' => 'boot',
    'filename' => 'MysqlSshTunnelHook.php',
    'filepath' => 'hooks',
];

// application/config/database.php — pre_system runs first, so the constants exist here
$db['default']['hostname'] = MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.server.com';
$db['default']['port']     = MYSQL_SSH_TUNNEL_LOCAL_PORT;
```

### CodeIgniter 4 (Event)

Use the `pre_system` event. Full example:
[`examples/ci4-event.php`](examples/ci4-event.php).

```php
// app/Config/Events.php
use CodeIgniter\Events\Events;
use HumanNotFound\MysqlSshTunnel\TunnelManager;

Events::on('pre_system', static function (): void {
    TunnelManager::boot([
        'local_port'          => 3307,
        'server'              => 'remote.server.com',
        'ssh_user'            => 'someuser',
        'remote_port'         => 3306,
        'ssh_binary_path'     => '/usr/bin/ssh',
        'environments'        => ['development'],
        'current_environment' => ENVIRONMENT,
    ]);
});

// app/Config/Database.php
public array $default = [
    'hostname' => MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.server.com',
    'port'     => MYSQL_SSH_TUNNEL_LOCAL_PORT,
    // ...
];
```

### Laravel (service provider)

Use a service provider's **`register()`** method. Why not `App::before` or
middleware? `App::before` filters were removed in Laravel 5, and middleware
runs too late for artisan commands and queue workers — which also need the
database. `register()` runs before any provider's `boot()` and before the DB
connection is resolved, so it is the idiomatic "before the DB is touched"
hook in modern Laravel. Full example:
[`examples/laravel-provider.php`](examples/laravel-provider.php).

```php
// app/Providers/MysqlSshTunnelServiceProvider.php  (register it in
// bootstrap/providers.php on Laravel 11+, or config/app.php earlier)
public function register(): void
{
    TunnelManager::boot([
        'local_port'          => 3307,
        'server'              => env('DB_HOST_REMOTE', 'remote.server.com'),
        'ssh_user'            => env('DB_TUNNEL_SSH_USER'),
        'remote_port'         => 3306,
        'ssh_binary_path'     => '/usr/bin/ssh',
        'environments'        => ['local', 'development'],
        'current_environment' => $this->app->environment(),
        'logger'              => logger()->driver(),
    ]);
}

// config/database.php — defined() guards keep artisan tooling happy
'host' => defined('MYSQL_SSH_TUNNEL_ACTIVE') && MYSQL_SSH_TUNNEL_ACTIVE
    ? '127.0.0.1' : env('DB_HOST'),
'port' => defined('MYSQL_SSH_TUNNEL_LOCAL_PORT')
    ? MYSQL_SSH_TUNNEL_LOCAL_PORT : env('DB_PORT', 3306),
```

Don't `php artisan config:cache` a tunneled configuration into a production
build — the tunnel is a development aid.

### Standalone PHP

A plain `require` at the top of your bootstrap, before any DB connection.
Full example: [`examples/standalone.php`](examples/standalone.php).

```php
require __DIR__ . '/vendor/autoload.php';

use HumanNotFound\MysqlSshTunnel\TunnelManager;

TunnelManager::boot([ /* config as above */ ]);

$host = MYSQL_SSH_TUNNEL_ACTIVE ? '127.0.0.1' : 'remote.server.com';
$pdo  = new PDO("mysql:host={$host};port=" . MYSQL_SSH_TUNNEL_LOCAL_PORT . ';dbname=mydb', $user, $pass);
```

## The `ssh_binary_path` setting

`ssh_binary_path` is **required with no baked-in default**, because the `ssh`
binary's location varies by OS and distro:

- most Linux distros: `/usr/bin/ssh`
- some BSD-ish layouts: `/usr/sbin/ssh`
- macOS (system): `/usr/bin/ssh`; Homebrew OpenSSH: `/opt/homebrew/bin/ssh`

Find yours with:

```bash
command -v ssh    # or: which ssh
```

The *shape* of the value is validated at config time, but whether the file
actually exists and is executable is checked at runtime: a missing binary on
one developer's machine logs a warning and falls back to a direct connection
rather than crashing the app for everyone sharing the config (see
[Design decisions](#design-decisions)).

## SSH authentication: key-based only

This library **never accepts SSH passwords** in config, never reads or logs
key file contents, and never prompts for anything. The tunnel process is
spawned with `proc_open()` — there is **no interactive terminal to prompt
through** — and runs with `-o BatchMode=yes`, so ssh fails fast instead of
hanging on a prompt. Auth therefore goes through exactly what the system
`ssh` binary would normally use:

1. `ssh_key_path` config → passed to ssh as `-i /path/to/key`
2. a running `ssh-agent`
3. your `~/.ssh/config` / default identity files

> ⚠️ **Any key referenced by `ssh_key_path` must be passphrase-less or
> already unlocked/loaded into `ssh-agent`.** This library cannot and will
> not prompt for a passphrase. A locked key simply fails to authenticate,
> and the library falls back to a direct connection with a warning.

The remote host must already be in `known_hosts` (or resolvable via your ssh
config), because [host key checking](#host-key-checking) is strict by
default. SSH into the server once manually before first use.

### Host key checking

By default the tunnel uses ssh's normal strict host-key verification. You
can set `'strict_host_key_checking' => false` to pass
`-o StrictHostKeyChecking=no`, but **this exposes you to man-in-the-middle
attacks** — anyone able to intercept your traffic can impersonate the server
and capture your MySQL credentials. Only use it against throwaway
infrastructure, and prefer adding the host key properly instead.

## The `'random'` port race window

With `'local_port' => 'random'` the library binds port `0` on `127.0.0.1`,
reads back the OS-assigned ephemeral port, then **releases it immediately**
before handing it to `ssh`. Between that release and the moment the `ssh`
process binds it, another process could in principle grab the port. This
window is inherent to the approach (PHP and `ssh` are separate processes, so
the socket cannot be handed over directly) and is typically a few
milliseconds on a local machine.

If you lose the race, `ssh` exits (we pass `-o ExitOnForwardFailure=yes`),
and the library logs a warning and falls back to a direct connection — it
does not retry. Random-port tunnels are also **request-scoped**: a shutdown
function terminates the ssh process and removes the lockfile at the end of
the request, since nothing else could know the port afterwards. Fixed-port
tunnels are intentionally left running so they can be reused across requests.

## Environment restriction

The optional `environments` key is a safety rail against accidentally
leaving the tunnel wired into a production deployment:

```php
'environments'        => ['development', 'local'],
'current_environment' => getenv('APP_ENV') ?: 'production',
```

You supply `current_environment` explicitly (from `ENVIRONMENT` in
CodeIgniter, `$app->environment()` in Laravel, `getenv('APP_ENV')` standalone)
— the library deliberately does not guess framework-specific environment
detection, to stay framework-independent.

When the current environment is **not** in the list:

- No tunnel is started and no ssh process is spawned.
- A warning is logged explaining the skip (this is deliberate, not an error).
- The constants fall back to direct-connection values
  (`MYSQL_SSH_TUNNEL_ACTIVE === false`,
  `MYSQL_SSH_TUNNEL_LOCAL_PORT === remote_port`), so your app still boots
  and connects directly.

If `environments` is omitted entirely, the tunnel is allowed in **all**
environments. **This package is intended for local/development use against
remote database servers and is not recommended for production traffic** —
set `environments` explicitly in anything that could reach a production
deploy.

## How tunnel reuse works

- Each tunnel identity (server + user + remote port + fixed local port, or
  `random`) gets a lockfile in `sys_get_temp_dir()` named
  `php-mysql-ssh-tunnel-<sha256>.lock`, chmod `0600`, containing the ssh
  PID and resolved local port as JSON.
- On each boot the library takes an exclusive `flock()` on that file (so two
  PHP processes starting at the same instant can't both spawn a tunnel),
  then reuses the recorded tunnel if the PID is alive **and** the port
  accepts a TCP connection; otherwise it starts a fresh `ssh` process.
- PID liveness uses `posix_kill($pid, 0)` when `ext-posix` is available,
  falls back to checking `/proc/{pid}` on Linux, and otherwise treats
  liveness as unknown and relies on the port probe alone.

The exact protocol is documented for future maintainers in
[AGENT.md](AGENT.md).

## Testing

```bash
composer install
composer test               # unit suite — no network, no ssh, proc_open faked
```

### Optional real-world integration test

An end-to-end test (`tests/TunnelIntegrationTest.php`, group `integration`)
opens a **real** ssh tunnel and PDO connection. It is excluded from the
default run and **is not expected to run in CI** unless you provision the
environment yourself. It reads its configuration entirely from environment
variables and skips (never fails) when any is missing.

Set them locally by copying [.env.testing.example](.env.testing.example):

```bash
cp .env.testing.example .env.testing   # .env.testing is gitignored
# edit .env.testing with your test server details, then:
set -a; source .env.testing; set +a
composer test:integration
```

Use a dedicated passphrase-less test key and a dedicated low-privilege MySQL
user — never commit or reuse real credentials.

## Design decisions

Choices made where the spec allowed discretion, so they're documented rather
than implicit:

- **Namespace `HumanNotFound\MysqlSshTunnel`** — PHP namespaces cannot start
  with a digit, so the `404-` vendor prefix can't appear literally; the
  Composer package name keeps the full `404-humannotfound` vendor.
- **`psr/log` is the only runtime dependency** — it's a framework-agnostic
  interface package (not a framework), and it lets any framework's logger be
  injected via the `logger` config key. Without one, warnings go to
  `error_log()`.
- **Missing/non-executable `ssh_binary_path` falls back instead of
  throwing.** Config *shape* errors (missing key, empty value) throw, but the
  binary's presence is environmental: the same committed config can be valid
  on one machine and not another, and the package's contract is that
  environmental failures degrade to a logged warning + direct connection.
- **`local_port` defaults to `'random'`** when omitted, as the safest choice
  (no chance of colliding with a local MySQL on 3306).
- **`ssh` is invoked with individually `escapeshellarg()`-escaped arguments**,
  and `server`/`ssh_user` are additionally allow-list validated (which also
  blocks leading-`-` values that could be parsed as ssh options).
- **Random-port lockfiles share one file per target** (hash uses the literal
  string `random`), so a still-live random tunnel started earlier in the same
  request chain can be found and reused via the port recorded in the file.
- **PHPUnit 10 with attributes** (`#[Group('integration')]`,
  `#[RunInSeparateProcess]` for constant-defining tests) — annotations are on
  their way out in newer PHPUnit majors.

## License

[MIT](LICENSE) © 404-HumanNotFound
