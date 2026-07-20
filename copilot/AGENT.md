# AGENT.md

Purpose

This repository provides a focused helper to ensure PHP applications can transparently connect to remote MySQL servers via an SSH local-port-forward tunnel. It is not a general SSH library, not a connection pool, and not a MySQL client wrapper.

Hard constraints (must not be violated)

- No shell string interpolation: every argument passed to `ssh` must be provided as an argv array and escaped by the OS. Do not build commands with concatenation.
- No password/passphrase handling: only key-based SSH auth via `ssh_key_path` or ssh-agent. Never prompt or accept a passphrase.
- Framework independence: src/ must not depend on framework classes.
- PHP 8.2 minimum: avoid syntax only available in 8.3+.
- Environmental failures (missing SSH binary, tunnel failure, disallowed environment) must degrade to a logged warning + direct-connection fallback rather than throwing. Only configuration validation errors may throw.

Lockfile/PID protocol

- Lockfiles live in `sys_get_temp_dir()` and are named `php-mysql-ssh-tunnel-{hash}.lock` where `{hash}` is sha256 of stable config values (server, ssh_user, ssh_port, remote_port, local_port when not random).
- Lockfile content is a JSON object: `{ "pid": <int|null>, "port": <int> }`.
- Files should be written with restrictive permissions (0600).
- On boot: attempt to exclusively flock() the lockfile, read it, validate PID liveness (posix_kill or /proc), verify port acceptance with fsockopen. If not live, start a new ssh with proc_open.

Tests expectations

- Use PHPUnit. Tests must mock proc_open via providing a process runner callable to TunnelManager so tests do not open real SSH connections.
- Tests should cover config validation, lockfile detection, ephemeral port allocation, and shutdown registration behavior.

Coding style

- Follow PSR-12 where reasonable (PSR-4 autoloading already configured). Keep public API surface small and documented.
- Include minimal inline docblocks on public classes.

When changing or extending

- Update AGENT.md to include any protocol changes (lockfile format, constant names, public function signatures).
- Add PHPUnit tests for new behaviors and ensure they run without external network or SSH access.
