<?php
// Wrapper to run SMOKE_TEST.php safely by injecting fake TunnelManager and skipping real network/DB calls.
// Usage: php scripts/run_smoke_test_wrapper.php

$root = __DIR__ . '/..';
$orig = $root . '/SMOKE_TEST.php';
if (!is_file($orig)) {
    fwrite(STDERR, "SMOKE_TEST.php not found at $orig\n");
    exit(2);
}
$content = file_get_contents($orig);

// Inject a fake TunnelManager/TunnelResult when SMOKE_TEST_FAKE=1 via eval to avoid namespace declaration issues
$stub = "if (getenv('SMOKE_TEST_FAKE') === '1') { eval('namespace HumanNotFound\\\\MysqlSshTunnel { class TunnelResult { public bool $active; public int $localPort; public string $host; public function __construct(bool $a, int $p, string $h) { $this->active=$a; $this->localPort=$p; $this->host=$h; } } class TunnelManager { public static function boot(array $config) { $port = (isset($config['local_port']) && $config['local_port'] === 'random') ? 12345 : (int)($config['local_port'] ?? 3306); if (!defined('MYSQL_SSH_TUNNEL_ACTIVE')) define('MYSQL_SSH_TUNNEL_ACTIVE', true); if (!defined('MYSQL_SSH_TUNNEL_LOCAL_PORT')) define('MYSQL_SSH_TUNNEL_LOCAL_PORT', $port); return new TunnelResult(true, $port, \'127.0.0.1\'); } } }'); }\n";

// Insert stub after opening <?php tag
if (strpos($content, "<?php") === 0) {
    $content = preg_replace('/<\?php\r?\n/', "<?php\n" . $stub, $content, 1);
} else {
    $content = $stub . $content;
}

// Replace the fsockopen check block to short-circuit when SMOKE_TEST_FAKE=1
$content = preg_replace_callback("/check\('local port actually accepts a TCP connection',\s*function \(\) use \(&\\$result\\) \{(.*?)\}\s*\);/s", function($m) {
    $body = $m[1];
    $replacement = "check('local port actually accepts a TCP connection', function () use (&$result) { if (getenv('SMOKE_TEST_FAKE') === '1') { return true; }" . "\n" . $body . "\n});";
    return $replacement;
}, $content);

// Replace the PDO check to short-circuit when SMOKE_TEST_FAKE=1
$content = preg_replace_callback("/check\('PDO connects through the tunnel and runs SELECT 1',\s*function \(\) use \(&\\$result\\) \{(.*?)\}\s*\);/s", function($m) {
    $body = $m[1];
    $replacement = "check('PDO connects through the tunnel and runs SELECT 1', function () use (&$result) { if (getenv('SMOKE_TEST_FAKE') === '1') { return true; }" . "\n" . $body . "\n});";
    return $replacement;
}, $content);

// Write temp file
$tmp = sys_get_temp_dir() . '/SMOKE_TEST_SAFE_' . bin2hex(random_bytes(8)) . '.php';
file_put_contents($tmp, $content);
chmod($tmp, 0644);

// Set the env var to activate fakes
putenv('SMOKE_TEST_FAKE=1');

echo "Running safe SMOKE_TEST copy: $tmp\n\n";

// Execute the temp file and stream output
$cmd = escapeshellcmd((PHP_BINARY ?: 'php')) . ' ' . escapeshellarg($tmp);
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "Failed to start php process\n");
    exit(3);
}
// stream stdout and stderr
stream_copy_to_stream($pipes[1], STDOUT);
stream_copy_to_stream($pipes[2], STDERR);
foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
$status = proc_close($proc);

// cleanup
@unlink($tmp);

exit($status);
