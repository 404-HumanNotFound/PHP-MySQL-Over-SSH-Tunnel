# AGENT.md — guidance for AI coding agents working on this repository

This file is the contract for any automated agent (or human in a hurry)
modifying `404-humannotfound/php-mysql-over-ssh-tunnel`. Read it fully
before changing `src/`.

## Purpose

A small, framework-agnostic PHP library that establishes (or detects and
reuses) an SSH local-port-forward tunnel to a remote MySQL server via the
system `ssh` binary, then publishes the resolved local port as the global
constants `MYSQL_SSH_TUNNEL_LOCAL_PORT` and `MYSQL_SSH_TUNNEL_ACTIVE` so
application code can decide where to point its DB connection. It is a
*bootstrap step*, designed to run before any framework opens a database
connection.

## Non-goals — do not grow the package into these

- **Not a general SSH library.** No remote command execution, no SFTP/SCP,
  no interactive sessions, no remote (`-R`) or dynamic (`-D`) forwards.
- **Not a connection pool.** It manages one ssh process per tunnel identity;
  it does not manage, count, or multiplex database connections.
- **Not a MySQL client wrapper.** It never opens a database connection
  itself (outside the opt-in integration test) and knows nothing about SQL,
  DSNs, or drivers. Consumers build their own PDO/mysqli connections.

## Hard constraints — never violate these

1. **No shell string interpolation.** The ssh command line is built by
   escaping every argument individually with `escapeshellarg()`
   (`TunnelManager::buildCommand()`). Never concatenate or interpolate raw
   config into a command string. `server` and `ssh_user` are additionally
   allow-list validated in `TunnelConfig` (this also blocks leading-`-`
   argument injection) — keep those regexes strict.
2. **No password/passphrase SSH handling, ever.** No config key for
   passwords or passphrases (attempts to pass `ssh_password`/`ssh_passphrase`
   throw); never read, log, or transmit key file *contents* — `ssh_key_path`
   is a path handed to `ssh -i` and nothing more; never prompt interactively
   (there is no TTY; `-o BatchMode=yes` stays).
3. **No framework dependency in `src/`.** The only runtime dependency is
   `psr/log`. Framework wiring lives exclusively in `examples/` as copyable
   adapters; the core must keep working with a bare `require
   vendor/autoload.php`.
4. **PHP 8.2 minimum syntax.** `composer.json` declares `>=8.2`; do not use
   8.3+-only features (e.g. typed class constants, `#[\Override]`,
   `json_validate()`). Readonly classes/properties and enums are fine (8.1/8.2).
5. **Environmental failures degrade; only config errors throw.**
   - Missing/non-executable ssh binary at runtime, ssh process dying, the
     forward not coming up within `connect_timeout`, lockfile I/O trouble,
     current environment not in `environments` → **log a warning via the
     PSR-3 logger and return the direct-connection fallback**
     (`active=false`, port = `remote_port`, host = `server`). Never throw,
     never halt the app.
   - Config validation errors (bad hostname/username, port out of range,
     `ssh_key_path` file missing, wrong types) → throw `TunnelException`
     from `TunnelConfig::fromArray()`. Nothing else may throw it.
6. **Constants are defined once, guarded with `defined()`.** Multiple
   bootstrap paths in one request must never fatal on redefinition.
7. **Shutdown teardown only for `'random'` local ports.** Fixed-port tunnels
   are deliberately left running for reuse across requests. Do not "fix"
   that asymmetry.

## Lockfile / PID protocol (do not reinvent incompatibly)

- **Location:** `sys_get_temp_dir() . '/php-mysql-ssh-tunnel-' . <hash> . '.lock'`
- **Hash:** `sha256("{server}|{ssh_user}|{remote_port}|{local}")` where
  `{local}` is the fixed local port, or the literal string `random` when
  `local_port === 'random'` (so all random-port requests for one target
  share a lockfile; the resolved port lives inside the file).
  Implemented in `TunnelConfig::hash()`.
- **Content:** a single JSON object
  `{"pid": <int ssh PID>, "port": <int resolved local port>, "created_at": <unix ts>}`.
  Unparseable/foreign content is treated as "no lockfile".
- **Permissions:** `0600` — the file reveals a live PID and an open local
  port; keep it restrictive.
- **Concurrency:** the whole read → liveness-check → maybe-start → write
  sequence runs under `flock($fh, LOCK_EX)` on the lockfile itself. Any new
  code path that touches the lockfile must run under the same lock.
- **Liveness definition:** PID alive (`posix_kill($pid, 0)`, EPERM counts as
  alive; fallback `/proc/{pid}` on Linux; otherwise "unknown" — which does
  NOT veto reuse) **and** `127.0.0.1:port` accepts a TCP connection.
  A tunnel failing either check is stale and gets replaced.
- **Removal:** the shutdown function (random-port tunnels only) terminates
  the process and unlinks the lockfile.

If you extend the JSON payload, add keys — never rename or repurpose
`pid`/`port`, and keep readers tolerant of unknown keys.

## Testing expectations for any change

- Every behavioral change to `src/` ships with PHPUnit coverage in `tests/`.
- The default suite must stay hermetic: **no real ssh processes, no network
  beyond loopback sockets the test itself binds, no external services.** The
  ssh process is faked via `ProcessRunnerInterface` /
  `tests/Support/FakeProcessRunner.php` — inject fakes; do not shell out.
- Tests that define the global constants must run in a separate process
  (`#[RunInSeparateProcess]`) so they don't poison the shared process.
- Real-world coverage belongs only in `tests/TunnelIntegrationTest.php`
  (group `integration`, excluded by default, env-var driven,
  `markTestSkipped()` when unconfigured). Never add required env vars
  without updating `.env.testing.example` and the README.
- Run `composer test` before considering any change done.

## Coding style & tooling

- **PSR-12**, `declare(strict_types=1);` in every PHP file, PSR-4 under
  `HumanNotFound\MysqlSshTunnel\` (namespace differs from the vendor string
  because PHP namespaces cannot start with `404`).
- Full parameter/return types everywhere; `final` classes by default;
  constructor property promotion and `readonly` where applicable.
- Static analysis target: **PHPStan level 8** over `src/` (not yet wired
  into composer.json — if you add it, add it as a dev dependency and a
  `composer analyse` script; do not add runtime dependencies).
- Keep PHPUnit on attributes (not docblock annotations) for metadata, except
  the redundant `@group integration` docblock kept for grep-ability.
- Log messages: PSR-3 `{placeholder}` interpolation, warning level for all
  fallback paths; never log secrets (there are none to log — keep it that
  way).
