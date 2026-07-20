# AGENT.md — notes for AI coding agents working on this repo

This file is for future AI agents (and humans) modifying this package. Read it
before changing anything in `src/`.

## Purpose

One job: as a bootstrap step, ensure an **SSH local-port-forward tunnel** to a
remote MySQL server is up (detect/reuse/start), or cleanly **fall back to a
direct connection**, and expose where to connect via global constants +a
returned `TunnelResult`. It is meant to run *before* any DB connection is
opened, across CI3 / CI4 / Laravel / standalone PHP.

## Non-goals (do not turn it into these)

- **NOT** a general-purpose SSH library.
- **NOT** a connection pool.
- **NOT** a MySQL client wrapper.

Keep the surface tiny. New features should serve the one job above.

## Hard constraints — never violate these

1. **No shell string interpolation.** Every ssh argument is assembled as a
   `list<string>` in `TunnelManager::buildSshArgv()` and escaped
   **individually** via `escapeshellarg()` in
   `NativeSystem::buildCommandLine()`. Never build the command by concatenating
   or interpolating config values into a string.
2. **No password/passphrase-based SSH.** Never accept an SSH password or key
   passphrase through config. `ssh_key_path` is a **file path only** — never
   read or print the key file's contents. Auth is key/agent/ssh-config only, and
   the tunnel runs with `-o BatchMode=yes` (no TTY to prompt through).
3. **No framework dependency in `src/`.** Nothing under `src/` may `require` or
   reference CodeIgniter / Laravel / any framework or service container. Framework
   glue lives only in `examples/` and may depend on the core, never the reverse.
4. **PHP 8.2 minimum syntax.** Target PHP 8.2 (readonly classes, named args,
   enums, first-class callables are fine). Do **not** use 8.3+-only syntax
   (typed class constants, `json_validate()`, `#[\Override]`, etc.).
5. **Throw only on config errors; degrade on environmental failures.**
   - **Throw** `ConfigValidationException` for: invalid hostname/username,
     out-of-range ports, missing required `ssh_binary_path` key, `ssh_key_path`
     set but the file doesn't exist.
   - **Never throw** for environmental failures — instead log a warning (via the
     pluggable PSR-3-ish logger, default `error_log`) and fall back to the
     direct-connection constants. Environmental = non-executable ssh binary,
     tunnel timeout / immediate ssh exit, disallowed environment.
   - The single subtlety: a **missing** `ssh_binary_path` *config key* throws,
     but a **present-but-broken** path falls back. (The prompt was internally
     inconsistent here; this repo resolves it toward the "never throw on
     environmental failure" contract. See the long comment in
     `TunnelConfig::fromArray()`.)
6. **Security defaults:** validate `server` (hostname/IP allow-list) and
   `ssh_user` (`^[A-Za-z0-9._-]{1,64}$`) before use; keep strict host key
   checking on by default (only disable when the explicit
   `strict_host_key_checking => false` flag is set); write lockfiles `0600`.

## Lockfile / PID protocol

Keep this stable so future changes stay compatible:

- **Location:** `sys_get_temp_dir()/mysql-ssh-tunnel-{identityHash}.lock`
- **`identityHash`:** first 16 hex chars of
  `sha256(server|ssh_user|ssh_port|remote_port[|local_port])`. `local_port` is
  included **only** for fixed ports (random-port tunnels are excluded so each
  gets its own identity).
- **Permissions:** `0600` (reveals active tunnel/process info).
- **Contents:** one line of JSON — `{"pid": int, "port": int, "created_at": int}`.
- **Sibling log:** `…-{identityHash}.log` captures the spawned ssh's
  stdout/stderr (so the child can outlive the PHP request without SIGPIPE).
- **Concurrency:** the same handle is `flock(LOCK_EX)`'d for the whole
  check-then-start sequence (see `Lockfile::openForLocking()` +
  `TunnelManager::ensure()`).
- **Liveness:** `posix_kill($pid, 0)` when `ext-posix` is present; otherwise
  `/proc/{pid}` on Linux; otherwise liveness is unknown → reconnect.
- **Shutdown:** only **random-port** tunnels register a
  `register_shutdown_function()` that `proc_terminate()`s and deletes the
  lockfile. Fixed-port tunnels are intentionally left running for reuse.

## Architecture / seams

- `TunnelConfig` — immutable, validated config value object (`fromArray()` does
  all validation).
- `TunnelResult` — readonly outcome DTO (`active`, `host`, `port`, `reused`).
- `TunnelManager` — orchestration: `boot()` / `ensure()` / `buildSshArgv()`.
- `SystemInterface` — the **test seam** over all OS-level operations (spawn,
  liveness, port probing, free-port allocation, shutdown registration).
  - `NativeSystem` — real `proc_open()`-backed implementation.
  - `Tests\Support\FakeSystem` — in-memory double used by the unit suite.
- `Lockfile` — lockfile path + flock + read/write/clear/delete.
- `Support\Logging` — duck-typed PSR-3 warn (falls back to `error_log`).
- `Exception\ConfigValidationException` — the one throwable type.

**When adding OS-touching behavior, add it to `SystemInterface`** so it stays
mockable — do not call `proc_open`/`fsockopen`/`posix_*` directly from
`TunnelManager`.

## Test expectations for any change

- Every change ships with PHPUnit tests. **Mock `proc_open` / sockets via
  `FakeSystem`** — the default suite must never open a real SSH connection or
  socket to an external host, and must pass with **no external dependencies**.
- Constant-related assertions run in isolated processes (`ConstantsTest` uses
  `#[RunTestsInSeparateProcesses]`) because the `MYSQL_SSH_TUNNEL_*` constants
  can only be defined once per process.
- The real integration test stays `@group integration`, env-var driven, and
  self-skipping — never add it to the default suite.
- Run: `composer test` (default) / `composer test:integration` (opt-in).

## Coding style / tooling

- **PSR-12**, `declare(strict_types=1)` in every file.
- Prefer PHPStan **level 8** (or `max`) for any static analysis added.
- Keep `src/` dependency-free at runtime (dev-only deps are fine).
