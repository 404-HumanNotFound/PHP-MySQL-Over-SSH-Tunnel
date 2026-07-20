# php-mysql-over-ssh-tunnel

A tiny, **framework-agnostic** PHP library that transparently establishes (or
reuses) an **SSH local-port-forward tunnel** to a remote MySQL server, then
exposes the local port as a global constant so your app can connect to
`127.0.0.1:PORT` instead of the remote host directly.

It is designed to run as a **bootstrap step _before_ any DB connection is
opened**, and ships with thin adapter examples for **CodeIgniter 3**,
**CodeIgniter 4**, **Laravel**, and **plain/standalone PHP**.

> ⚠️ **Intended for local/development use against remote database servers.**
> This package is **not recommended for production traffic**. Use the
> [`environments`](#environment-restriction) option to make sure it never
> activates in production. See [Non-goals](#non-goals).

---

## How it works

1. You call one bootstrap function with a small config array.
2. The library validates the config (throwing only on genuine config errors).
3. It looks for an already-running tunnel for this config (via a per-config
   lockfile in the system temp dir) and **reuses** it if the PID is alive and
   the local port is accepting connections.
4. Otherwise it spawns `ssh -N -L …` via `proc_open()` and waits briefly for the
   forward to come up.
5. It defines three global constants and returns a result object:
   - `MYSQL_SSH_TUNNEL_ACTIVE` (bool)
   - `MYSQL_SSH_TUNNEL_HOST` (string — `127.0.0.1` when tunneled)
   - `MYSQL_SSH_TUNNEL_LOCAL_PORT` (int)
6. If anything **environmental** goes wrong (bad ssh binary, tunnel timeout,
   disallowed environment) it **logs a warning and falls back to a direct
   connection** — it never throws and never halts your app.

---

## Requirements

- **PHP 8.2+** (the oldest branch still receiving security patches as of 2026).
- A working `ssh` client binary on the machine running PHP.
- Suggested: `ext-posix` for reliable PID-liveness checks (documented fallback
  when absent — see [Idempotency](#idempotency--reuse)).

## Installation

```bash
composer require 404-humannotfound/php-mysql-over-ssh-tunnel
```

---

## Minimal configuration

```php
use HumanNotFound\MysqlSshTunnel\TunnelManager;

$result = TunnelManager::boot([
    'local_port'          => 3306,                // int, or the string 'random'
    'server'              => 'remote.server.com', // remote host running sshd + MySQL
    'ssh_user'            => 'someuser',
    'ssh_port'            => 22,                   // port sshd listens on (NOT MySQL)
    'remote_port'         => 3306,                // MySQL port on the far side
    'ssh_binary_path'     => '/usr/bin/ssh',      // REQUIRED — see below
    'ssh_key_path'        => '/home/someuser/.ssh/id_ed25519', // optional
    'environments'        => ['development', 'local'],
    'current_environment' => getenv('APP_ENV') ?: 'development',
]);
```

### All config keys

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `local_port` | `int` \| `'random'` | `3306` | Local port to bind. `'random'` → OS-assigned ephemeral port. |
| `server` | `string` | — (required) | Remote host. Validated against a strict hostname/IP allow-list. |
| `ssh_user` | `string` | — (required) | Validated against `^[A-Za-z0-9._-]{1,64}$`. |
| `ssh_port` | `int` | `22` | Port **sshd** listens on. Distinct from `remote_port`. |
| `remote_port` | `int` | `3306` | **MySQL** port on the far side of the SSH connection. |
| `ssh_binary_path` | `string` | — (required) | Absolute path to the `ssh` executable. See [ssh_binary_path](#the-ssh_binary_path-option). |
| `ssh_key_path` | `string` \| `null` | `null` | Path to a **passphrase-less / agent-loaded** private key. Path only — contents never read. |
| `environments` | `string[]` \| `null` | `null` (all) | Allow-list of environments in which the tunnel may run. |
| `current_environment` | `string` \| `null` | `null` | Caller-supplied current environment name. |
| `logger` | PSR-3 logger \| `null` | `null` (`error_log`) | Any object exposing `warning()`. |
| `connect_timeout` | `int` \| `float` | `10.0` | Seconds to wait for the forward to come up. |
| `strict_host_key_checking` | `bool` | `true` | Set `false` to pass `StrictHostKeyChecking=no` (**insecure** — see below). |

### `ssh_port` vs `remote_port`

These are **two different ports** and mixing them up is a common failure:

- **`ssh_port`** is the port `sshd` listens on at `server`. On bastion/jump
  hosts this is frequently non-standard (e.g. `2222`). It is passed to ssh as
  `-p {ssh_port}`. **A library that hardcoded port 22 would silently fail
  against any such host** — this one does not.
- **`remote_port`** is the MySQL port on the far side of the SSH connection
  (usually `3306`), i.e. the right-hand side of `-L local:127.0.0.1:remote`.

---

## The constants — and how to branch on them

After `boot()`, three constants are defined (guarded against double-definition):

| Constant | When tunneled | On fallback |
|----------|---------------|-------------|
| `MYSQL_SSH_TUNNEL_ACTIVE` | `true` | `false` |
| `MYSQL_SSH_TUNNEL_HOST` | `'127.0.0.1'` | the remote `server` |
| `MYSQL_SSH_TUNNEL_LOCAL_PORT` | the local tunnel port | the remote `remote_port` |

> **You must branch on `MYSQL_SSH_TUNNEL_ACTIVE`** to choose the host. The port
> alone is not enough once a fallback has occurred, because on fallback the
> port becomes the *remote* port and the host becomes the *remote server*.

```php
$active = defined('MYSQL_SSH_TUNNEL_ACTIVE') && MYSQL_SSH_TUNNEL_ACTIVE;
$host   = $active ? '127.0.0.1' : 'remote.server.com';
$port   = defined('MYSQL_SSH_TUNNEL_LOCAL_PORT') ? MYSQL_SSH_TUNNEL_LOCAL_PORT : 3306;

$pdo = new PDO("mysql:host={$host};port={$port};dbname=app", $user, $pass);
```

The returned `$result` (a readonly `TunnelResult`) carries the **same three
pieces of information** as properties, for callers who prefer not to read
globals:

```php
$result->active; // bool
$result->host;   // string — '127.0.0.1' or the remote server
$result->port;   // int    — local tunnel port or remote port
$result->reused; // bool   — was an existing tunnel reused?
```

---

## Usage per framework

Each of these is a **thin adapter** around the same `TunnelManager::boot()`
call. Full runnable versions live in [`examples/`](examples/). The core library
never references framework code; these examples `use` the core class, not the
other way around.

### CodeIgniter 3 — via a Hook

Use the `pre_system` hook so the tunnel is up before the DB library connects.
See [`examples/ci3-hook.php`](examples/ci3-hook.php).

```php
// application/config/hooks.php
$hook['pre_system'][] = [
    'function' => 'mysql_ssh_tunnel_boot',
    'filename' => 'MysqlSshTunnelHook.php',
    'filepath' => 'hooks',
];
```

```php
// application/hooks/MysqlSshTunnelHook.php
use HumanNotFound\MysqlSshTunnel\TunnelManager;

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
        'current_environment' => defined('ENVIRONMENT') ? ENVIRONMENT : 'development',
    ]);
}
```

Then branch on the constants in `application/config/database.php`.

### CodeIgniter 4 — via an Event

Use the `pre_system` event in `app/Config/Events.php`. See
[`examples/ci4-event.php`](examples/ci4-event.php).

```php
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
        'current_environment' => defined('ENVIRONMENT') ? ENVIRONMENT : 'development',
    ]);
});
```

Then branch on the constants in `app/Config/Database.php`.

### Laravel — via a Service Provider `register()`

We use a service provider's **`register()`** (rather than an `App::before`
middleware or `boot()`) because the tunnel must exist before the **first** DB
connection is resolved — which can happen very early (session, auth) during
framework boot, before middleware runs. See
[`examples/laravel-provider.php`](examples/laravel-provider.php).

```php
use HumanNotFound\MysqlSshTunnel\TunnelManager;
use Illuminate\Support\ServiceProvider;

final class MysqlSshTunnelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        TunnelManager::boot([
            'local_port'          => 3306,
            'server'              => 'remote.server.com',
            'ssh_user'            => 'someuser',
            'ssh_port'            => 22,
            'remote_port'         => 3306,
            'ssh_binary_path'     => '/usr/bin/ssh',
            'ssh_key_path'        => storage_path('ssh/id_ed25519'),
            'environments'        => ['local', 'development'],
            'current_environment' => $this->app->environment(),
            'logger'              => $this->app->make(\Psr\Log\LoggerInterface::class),
        ]);
    }
}
```

Then point `config/database.php`'s `mysql` connection at the constants.

### Standalone PHP

Just `require` it at the top of your bootstrap, before any DB connection. See
[`examples/standalone.php`](examples/standalone.php).

---

## The `ssh_binary_path` option

`ssh_binary_path` is **required** and has no baked-in default, because the ssh
binary's location varies by OS/distro:

- Linux: usually `/usr/bin/ssh`
- Some distros / sbin layouts: `/usr/sbin/ssh`
- macOS (system): `/usr/bin/ssh`; (Homebrew): `/opt/homebrew/bin/ssh`

Find yours with:

```bash
command -v ssh   # or: which ssh
```

If the key is simply **missing from config**, that's treated as a programmer
error and throws immediately. If the key is present but the path doesn't exist
or isn't executable at runtime, that's an **environmental** problem: the library
logs a warning and falls back to a direct connection (it does **not** throw).

---

## SSH authentication (key-based only)

**This library never handles SSH passwords or key passphrases.** Authentication
must go through one of:

- the optional `ssh_key_path` (a **file path only**),
- your running **`ssh-agent`**, or
- your `~/.ssh/config` / `known_hosts`

— i.e. whatever the system `ssh` binary would normally use.

> 🔑 **Any key referenced by `ssh_key_path` must be passphrase-less _or_ already
> unlocked/loaded into `ssh-agent`.** The tunnel is spawned with `proc_open()`
> and `-o BatchMode=yes`, which has **no interactive terminal** — so the library
> cannot (and will never) prompt for a passphrase. A passphrase-protected key
> that isn't loaded in the agent will simply cause the tunnel to fail and fall
> back to a direct connection.

The key file's **contents are never read or logged** — only its path is passed
to `ssh -i`. Passwords are never accepted through config, by design.

### Host key checking

By default the library keeps ssh's **strict host key checking** (it does *not*
pass `StrictHostKeyChecking=no`). You can opt into the insecure mode with
`'strict_host_key_checking' => false`, which adds
`-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null`.

> ⚠️ Disabling strict host key checking exposes you to man-in-the-middle
> attacks. Only do this for throwaway hosts you fully trust, and prefer adding
> the host to `known_hosts` instead.

---

## Idempotency / reuse

- A stable **hash of the resolved config** (server, ssh_user, ssh_port,
  remote_port, and — for fixed ports — local_port) identifies each tunnel.
- A **lockfile** at
  `sys_get_temp_dir()/mysql-ssh-tunnel-{hash}.lock` (mode `0600`) stores the
  spawned **PID** and **local port** as JSON.
- On each boot the library reads the lockfile, checks the PID is alive
  (`posix_kill($pid, 0)`), and confirms the local port is accepting connections
  (`fsockopen`). Only if there's no live, matching tunnel does it start a new
  `ssh` process.
- The check-then-start sequence is guarded by `flock()` on the lockfile, so two
  PHP processes booting simultaneously won't both spawn a tunnel.

**Without `ext-posix`**, liveness is determined by a documented fallback:
`/proc/{pid}` on Linux, otherwise liveness is treated as *unknown* and the
library reconnects (spawning a fresh ssh; the old one, if any, is harmless and
`ExitOnForwardFailure` prevents duplicate binds).

---

## The `random` port option & its race window

Set `'local_port' => 'random'` to bind an OS-assigned ephemeral port. The
library binds to port `0`, reads back the assigned port, releases it, then hands
it to `ssh`.

> ⏱️ **Documented tradeoff:** there is an inherent, small **race window**
> between releasing the port and `ssh` binding it — another process could grab
> it in between. Because ssh is launched with `-o ExitOnForwardFailure=yes`, if
> that happens the forward fails fast and the library falls back to a direct
> connection rather than silently succeeding on the wrong port.

Random-port tunnels are **torn down on shutdown** (a
`register_shutdown_function()` calls `proc_terminate()` and removes the
lockfile). **Fixed-port tunnels are intentionally left running** so they can be
reused across requests.

---

## Environment restriction

The `environments` array restricts *when* the tunnel may activate. You supply
the current environment explicitly via `current_environment` (the library never
guesses framework-specific env detection, to stay framework-independent):

```php
'environments'        => ['development', 'local'],
'current_environment' => getenv('APP_ENV') ?: 'development',
```

If the current environment **isn't** in the allow-list:

- **No tunnel is started.**
- A warning is logged explaining the tunnel was skipped (a deliberate safety
  rail — distinct from a failure).
- The library falls back to the **direct-connection constants**
  (`MYSQL_SSH_TUNNEL_ACTIVE = false`, host = remote `server`, port =
  `remote_port`).
- **It does not throw.**

This makes it hard to accidentally leave the tunnel mechanism wired into a
production deployment.

If `environments` is **omitted entirely**, all environments are allowed —
but see the production warning at the top of this README.

---

## Throw-vs-fallback contract (important)

| Situation | Behavior |
|-----------|----------|
| Invalid hostname / username | **throws** `ConfigValidationException` |
| `local_port` out of range / invalid | **throws** |
| Missing required `ssh_binary_path` key | **throws** |
| `ssh_key_path` set but file missing | **throws** |
| `ssh_binary_path` present but not executable | warn + **fallback** |
| Tunnel times out / ssh exits immediately | warn + **fallback** |
| Current environment not allowed | warn + **fallback** |

Only genuine **config/programmer errors** throw. All **environmental** failures
degrade gracefully to a logged warning + direct connection.

---

## Integration testing (optional, local-only)

The default `composer test` runs a fast, fully-mocked suite with **no external
dependencies** — it never opens a real SSH connection.

There is also an **optional** real-world integration test (tagged
`@group integration`, excluded from the default run). It reads everything from
environment variables and **skips itself** (never fails) if any are missing.

```bash
cp .env.testing.example .env.testing
# edit .env.testing with a throwaway low-privilege test DB user + test key
set -a; source .env.testing; set +a
composer test:integration
```

Required env vars: `TUNNEL_TEST_SSH_KEY_PATH`, `TUNNEL_TEST_SSH_USER`,
`TUNNEL_TEST_SERVER`, `TUNNEL_TEST_REMOTE_PORT`, `TUNNEL_TEST_DB_USER`,
`TUNNEL_TEST_DB_PASSWORD`, `TUNNEL_TEST_DB_NAME`. Use a **dedicated,
low-privilege** MySQL user — never a real application user. **Never commit real
keys, hosts, or credentials.** This test is not expected to run in CI unless CI
provisions its own SSH host and database.

---

## Non-goals

This library is deliberately small. It is **not**:

- a general-purpose SSH library,
- a connection pool,
- a MySQL client wrapper.

It does exactly one thing: make sure an SSH tunnel to a remote MySQL server is
up (or cleanly fall back) and tell you where to connect.

---

## Design decisions / assumptions

- **Namespace** `HumanNotFound\MysqlSshTunnel` (the vendor `404-HumanNotFound`
  can't start a PHP namespace with a digit).
- **Zero runtime dependencies.** The PSR-3 logger is *duck-typed* (any object
  with a `warning()` method) rather than pulling in `psr/log`, keeping the
  "single `require`/`use`" promise.
- **`ssh_binary_path` throw-vs-fallback:** the prompt was internally
  inconsistent (one section said a non-executable binary should throw; two
  others said it must fall back). We treat only a **missing config key** as a
  throwable programmer error, and a **present-but-broken path** as an
  environmental fallback — following the "never throw on environmental failure"
  contract. See the code comment in `TunnelConfig::fromArray()`.
- An extra `MYSQL_SSH_TUNNEL_HOST` constant is provided in addition to the two
  required ones, so consumers can read the host without re-deriving it.

## License

[MIT](LICENSE) © 404-HumanNotFound
