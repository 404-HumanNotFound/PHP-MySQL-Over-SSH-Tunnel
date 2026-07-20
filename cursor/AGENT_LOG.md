# AGENT_LOG.md

Session log for the autonomous build of
`404-humannotfound/php-mysql-over-ssh-tunnel`.

Generated from Cursor agent transcript
[`bf158495-41d1-4be0-b40a-4901bc265785`](bf158495-41d1-4be0-b40a-4901bc265785)
and the build conversation itself.

---

## Overview

| Field | Value |
|-------|--------|
| Package | `404-humannotfound/php-mysql-over-ssh-tunnel` |
| Spec source | `PROMPT.md` in workspace root |
| Workspace | `/Users/mchaggis/AIProjects/php-mysql-over-ssh-tunnel/cursor` |
| Agent / model | Cursor Auto (Composer) |
| Transcript ID | `bf158495-41d1-4be0-b40a-4901bc265785` |
| Sessions recorded | 2 turns in one continuous chat (build + this log request) |
| Git | Not initialized (per PROMPT.md — local file generation only) |

---

## Session 1 — Package build

| Field | Value |
|-------|--------|
| Started | Monday, 20 Jul 2026, 10:38 PM (UTC+1) |
| Completed | Monday, 20 Jul 2026, ~10:50 PM (UTC+1) |
| User request | *Read PROMPT.md in this directory and build the project it describes.* |
| Mode | Autonomous single-pass build (no design/scope clarifying questions) |

### Inputs consulted

1. **`PROMPT.md`** — full package spec (API contract, security, tests, docs, composer.json rules).
2. **`SMOKE_TEST.php`** — fixed public API expectations:
   - Namespace: `HumanNotFound\MysqlSshTunnel`
   - `TunnelManager::boot(array $config): TunnelResult`
   - `TunnelResult` properties: `active`, `localPort`, `host`
3. **`run-smoke-test.sh` / smoke helpers** — env-var naming for optional real-world checks (not executed during the build; no real SSH/DB opened).

### Design choices locked during the build

Documented also in README “Design Decisions”:

- Missing / non-executable `ssh_binary_path` → **environmental fallback** (PROMPT req 7 / AGENT constraints), not a validation throw.
- No hard `psr/log` dependency; duck-typed `warning()` + default `error_log()`.
- Lockfile stale cleanup truncates in place under `flock` (no unlink-while-open).
- Random-port race (bind `:0`, release, then `ssh -L`) documented, not hidden.
- Suggest `ext-posix`; fallback via `/proc/{pid}` or TCP port check.

### Work phases (chronological)

1. **Scaffold** — `composer.json`, `LICENSE` (MIT), `phpunit.xml`, `.gitignore`, `.env.testing.example`.
2. **Core library (`src/`)** — `TunnelConfig`, `TunnelResult`, `TunnelManager`, `InvalidConfigException`, logger contract, lockfile helper, `proc_open` process runner + PID liveness.
3. **Lockfile fix** — avoid unlinking flock’d lockfiles (Unix inode orphan risk).
4. **Examples** — CI3 hook, CI4 event, Laravel provider, standalone PHP.
5. **Tests** — PHPUnit unit suite with fake `ProcessRunnerInterface`; optional `@group integration` test (env-gated, skipped by default).
6. **Docs** — `README.md`, `AGENT.md`.
7. **`composer install`** + unit run — initially **12** tests discovered (config tests co-located in wrong filename).
8. **Fix discovery** — split `TunnelConfigTest` into its own file; **23** tests, **81** assertions, OK.
9. **Deprecation cleanup** — `@runInSeparateProcess` → `#[RunInSeparateProcess]` (PHPUnit 11).

### Verification performed

```text
composer install --no-interaction
./vendor/bin/phpunit --testsuite unit
→ OK (23 tests, 81 assertions)
```

Not run (by design / PROMPT constraints):

- Real SSH connections
- Real MySQL connections
- `composer test:integration` / `SMOKE_TEST.php` against live hosts
- `git init` / commit / publish to Packagist or GitHub

### Deliverables produced (Session 1)

```text
composer.json, LICENSE, phpunit.xml, .gitignore, .env.testing.example
README.md, AGENT.md
src/TunnelManager.php, TunnelResult.php, TunnelConfig.php
src/Exception/InvalidConfigException.php
src/Contract/LoggerInterface.php, Logging/ErrorLogLogger.php
src/Lockfile/TunnelLockfile.php
src/Process/{ProcessRunnerInterface,ProcessHandleInterface,ProcOpenRunner,ProcOpenHandle,PidLiveness}.php
examples/{ci3-hook,ci4-event,laravel-provider,standalone}.php
tests/TunnelConfigTest.php, TunnelManagerTest.php, TunnelLockfileTest.php
tests/Integration/RealTunnelIntegrationTest.php
```

Pre-existing workspace files left in place (not authored this session):  
`PROMPT.md`, `SMOKE_TEST.php`, `smoke-test-shim.example.php`, `run-smoke-test.sh`.

### Outcome

Package build completed end-to-end in one agent session. Public API aligned with `SMOKE_TEST.php`. Default unit suite green.

---

## Session 2 — Agent log request

| Field | Value |
|-------|--------|
| Started | Monday, 20 Jul 2026, 10:50 PM (UTC+1) |
| User request | *Are you able to generate a AGENET_LOG.md file that contains details of the sessions used to create the package?* |
| Action | Created this file as **`AGENT_LOG.md`** (corrected spelling from `AGENET_LOG.md`). |
| Sources | Agent transcript `bf158495-41d1-4be0-b40a-4901bc265785` + Session 1 build notes |

---

## Transcript reference

Cursor stores the chat as JSONL under the project’s agent-transcripts directory:

```text
…/agent-transcripts/bf158495-41d1-4be0-b40a-4901bc265785/bf158495-41d1-4be0-b40a-4901bc265785.jsonl
```

Approximate message mix in that transcript at log-write time: **2 user** turns, **24 assistant** turns (tool calls + replies), covering the full build and this follow-up.

---

## Notes for future agents

- Prefer `AGENT.md` for *constraints* going forward; prefer this `AGENT_LOG.md` for *historical session provenance*.
- Do not treat `run-smoke-test.sh` credentials as package defaults — they are local operator env exports only.
- Re-run `composer test` after behavioral changes; keep `proc_open` mocked in the unit suite.
