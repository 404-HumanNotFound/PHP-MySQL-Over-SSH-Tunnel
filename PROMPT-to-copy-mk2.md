# Prompt: Build `php-mysql-over-ssh-tunnel` Composer Package

## Execution Mode

Complete this entire build autonomously, end-to-end, in a single pass.
Do not pause to ask **design or scope** clarifying questions (e.g. which
license, which framework hook style, naming choices). Where this prompt is
ambiguous or silent on such a detail, make the most reasonable,
industry-standard choice yourself, note the assumption briefly in a code
comment or in a "Design Decisions" section of the README, and continue.

This does **not** extend to command-execution safety. Nothing in this
prompt authorizes skipping your own built-in confirmation prompts for
destructive, irreversible, or high-blast-radius shell commands (e.g. `rm -rf`,
deleting or overwriting files outside this project's own folder, changing
file permissions/ownership outside this folder, or any command touching
paths outside the current working directory). If your normal safety checks
would ask before running such a command, still ask — that confirmation
step is expected and welcome, and is separate from the "don't ask design
questions" instruction above.

Fixed decisions (do not ask about these, do not deviate):
- License: **MIT**. Include a `LICENSE` file and set `"license": "MIT"` in
  `composer.json`.
- Author/vendor: **404-HumanNotFound** (already reflected in the package
  name below).
- Do **not** run any `git init`, `git add`, `git commit`, or `git push`
  commands, and do not attempt to create, authenticate with, or push to any
  GitHub repository. This is a local file-generation task only — leave
  version control entirely to me.
- Do **not** run `composer install`/`composer update` against the real
  Packagist registry unless you are only resolving dev dependencies needed
  to run the test suite locally — do not attempt to publish or register the
  package anywhere.
- Do **not** attempt to open any real SSH connection, contact any real
  server, or create any real database during the build — all such
  interaction must be confined to mocked/faked tests as specified below.
- If you would normally ask "should I create the repo / initialize git /
  install this globally / etc." — the answer is always no. Just generate
  the files.

Create every file specified below in full — do not truncate, summarize, or
leave `// TODO: implement this` placeholders in place of real logic. If a
section legitimately has no more to add (e.g. an example file), still
generate a complete, working, runnable example rather than a stub.

When finished, output a short plain-text summary (file tree + one line per
file) rather than an extended narrative explanation — this keeps output
comparable across tools.

## Package

Create a new Composer package: **`404-humannotfound/php-mysql-over-ssh-tunnel`**

## Goal

A framework-agnostic PHP library that transparently establishes (or reuses) an
SSH local-port-forward tunnel to a remote MySQL server, and exposes the local
port as a PHP constant so application code can connect to `127.0.0.1:PORT`
instead of the remote host directly. It must be loadable as a bootstrap step
*before* any DB connection is opened, in CodeIgniter 3, CodeIgniter 4, Laravel,
and plain/standalone PHP.

## Functional Requirements

1. **Framework independence.** Zero hard dependency on any framework class,
   interface, or service container. The core library must be usable with a
   single `require`/`use` statement and no framework bootstrapping.
2. **Minimal configuration**, supplied as a plain array or a small immutable
   config object:
   ```php
   [
       'local_port'      => 3306,   // int, or the string 'random'
       'server'          => 'remote.server.com',
       'ssh_user'        => 'someuser',
       'ssh_port'        => 22,     // port sshd listens on — NOT the MySQL port
       'remote_port'     => 3306,   // MySQL port on the remote/server side
       'ssh_binary_path' => '/usr/sbin/ssh',              // path to the ssh executable
       'ssh_key_path'    => '/home/user/.ssh/id_ed25519', // optional
       'environments'    => ['development', 'local'],     // see "Environment restriction" below
   ]
   ```
   - `ssh_port` is distinct from `remote_port`: `ssh_port` is the port `sshd`
     itself listens on at `server` (commonly non-default — e.g. `2222` or a
     high port — on bastion/jump hosts), while `remote_port` is the MySQL
     port being forwarded to on the far side of that SSH connection. Default
     to `22` when omitted. Validate as an integer 1-65535, same as
     `remote_port`. This must be passed to the `ssh` invocation (typically
     via `-p {ssh_port}`) — a package that hardcodes port 22 will silently
     fail against any bastion host using a non-standard SSH port.
   - `local_port: random` → bind an OS-assigned ephemeral port (bind to port 0,
     read back the assigned port, then release it immediately before handing
     it to `ssh`). There is an inherent small race window between releasing
     and the `ssh` process binding it — document this tradeoff in code
     comments and README rather than hiding it.
   - `ssh_binary_path` is required config (no baked-in default assumed, since
     the `ssh` binary's location varies by OS/distro — e.g. `/usr/bin/ssh` vs
     `/usr/sbin/ssh` vs a Homebrew path on macOS). Validate the path exists
     and is executable before use; a missing/non-executable path is a config
     error and should throw immediately (distinct from the "binary is fine
     but the tunnel itself fails" case under "Fallback behavior" below).
   - Do not accept SSH passwords as config, ever. Auth must go through the
     optional `ssh_key_path` config key, the user's running ssh-agent, or
     their `~/.ssh/config`/`known_hosts` — i.e. whatever the system `ssh`
     binary would normally use. **Document explicitly in the README and in a
     code-level docblock that any key referenced by `ssh_key_path` must
     either be passphrase-less or already unlocked/loaded into `ssh-agent`**
     — this library will never prompt for or handle a key passphrase, since
     `proc_open()` has no interactive terminal to prompt through.
3. **Tunnel process:** implemented via `proc_open()` invoking the binary at
   `ssh_binary_path` with
   `-p {ssh_port} -N -L {local_port}:127.0.0.1:{remote_port} {ssh_user}@{server}`
   (plus `-o BatchMode=yes`, `-o ExitOnForwardFailure=yes`, and, when
   `ssh_key_path` is set, `-i {ssh_key_path}`). All arguments must be passed
   through `escapeshellarg()` individually — never build the command as one
   interpolated string.
4. **Idempotent / auto-detect running tunnel:**
   - Compute a stable hash of the resolved config (server, user, remote_port,
     local_port when not random).
   - Store a lockfile per config-hash in `sys_get_temp_dir()` containing the
     PID and the resolved local port.
   - On each request/boot: read the lockfile if present, verify the PID is
     still alive (`posix_kill($pid, 0)` — with a documented fallback for
     systems without the `posix` extension, e.g. checking `/proc/{pid}` on
     Linux or treating liveness as unknown and reconnecting), and confirm the
     local port is actually accepting connections
     (`@fsockopen('127.0.0.1', $port, ..., $timeout)`).
   - Only start a new `ssh` process if no live, matching tunnel is found.
   - Handle the race between two PHP processes starting at the same instant
     (e.g. `flock()` on the lockfile during the check-then-start sequence).
5. **Shutdown behavior:** only when `local_port === 'random'`, register a
   `register_shutdown_function()` that terminates the spawned `ssh` process
   (`proc_terminate()`) and removes its lockfile. Fixed-port tunnels are
   intentionally left running (they're meant to be reused across requests).
6. **Global constant:** once resolved, expose the active local port as
   `define('MYSQL_SSH_TUNNEL_LOCAL_PORT', $port);` — guard against
   double-definition (`defined()` check) since this may be triggered by
   multiple bootstrap paths in the same request.
7. **Fallback behavior (no throw):** if the configured `ssh_binary_path`
   doesn't exist/isn't executable, or the `ssh` process exits immediately /
   fails to establish the forward within a short timeout, the library must
   **not** throw and must **not** halt the application. Instead:
   - Log a clear warning (via a pluggable PSR-3-compatible logger passed into
     config, defaulting to `error_log()` if none is provided) stating that
     the tunnel could not be established and the library is falling back to
     a direct connection.
   - Define `MYSQL_SSH_TUNNEL_LOCAL_PORT` as the *remote* `remote_port`
     value instead, and expose an additional constant such as
     `MYSQL_SSH_TUNNEL_ACTIVE` (bool, `false` in this case) so calling code
     can also detect the fallback and, e.g., use `server` directly as the DB
     host instead of `127.0.0.1`. Document clearly in the README that
     consuming code should branch on `MYSQL_SSH_TUNNEL_ACTIVE` to pick the
     right host (`127.0.0.1` when tunneled, `server` when falling back),
     since the local port alone isn't enough information once fallback has
     occurred.
   - Only genuine **config validation errors** (e.g. invalid hostname format,
     `local_port` out of valid range, `ssh_key_path` set but file doesn't
     exist) should throw — those are programmer errors, not environmental
     ones, and failing loudly at boot is appropriate for those.
8. **Environment restriction:** config accepts an optional `environments`
   array (e.g. `['development', 'local']`). The library must compare this
   against a detected/supplied current environment (accept it as an explicit
   config value — e.g. `'current_environment' => getenv('APP_ENV')` — rather
   than the library guessing framework-specific env detection itself, to
   keep it framework-independent). If the current environment is not in the
   allowed list:
   - Do not start a tunnel.
   - Log a clear warning explaining that the tunnel was skipped because the
     current environment isn't in the allowed list (this is a *deliberate*
     safety rail, distinct from the fallback case above — the intent is to
     make it hard to accidentally leave this tunnel mechanism wired into a
     production deployment).
   - Fall back to the same "direct connection" constants behavior described
     under Fallback behavior above.
   - If `environments` is omitted entirely, default to allowing all
     environments, but the README must carry an explicit, prominent warning
     that this package is intended for local/development use against remote
     database servers and is not recommended for production traffic.
9. **PHP version floor:** target **PHP 8.2** as the minimum supported version.
   (As of mid-2026, PHP 8.1 and earlier are fully end-of-life with no security
   patches; PHP 8.2 is the oldest branch still receiving security-only
   patches. Use this as the actual floor rather than defaulting to PHP 8.0.)
   Avoid PHP 8.3+-only syntax so the library still installs cleanly on 8.2.
10. **No framework bootstrap coupling.** The core class must not assume it is
    being autoloaded by any specific framework's service container. Provide
    thin, separate "adapter" snippets/files (not hard dependencies) showing
    how to wire it into each framework's own lifecycle hook — these adapters
    can `require` or `use` the core class but the core class must never
    `require` or reference framework code.

## Public API Contract (behavioral — exact names are your choice)

This section defines what the public entry point must be *capable of doing*,
not its literal class/method names. Design the API however is idiomatic for
PHP and for whatever internal structure you choose — do not treat any name
below as fixed. What must hold regardless of naming:

- **A single, documented bootstrap call** that a framework adapter or a
  standalone script can invoke with the config array described earlier in
  this document (`local_port`, `server`, `ssh_user`, `ssh_port`,
  `remote_port`, `ssh_binary_path`, `ssh_key_path`, `environments`,
  `current_environment`, plus any optional keys you add such as a PSR-3
  `logger`, `connect_timeout`, or `strict_host_key_checking`). This call
  must: validate config, ensure the tunnel (detect/reuse/start/fallback),
  define the global constants, and hand back an outcome value.
- **An outcome value** (object, readonly DTO, associative array — your
  choice) that exposes, at minimum, equivalents of:
  - whether the tunnel is actually active (bool),
  - the port to connect to (int) — the local tunnel port when active, the
    remote port when falling back,
  - the host to connect to (string) — `127.0.0.1` when active, the remote
    `server` hostname when falling back.
  These three pieces of information must be readable by field/property/array
  access without needing to inspect internals or catch anything.
- **Config validation errors** must throw a distinct, catchable exception
  type of your own naming. Environmental failures (missing/bad SSH binary,
  tunnel timeout, disallowed environment) must **never** throw — see
  "Fallback behavior" above.
- A way to separate "build/validate config" from "establish the tunnel" is
  welcome but not required, if your single bootstrap call already covers
  both in one step.
- No public manual teardown method is required — the shutdown behavior
  (register a `register_shutdown_function()` only for random-port tunnels)
  is sufficient on its own.
- Whatever names you choose, document the public surface clearly in the
  README and use it consistently across every framework adapter and the
  standalone example in this same package.

A concrete illustrative shape (not mandatory — shown only so the intent is
unambiguous):
```php
use YourVendor\YourNamespace\TunnelManager;

$result = TunnelManager::boot([
    'local_port'          => 3306,
    'server'              => 'remote.server.com',
    'ssh_user'            => 'someuser',
    'ssh_port'            => 22,
    'remote_port'         => 3306,
    'ssh_binary_path'     => '/usr/bin/ssh',
    'ssh_key_path'        => '/path/to/key',
    'environments'        => ['development', 'local'],
    'current_environment' => getenv('APP_ENV') ?: 'development',
]);
// MYSQL_SSH_TUNNEL_LOCAL_PORT and MYSQL_SSH_TUNNEL_ACTIVE are now defined.
// $result carries the same info directly (however you expose it) for
// callers who prefer not to read the global constants.
```
Internal implementation details (private methods, additional helper classes,
constructor injection for a logger, process-runner seams for testing, exact
class/namespace/method names, etc.) are entirely up to you.

```
php-mysql-over-ssh-tunnel/
  composer.json
  src/
    (config value object + validation)
    (core: start/detect/reuse/shutdown logic)
    (exception type for config validation errors)
  examples/
    ci3-hook.php            // CI3 Hooks example
    ci4-event.php            // CI4 Events example
    laravel-provider.php    // Laravel service provider / App::before example
    standalone.php
  tests/
    (PHPUnit test suite, with proc_open mocked/faked)
  README.md
  AGENT.md
  composer.json
```
(File/class names inside `src/` and `tests/` are yours to choose — the list
above shows the expected *responsibilities*, not literal filenames.)

## Security Requirements

- Never build shell strings via concatenation/interpolation — always pass
  argument arrays through `escapeshellarg()`.
- Never accept or log SSH passwords or private key contents/passphrases
  through config; `ssh_key_path` is a *file path only* — never read or print
  the key file's contents, and never prompt for or accept a passphrase.
- Validate `server` and `ssh_user` config values against a strict allow-list
  regex before use (hostnames/usernames only — reject shell metacharacters).
- Default to strict host key checking (do **not** silently pass
  `StrictHostKeyChecking=no` unless a config flag explicitly opts in, and
  document the risk of doing so).
- Lockfiles/PID files should be written with restrictive permissions
  (e.g. `0600`) since they reveal active tunnel/process info.

## Testing

- PHPUnit test suite covering: config validation, lockfile detection logic
  (live vs stale PID), random port allocation, and shutdown-function
  registration — using fakes/mocks for `proc_open` rather than opening real
  SSH connections. These run by default with no external dependencies.
- **Optional real-world integration test**, tagged `@group integration` and
  excluded from the default test run (e.g. via `phpunit.xml`
  `<exclude>integration</exclude>` on the default suite, with a separate
  `composer test:integration` script that includes it):
  - Reads connection details entirely from environment variables — never
    hardcode a personal path or server in the test itself:
    - `TUNNEL_TEST_SSH_KEY_PATH` — path to a passphrase-less (or
      agent-loaded) test SSH key.
    - `TUNNEL_TEST_SSH_USER`, `TUNNEL_TEST_SERVER`, `TUNNEL_TEST_REMOTE_PORT`.
    - `TUNNEL_TEST_DB_USER`, `TUNNEL_TEST_DB_PASSWORD`, `TUNNEL_TEST_DB_NAME`
      — credentials for a dedicated, low-privilege test MySQL user (do not
      reuse a real application DB user for this).
  - If any required env var is missing, the test must `markTestSkipped()`
    with a message explaining which variable is missing — never fail or
    error out for a developer who hasn't configured integration testing.
  - When the env vars are present, the test should: start the tunnel,
    confirm `MYSQL_SSH_TUNNEL_ACTIVE` is `true`, open a real PDO connection
    to `127.0.0.1:MYSQL_SSH_TUNNEL_LOCAL_PORT` using the test DB
    credentials, run a trivial `SELECT 1`, then let the shutdown function
    (or an explicit teardown) close the tunnel.
  - Document in the README/CONTRIBUTING how a developer sets these env vars
    locally (e.g. a `.env.testing.example` file) to run this suite
    themselves — but do not commit any real key, host, or credential.
- Note in README that this integration test is optional/local-only and is
  not expected to run in the agents' own CI unless they provision the above
  environment themselves.

## Documentation Deliverables

### README.md
Must include:
- Installation via Composer.
- Minimal config example.
- A dedicated usage section for each of:
  - **CodeIgniter 3** — via a **Hook** (e.g. `pre_system` or `pre_controller`).
  - **CodeIgniter 4** — via an **Event** (e.g. `pre_system`).
  - **Laravel** — via an `App::before` filter / middleware or a service
    provider `boot()` method (whichever is more idiomatic — explain the
    choice).
  - **Standalone PHP** — a plain `require` at the top of a bootstrap file.
- An explanation of the `MYSQL_SSH_TUNNEL_LOCAL_PORT` and
  `MYSQL_SSH_TUNNEL_ACTIVE` constants and how to branch on them when
  configuring a PDO/mysqli connection (`127.0.0.1` + local port when active,
  `server` + `remote_port` when not).
- A section on `ssh_binary_path` (why it's required, how to find it on
  common OSes — `which ssh` / `command -v ssh`).
- A section on the SSH auth requirements (key-based only via `ssh_key_path`
  or ssh-agent) explaining **why**, and a prominent note that any key must be
  passphrase-less or already unlocked in `ssh-agent`, since this library
  cannot interactively prompt for a passphrase.
- A section documenting the `random` port race-condition tradeoff.
- A section on the `environments` config option: how to restrict the tunnel
  to non-production environments, what happens when the current environment
  isn't allowed (silent fallback + warning, not an error), and an explicit
  recommendation against using this package in production.

### AGENT.md
A file aimed at AI coding agents working on this repo in the future,
covering:
- The package's purpose and non-goals (explicitly: *not* a general SSH
  library, *not* a connection pool, *not* a MySQL client wrapper).
- Hard constraints that must never be violated by future changes:
  no shell string interpolation, no password/passphrase-based SSH handling,
  no framework dependency in `src/`, PHP 8.2 minimum syntax, environmental
  failures (missing binary, tunnel failure, disallowed environment) must
  degrade to a logged warning + direct-connection fallback rather than
  throwing — only config validation errors may throw.
- The lockfile/PID protocol description (format, location, meaning) so an
  agent extending this later doesn't reinvent it incompatibly.
- Expectations for tests accompanying any change (PHPUnit, mocked proc_open).
- Coding style / PSR standard to follow (e.g. PSR-12) and static analysis
  tooling expected (e.g. PHPStan level to target).

## composer.json requirements
- `"type": "library"`, PSR-4 autoload for `src/`.
- `"require": {"php": ">=8.2"}`.
- Suggest (not require) `ext-posix` for liveness checks, with documented
  fallback behavior when absent.
- No runtime dependency on any framework package.
- Include `"homepage"` and `"support": {"issues": ..., "source": ...}` fields
  pointing at `https://github.com/404-HumanNotFound/php-mysql-over-ssh-tunnel`
  (placeholder — repo not yet created, but keep the URL structure consistent
  so it's a one-line update once the repo exists).
- License field must be `"MIT"` (see Execution Mode above) and a matching
  `LICENSE` file must be included. Include an `"authors"` entry for
  `404-HumanNotFound`.

## Deliverable checklist
- [ ] `composer.json`
- [ ] `src/` classes covering: config value object + validation, core
      tunnel start/detect/reuse/shutdown logic, and a dedicated exception
      type for config validation errors (names your choice)
- [ ] Framework adapter examples (CI3/CI4/Laravel/standalone)
- [ ] PHPUnit tests
- [ ] README.md (all four usage sections + constant + auth + random-port caveat)
- [ ] AGENT.md
