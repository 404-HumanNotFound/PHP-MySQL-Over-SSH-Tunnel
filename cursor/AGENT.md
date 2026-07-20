# AGENT.md — guidance for AI coding agents

## Purpose

`404-humannotfound/php-mysql-over-ssh-tunnel` establishes (or reuses) an SSH
**local port forward** to a remote MySQL instance and exposes the resolved
local port to PHP applications via `TunnelManager::boot()` and the globals
`MYSQL_SSH_TUNNEL_LOCAL_PORT` / `MYSQL_SSH_TUNNEL_ACTIVE`.

## Non-goals

Do **not** turn this package into:

- A general-purpose SSH client or multiplexing library
- A database connection pool or MySQL client wrapper
- A framework-specific package with hard dependencies on Laravel / CI / etc.

Framework wiring belongs only under `examples/`. Nothing in `src/` may
`require`, `use`, or type-hint framework classes.

## Hard constraints (never violate)

1. **No shell string interpolation for argv.** Build an argument array and pass
   each element through `escapeshellarg()` (see `ProcOpenRunner`). Never
   concatenate user config into one unsafe shell string.
2. **No password or passphrase handling.** Never accept SSH passwords, never
   read or log private key contents, never prompt for a passphrase. Document
   that `ssh_key_path` keys must be passphrase-less or agent-unlocked.
3. **No framework code in `src/`.**
4. **PHP 8.2 minimum** — avoid 8.3+-only syntax.
5. **Environmental failures must not throw.** Missing/non-executable
   `ssh_binary_path`, tunnel timeout / immediate ssh exit, and disallowed
   `environments` → log a warning + direct-connection fallback
   (`TunnelResult` with `active=false`, host=`server`, port=`remote_port`).
   Only **config validation** errors throw `InvalidConfigException`.
6. **Default StrictHostKeyChecking on.** Only disable when
   `strict_host_key_checking` is explicitly `false`.
7. **Lockfiles mode `0600`.**

## Public API

```php
HumanNotFound\MysqlSshTunnel\TunnelManager::boot(array $config): TunnelResult
```

`TunnelResult` readonly properties: `bool $active`, `int $localPort`, `string $host`.

Test seams: `setProcessRunner()`, `setLockfileStore()`, `reset()`,
`shutdownRegistrationCount()`.

## Lockfile / PID protocol

| Item | Value |
|------|--------|
| Path | `{sys_get_temp_dir()}/mysql-ssh-tunnel-{sha256}.lock` |
| Hash inputs | `server`, `ssh_user`, `remote_port`, `ssh_port`; plus `local_port` when not `'random'` |
| Body | line-oriented `pid=`, `port=`, `hash=` |
| Permissions | `0600` |
| Coordination | `fopen('c+')` + `flock(LOCK_EX)` around check-then-start |
| Stale handling | truncate in place while handle is open — **do not unlink** under an open flock'd fd (orphans the inode) |
| Liveness | `posix_kill($pid, 0)` if available; else `/proc/{pid}` on Linux; else unknown → rely on `fsockopen` to local port |
| Reuse | live PID (or unknown) **and** local port accepting TCP → reuse, do not spawn |
| Shutdown | `register_shutdown_function` **only** when `local_port === 'random'`: `proc_terminate` + unlink lockfile |

## SSH invocation shape

```
{ssh_binary_path} -p {ssh_port} -N
  -L {local_port}:127.0.0.1:{remote_port}
  -o BatchMode=yes -o ExitOnForwardFailure=yes
  [-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null]  # opt-in only
  [-i {ssh_key_path}]
  {ssh_user}@{server}
```

## Tests

- Default suite (`composer test`): PHPUnit unit tests with a fake
  `ProcessRunnerInterface` — **never** open a real SSH connection.
- Cover: config validation, lockfile live vs stale, random port allocation,
  shutdown registration for random ports, environment gating, fallback paths.
- Integration (`composer test:integration`, `@group integration`): env-driven,
  `markTestSkipped()` when vars missing. Do not hardcode credentials.
- Any behavior change in tunnel start/reuse/fallback must update unit tests.

## Coding standards

- PSR-12 code style
- `declare(strict_types=1);` in every PHP file
- Target **PHPStan level 8** if/when static analysis is added (not required to
  vendor phpstan in this package unless you choose to)
- Prefer `final` classes and readonly DTOs for public value objects
- Keep the surface small; new features should justify themselves against the
  non-goals above
