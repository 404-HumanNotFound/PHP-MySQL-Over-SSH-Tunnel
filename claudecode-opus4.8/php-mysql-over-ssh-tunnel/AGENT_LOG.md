# Agent Session Log

This file records the AI coding agent sessions (Claude Code CLI) that produced
this package. It was reconstructed from the local Claude Code session
transcripts stored on the machine that ran the builds
(`~/.claude/projects/-Users-mchaggis-AIProjects-php-mysql-over-ssh-tunnel-claudecode-opus4-8/*.jsonl`),
not from any external record — it only covers work done through Claude Code
on this machine.

## Summary

| # | Date (UTC) | Duration | Model / Tool | Result |
|---|---|---|---|---|
| 1 | 2026-07-04, 16:24–16:43 | ~19 min | Claude Opus 4.8 via Claude Code CLI v2.1.201 | Full build, written to the wrong location — **superseded**, not present in the final package |
| 2 | 2026-07-04, 19:59–20:29 | ~30 min | Claude Opus 4.8 via Claude Code CLI v2.1.201 | Full rebuild in the correct `php-mysql-over-ssh-tunnel/` subfolder — **this is the code currently in this repository** |
| 3 | 2026-07-20, 21:16– | — | Claude Fable 5 via Claude Code CLI | Documentation session: generated this log by reading back sessions 1–2 |

Both build sessions were given the identical prompt: *"Read PROMPT.md in this
directory and build the project it describes."*, working from the same
`PROMPT.md` specification checked into the parent directory.

---

## Session 1 — 2026-07-04 16:24–16:43 UTC (superseded)

- **Session ID:** `b1792762-da25-4d33-905c-3ef2ce41ae3f`
- **Model:** Claude Opus 4.8, Claude Code CLI v2.1.201
- **Working directory:** `claudecode-opus4.8/` (repo root — not the `php-mysql-over-ssh-tunnel/` subfolder the spec's file tree calls for)

What happened:
1. Read `PROMPT.md`, then discovered and read an external `SMOKE_TEST.php`
   validation harness present in the directory at the time, to confirm the
   public API contract before writing code.
2. Wrote the full package directly into the repo root: `composer.json`,
   `src/` (config value object, tunnel manager, process-runner abstraction,
   logging support), `tests/` (fakes + PHPUnit suite), `phpunit.xml`.
3. Ran `composer install` and `./vendor/bin/phpunit`; hit two problems:
   - `error_log()` output from a separate-process test corrupted PHPUnit's
     child-process result serialization.
   - Deprecation warnings from old-style `@dataProvider` doc-comments.
   Both were fixed (converted `@dataProvider` doc-comments to `#[DataProvider]`
   attributes via a `perl` in-place edit). Suite then passed **50/50**.
4. Verified the integration test suite skips cleanly without env vars.
5. Attempted to run `php SMOKE_TEST.php` as a final validation step — the
   user interrupted this tool call, then replied `continue`. The agent
   acknowledged, skipped the smoke test, and continued to the remaining
   deliverables (LICENSE, examples for CI3/CI4/Laravel/standalone,
   `.env.testing.example`, `CONTRIBUTING.md`, `AGENT.md`, `README.md`).
6. Linted every PHP file (`php -l`), ran `composer validate --strict`, did a
   final full test pass, added `.gitignore`, and printed the file tree.

Outcome: a complete, passing build — but located at the repo root rather than
inside a `php-mysql-over-ssh-tunnel/` package folder. Session 2 redid the
build in the correct location; none of this session's files remain on disk
today.

---

## Session 2 — 2026-07-04 19:59–20:29 UTC (current package)

- **Session ID:** `df835988-1784-4218-9237-227fc49596f6`
- **Model:** Claude Opus 4.8, Claude Code CLI v2.1.201
- **Working directory:** `claudecode-opus4.8/`, building into a
  `php-mysql-over-ssh-tunnel/` subfolder created for the purpose

What happened:
1. Read `PROMPT.md` again from a clean session and decided upfront to build
   inside a `php-mysql-over-ssh-tunnel/` subfolder to match the file tree
   specified in the prompt.
2. Created the directory structure and wrote:
   - `src/TunnelConfig.php`, `src/TunnelManager.php`, `src/TunnelResult.php`,
     `src/Lockfile.php`
   - `src/Exception/ConfigValidationException.php`
   - `src/System/SystemInterface.php`, `src/System/NativeSystem.php`
   - `src/Support/Logging.php`
   - `tests/Unit/*` (config, manager, native-system, constants tests),
     `tests/Support/FakeSystem.php`, `tests/Support/RecordingLogger.php`,
     `tests/Integration/RealTunnelIntegrationTest.php`
   - `examples/ci3-hook.php`, `examples/ci4-event.php`,
     `examples/laravel-provider.php`, `examples/standalone.php`
   - `composer.json`, `phpunit.xml`, `LICENSE`, `README.md`, `AGENT.md`,
     `.env.testing.example`, `.gitignore`
3. Ran `composer install`, then `composer test`: 2 failures, same root cause
   as Session 1 — `error_log()` output leaking into an isolated-process
   test's captured output. Fixed by injecting a logger into the affected
   tests so warnings don't hit `error_log`.
4. Re-ran `composer test`: **45/45 passed.**
5. Verified `composer test:integration` self-skips cleanly (no env vars
   configured), linted all PHP files, and produced the final file tree.

Outcome: this is the build present in this repository today.

---

## Current state (re-verified 2026-07-20)

Re-running the suite in this package as of this log's generation:

```
$ composer test
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
.............................................                     45 / 45 (100%)
OK (45 tests, 107 assertions)
```

45/45 tests still pass, matching Session 2's result — no changes have been
made to `src/` or `tests/` since that session.

---

## Session 3 — 2026-07-20 (this session)

- **Model:** Claude Fable 5, via Claude Code CLI
- **Purpose:** generated this `AGENT_LOG.md` by reading back the transcripts
  of Sessions 1 and 2 and re-running the test suite to confirm the package's
  current state matches what Session 2 reported.
