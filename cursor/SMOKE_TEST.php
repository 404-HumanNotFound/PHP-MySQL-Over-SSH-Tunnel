<?php
/**
 * SMOKE_TEST.php
 *
 * Manual, real-world verification script for php-mysql-over-ssh-tunnel builds.
 * Copy this file into the root of each generated package (next to composer.json)
 * and run it directly: `php SMOKE_TEST.php`
 *
 * This is NOT part of the package's own PHPUnit suite — it is a human-run
 * check against a real SSH server and a real test MySQL user, used to
 * confirm the package actually works end-to-end, not just that it passes
 * its own mocked unit tests.
 *
 * Targets the fixed Public API Contract from PROMPT.md:
 *   TunnelManager::boot(array $config): TunnelResult
 * which builds config, ensures the tunnel, defines the global constants,
 * and returns a TunnelResult with ->active / ->localPort / ->host.
 *
 * Reads all connection details from environment variables. Nothing here is
 * hardcoded, and nothing here should ever be committed with real values
 * filled in. Set these before running, e.g.:
 *
 *   export TUNNEL_TEST_SERVER=remote.server.com
 *   export TUNNEL_TEST_SSH_USER=someuser
 *   export TUNNEL_TEST_SSH_KEY_PATH=/home/user/.ssh/id_ed25519
 *   export TUNNEL_TEST_SSH_BINARY_PATH=/usr/bin/ssh
 *   export TUNNEL_TEST_REMOTE_PORT=3306
 *   export TUNNEL_TEST_DB_USER=tunnel_test_user
 *   export TUNNEL_TEST_DB_PASSWORD=xxxxx
 *   export TUNNEL_TEST_DB_NAME=tunnel_test
 *   php SMOKE_TEST.php
 *
 * Optional: TUNNEL_TEST_LOCAL_PORT
 *   Defaults to 'random' (exercises the ephemeral-port-allocation feature
 *   itself, which is worth covering). Set this to a specific free port
 *   (e.g. `export TUNNEL_TEST_LOCAL_PORT=52219`) if port 3306 (or whatever
 *   default a package chooses) collides with something already running on
 *   your machine — a local MySQL/MariaDB install is the usual culprit.
 *   Set this ONCE in your shell profile / a local .env.testing file and
 *   reuse the same value for every tool's generated package, so a port
 *   collision on your machine doesn't silently change what's actually being
 *   tested between runs.
 *
 * Optional: TUNNEL_TEST_SSH_PORT
 *   The port sshd listens on at TUNNEL_TEST_SERVER (NOT the MySQL port —
 *   see PROMPT.md's ssh_port vs remote_port distinction). Defaults to 22.
 *   Only passed into config when the package under test actually supports
 *   an `ssh_port` config key (added to PROMPT.md after the first run).
 *
 *   For a package built BEFORE ssh_port was added to the prompt (i.e. it
 *   has no way to accept a non-default SSH port at all), you can still test
 *   it against a bastion host on a non-standard port without touching its
 *   code: add a Host alias to your own ~/.ssh/config —
 *       Host tunnel-test-host
 *           HostName remote.server.com
 *           Port 52219
 *   — then set TUNNEL_TEST_SERVER=tunnel-test-host instead of the raw
 *   hostname. The system `ssh` binary reads the port from your config file,
 *   so no code change is needed to verify that specific build.
 */

require __DIR__ . '/vendor/autoload.php';

use HumanNotFound\MysqlSshTunnel\TunnelManager;

// ---- helpers ---------------------------------------------------------

$results = [];

function check(string $label, callable $fn): void
{
    global $results;
    echo "→ {$label}... ";
    try {
        $ok = $fn();
        if ($ok === true) {
            echo "PASS\n";
            $results[] = [$label, true, null];
        } else {
            $msg = is_string($ok) ? $ok : 'returned false';
            echo "FAIL ({$msg})\n";
            $results[] = [$label, false, $msg];
        }
    } catch (\Throwable $e) {
        echo "FAIL (exception: " . $e->getMessage() . ")\n";
        $results[] = [$label, false, $e->getMessage()];
    }
}

function env(string $key, ?string $default = null): ?string
{
    $val = getenv($key);
    return $val !== false ? $val : $default;
}

function requireEnv(array $keys): ?string
{
    $missing = array_filter($keys, fn($k) => getenv($k) === false);
    if ($missing) {
        return 'missing required env vars: ' . implode(', ', $missing);
    }
    return null;
}

// ---- Scenario 1: real tunnel + real DB connection ---------------------

echo "\n=== Scenario 1: Real tunnel, real DB connection ===\n";

$missing = requireEnv([
    'TUNNEL_TEST_SERVER', 'TUNNEL_TEST_SSH_USER', 'TUNNEL_TEST_SSH_KEY_PATH',
    'TUNNEL_TEST_SSH_BINARY_PATH', 'TUNNEL_TEST_REMOTE_PORT',
    'TUNNEL_TEST_DB_USER', 'TUNNEL_TEST_DB_PASSWORD', 'TUNNEL_TEST_DB_NAME',
]);

if ($missing) {
    echo "SKIPPED — {$missing}\n";
    echo "Set these env vars to run Scenario 1. See header comment for the full list.\n";
} else {
    $localPortOverride = env('TUNNEL_TEST_LOCAL_PORT', 'random');
    $happyConfig = [
        'local_port'          => ctype_digit($localPortOverride) ? (int) $localPortOverride : $localPortOverride,
        'server'              => env('TUNNEL_TEST_SERVER'),
        'ssh_user'            => env('TUNNEL_TEST_SSH_USER'),
        'ssh_port'            => (int) env('TUNNEL_TEST_SSH_PORT', '22'),
        'remote_port'         => (int) env('TUNNEL_TEST_REMOTE_PORT'),
        'ssh_binary_path'     => env('TUNNEL_TEST_SSH_BINARY_PATH'),
        'ssh_key_path'        => env('TUNNEL_TEST_SSH_KEY_PATH'),
        'environments'        => ['development', 'local'],
        'current_environment' => 'development',
    ];

    $result = null;

    check('TunnelManager::boot() accepts valid config and returns a TunnelResult', function () use ($happyConfig, &$result) {
        $result = TunnelManager::boot($happyConfig);
        return ($result instanceof \HumanNotFound\MysqlSshTunnel\TunnelResult)
            ? true
            : 'boot() did not return a TunnelResult';
    });

    check('TunnelResult->active is true', function () use (&$result) {
        return $result->active === true
            ? true
            : 'expected true, got ' . var_export($result->active, true);
    });

    check('MYSQL_SSH_TUNNEL_LOCAL_PORT is defined and matches TunnelResult->localPort', function () use (&$result) {
        if (!defined('MYSQL_SSH_TUNNEL_LOCAL_PORT')) {
            return 'constant not defined';
        }
        return (MYSQL_SSH_TUNNEL_LOCAL_PORT === $result->localPort)
            ? true
            : 'constant/result mismatch';
    });

    check('MYSQL_SSH_TUNNEL_ACTIVE is defined and true', function () {
        if (!defined('MYSQL_SSH_TUNNEL_ACTIVE')) {
            return 'constant not defined';
        }
        return MYSQL_SSH_TUNNEL_ACTIVE === true
            ? true
            : 'expected true, got ' . var_export(MYSQL_SSH_TUNNEL_ACTIVE, true);
    });

    check('local port actually accepts a TCP connection', function () use (&$result) {
        $fp = @fsockopen('127.0.0.1', $result->localPort, $errno, $errstr, 3);
        if (!$fp) {
            return "fsockopen failed: {$errstr} ({$errno})";
        }
        fclose($fp);
        return true;
    });

    check('PDO connects through the tunnel and runs SELECT 1', function () use (&$result) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s',
            $result->host,
            $result->localPort,
            env('TUNNEL_TEST_DB_NAME')
        );
        $pdo = new \PDO($dsn, env('TUNNEL_TEST_DB_USER'), env('TUNNEL_TEST_DB_PASSWORD'), [
            \PDO::ATTR_TIMEOUT => 3,
        ]);
        $stmt = $pdo->query('SELECT 1 AS ok');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return ((int) $row['ok'] === 1) ? true : 'unexpected result: ' . json_encode($row);
    });

    check('a second boot() with the same config reuses the tunnel (no duplicate process)', function () use ($happyConfig, &$result) {
        $before = $result->localPort;
        $second = TunnelManager::boot($happyConfig);
        return ($second->localPort === $before)
            ? true
            : 'port changed on second boot() — likely started a duplicate tunnel';
    });
}

// ---- Scenario 2: fallback on bad ssh_binary_path -----------------------

echo "\n=== Scenario 2: Fallback behavior (bad ssh_binary_path) ===\n";
echo "(Exercises the ambiguity between requirement 2 and requirement 7 in\n";
echo " PROMPT.md — record whether this throws or falls back for each agent.)\n";

check('boot() with a nonexistent ssh binary path either throws cleanly or falls back — record which', function () {
    try {
        $result = TunnelManager::boot([
            'local_port'          => 'random',
            'server'              => env('TUNNEL_TEST_SERVER', 'remote.server.invalid'),
            'ssh_user'            => env('TUNNEL_TEST_SSH_USER', 'someuser'),
            'remote_port'         => 3306,
            'ssh_binary_path'     => '/this/path/does/not/exist/ssh',
            'ssh_key_path'        => env('TUNNEL_TEST_SSH_KEY_PATH', null),
            'environments'        => ['development'],
            'current_environment' => 'development',
        ]);
    } catch (\Throwable $e) {
        echo "\n    [behavior: THREW " . get_class($e) . ': ' . $e->getMessage() . "]\n  ";
        return true; // not a failure — just record the behavior
    }

    // Read the fresh TunnelResult from THIS call, not the global constant —
    // the constant may already be defined (and guarded against
    // redefinition) from an earlier scenario's successful tunnel, so it can
    // legitimately disagree with this call's actual outcome.
    echo "\n    [behavior: FELL BACK, this call's active=" . var_export($result->active, true) . ']';
    if (defined('MYSQL_SSH_TUNNEL_ACTIVE') && MYSQL_SSH_TUNNEL_ACTIVE !== $result->active) {
        echo " (note: global MYSQL_SSH_TUNNEL_ACTIVE constant still reads "
            . var_export(MYSQL_SSH_TUNNEL_ACTIVE, true)
            . ' from an earlier scenario — expected, since it is only defined once per process)';
    }
    echo "\n  ";

    return ($result->active === false)
        ? true
        : "expected this call's TunnelResult->active to be false on fallback, got true";
});

// ---- Scenario 3: environment gating -----------------------------------

echo "\n=== Scenario 3: Environment gating (disallowed environment) ===\n";

check('boot() in a disallowed environment falls back, does not throw, does not attempt a real tunnel', function () {
    $result = TunnelManager::boot([
        'local_port'          => 3306,
        'server'              => env('TUNNEL_TEST_SERVER', 'remote.server.invalid'),
        'ssh_user'            => env('TUNNEL_TEST_SSH_USER', 'someuser'),
        'remote_port'         => 3306,
        'ssh_binary_path'     => env('TUNNEL_TEST_SSH_BINARY_PATH', '/usr/bin/ssh'),
        'ssh_key_path'        => env('TUNNEL_TEST_SSH_KEY_PATH', null),
        'environments'        => ['production'],
        'current_environment' => 'development', // deliberately not allowed
    ]);

    return ($result->active === false)
        ? true
        : 'expected active === false when environment is disallowed';
});

// ---- Summary ------------------------------------------------------------

echo "\n=== Summary ===\n";
$pass = count(array_filter($results, fn($r) => $r[1] === true));
$fail = count($results) - $pass;
foreach ($results as [$label, $ok, $msg]) {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . ($ok ? '' : " — {$msg}") . "\n";
}
echo "\n{$pass} passed, {$fail} failed.\n";

exit($fail > 0 ? 1 : 0);
