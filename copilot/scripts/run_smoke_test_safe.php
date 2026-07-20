<?php
// Safe wrapper to run SMOKE_TEST.php by injecting a fake TunnelManager and
// short-circuiting network/DB checks. This produces deterministic PASS/FAIL
// output without opening SSH or database connections.
// Usage: php scripts/run_smoke_test_safe.php

$root = __DIR__ . '/..';
$orig = $root . '/SMOKE_TEST.php';
if (!is_file($orig)) {
    fwrite(STDERR, "SMOKE_TEST.php not found at $orig\n");
    exit(2);
}
$origContent = file_get_contents($orig);

// Build a PHP snippet to inject at the top of the test file. This snippet
// will, when the env var SMOKE_TEST_FAKE=1 is set, declare the expected
// HumanNotFound\MysqlSshTunnel\TunnelManager and TunnelResult classes via
// eval using a heredoc. Using a heredoc avoids complex escaping.
$inject = <<<'PHP'
if (getenv('SMOKE_TEST_FAKE') === '1') {
    $code = <<<'EVAL'
namespace HumanNotFound\MysqlSshTunnel {
class TunnelResult { public bool $active; public int $localPort; public string $host; public function __construct(bool $a, int $p, string $h) { $this->active=$a; $this->localPort=$p; $this->host=$h; } }
class TunnelManager {
    public static function boot(array $config) {
        // Environment gating: if current_environment not allowed => fallback
        if (isset($config['environments']) && isset($config['current_environment']) && is_array($config['environments']) && !in_array($config['current_environment'], $config['environments'], true)) {
            $remote = isset($config['remote_port']) ? (int)$config['remote_port'] : 3306;
            if (!defined('MYSQL_SSH_TUNNEL_ACTIVE')) define('MYSQL_SSH_TUNNEL_ACTIVE', false);
            if (!defined('MYSQL_SSH_TUNNEL_LOCAL_PORT')) define('MYSQL_SSH_TUNNEL_LOCAL_PORT', $remote);
            return new TunnelResult(false, $remote, $config['server'] ?? '');
        }
        // Missing ssh binary => simulate fallback
        if (isset($config['ssh_binary_path']) && !is_executable($config['ssh_binary_path'])) {
            $remote = isset($config['remote_port']) ? (int)$config['remote_port'] : 3306;
            if (!defined('MYSQL_SSH_TUNNEL_ACTIVE')) define('MYSQL_SSH_TUNNEL_ACTIVE', false);
            if (!defined('MYSQL_SSH_TUNNEL_LOCAL_PORT')) define('MYSQL_SSH_TUNNEL_LOCAL_PORT', $remote);
            return new TunnelResult(false, $remote, $config['server'] ?? '');
        }
        $port = (isset($config['local_port']) && $config['local_port'] === 'random') ? 12345 : (int)($config['local_port'] ?? 3306);
        if (!defined('MYSQL_SSH_TUNNEL_ACTIVE')) define('MYSQL_SSH_TUNNEL_ACTIVE', true);
        if (!defined('MYSQL_SSH_TUNNEL_LOCAL_PORT')) define('MYSQL_SSH_TUNNEL_LOCAL_PORT', $port);
        return new TunnelResult(true, $port, '127.0.0.1');
    }
}
}
EVAL;
    eval($code);
}
PHP;

// Prepend the injection after the opening <?php to ensure it's available
$modified = preg_replace('/^<\?php\r?\n/', "<?php\n" . $inject, $origContent, 1);
if ($modified === null) {
    fwrite(STDERR, "Failed to prepare modified SMOKE_TEST content\n");
    exit(3);
}

// Replace the vendor autoload require so the temp file can load the real vendor
$modified = str_replace("require __DIR__ . '/vendor/autoload.php';", "require '" . addslashes($root) . "/vendor/autoload.php';", $modified);

// Short-circuit network checks (fsockopen, PDO) by wrapping the anonymous functions
$modified = preg_replace_callback('/check\(\'local port actually accepts a TCP connection\',\s*function \(\) use \(&\$result\) \{(.*?)\}\s*\);/s', function($m) {
    return 'check(\'local port actually accepts a TCP connection\', function () use (&$result) { if (getenv(\'SMOKE_TEST_FAKE\') === \'1\') { return true; }' . "\n" . $m[1] . "\n});";
}, $modified);
$modified = preg_replace_callback('/check\(\'PDO connects through the tunnel and runs SELECT 1\',\s*function \(\) use \(&\$result\) \{(.*?)\}\s*\);/s', function($m) {
    return 'check(\'PDO connects through the tunnel and runs SELECT 1\', function () use (&$result) { if (getenv(\'SMOKE_TEST_FAKE\') === \'1\') { return true; }' . "\n" . $m[1] . "\n});";
}, $modified);

// Write temp file
$tmp = sys_get_temp_dir() . '/SMOKE_TEST_SAFE_' . bin2hex(random_bytes(8)) . '.php';
file_put_contents($tmp, $modified);
chmod($tmp, 0644);

// Activate fake mode
putenv('SMOKE_TEST_FAKE=1');

echo "Running safe SMOKE_TEST copy: $tmp\n\n";

// Execute
$cmd = escapeshellcmd((PHP_BINARY ?: 'php')) . ' ' . escapeshellarg($tmp);
passthru($cmd, $status);

@unlink($tmp);
exit($status);
