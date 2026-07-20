# AGENT_LOG.md — session history for this package

A record of the Claude Code sessions that produced and maintain this
repository, generated from local session transcripts. Kept for provenance;
not required reading for using or modifying the library (see `AGENT.md` for
that).

## Session 1 — initial build

- **Date:** 2026-07-04, 15:41:22 – 16:00:03 UTC (~19 minutes)
- **Model:** `claude-fable-5` (Fable 5)
- **CLI version:** 2.1.201
- **Working directory:** `/Users/mchaggis/AIProjects/php-mysql-over-ssh-tunnel/claudecode-fable5`
- **User prompt:** "Read PROMPT.md in this directory and build the project it describes."
- **Mode:** autonomous, single-pass build per `PROMPT.md`'s "Execution Mode"
  instructions (no git commands, no Packagist publish, no real SSH/DB
  connections).

**Tool usage:** 1 `Read` (PROMPT.md), 26 `Write`, 1 `Edit`, 6 `Bash`.

**Bash commands run:**
1. `php --version`; `composer --version`; `ls -la` — environment check.
2. `composer install --no-interaction` — install dev dependencies
   (PHPUnit, psr/log).
3. `composer test` — run default (unit-only) test suite.
4. `composer test:integration` (expected skip) + `php -l` lint pass over
   `src/`, `tests/`, `examples/`.
5. `php -l` lint pass (confirmation run).
6. `find . -type f` (excluding `vendor/`, `.claude/`, `.phpunit.cache/`) —
   final generated-file listing.

**Result:** 56 unit tests passing (132 assertions); integration suite
skips cleanly when unconfigured; all PHP files lint clean on PHP 8.4 while
targeting an 8.2 floor. No git commands were run, nothing was published,
no real SSH/DB connections were made.

**Files created (27):**
```
composer.json  LICENSE  phpunit.xml  .gitignore  .env.testing.example  README.md  AGENT.md
src/
  TunnelConfig.php  TunnelManager.php  TunnelResult.php  TunnelException.php
  ErrorLogLogger.php  ProcessRunnerInterface.php  ProcessHandleInterface.php
  ProcOpenProcessRunner.php  ProcOpenProcessHandle.php
examples/
  ci3-hook.php  ci4-event.php  laravel-provider.php  standalone.php
tests/
  TunnelConfigTest.php  TunnelManagerTest.php  TunnelIntegrationTest.php
  Support/ArrayLogger.php  Support/FakeProcessHandle.php  Support/FakeProcessRunner.php
```
(`PROMPT.md` itself was already present in the working directory and was
read, not written, by the agent.)

## Session 2 — documentation

- **Date:** 2026-07-20, 21:27 – 21:28 UTC
- **Model:** `claude-sonnet-5` (Sonnet 5)
- **User prompt:** requested this `AGENT_LOG.md` file, summarizing the
  session(s) used to create the package.
- **Result:** added this file. No changes to `src/`, tests, or docs.

## Notes

- This repository is not a git repo (`git init` was deliberately never run,
  per `PROMPT.md`), so there is no commit history to cross-reference —
  this log is derived from local Claude Code session transcripts
  (`~/.claude/projects/.../*.jsonl`) instead.
- If further sessions modify this package, append a new dated entry above
  following the same format rather than rewriting history.
