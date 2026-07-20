<?php
namespace HumanNotFound\MysqlSshTunnel {
    // Require the package autoloader from inside the namespace block so that
    // the namespace declaration is the first statement in the file (PHP
    // requires that). The autoloader itself registers global symbols and
    // won't interfere with this namespace.
    require_once __DIR__ . '/../vendor/autoload.php';

    use PhpMySqlOverSshTunnel\TunnelManager as RealTunnelManager;

    final class TunnelResult
    {
        public bool $active;
        public int $localPort;
        public string $host;

        public function __construct(bool $active, int $localPort, string $host)
        {
            $this->active = $active;
            $this->localPort = $localPort;
            $this->host = $host;
        }
    }

    final class TunnelManager
    {
        /**
         * Bootstrap that delegates to the real TunnelManager and returns a
         * TunnelResult compatible with SMOKE_TEST expectations.
         *
         * @param array $config
         * @return TunnelResult
         */
        public static function boot(array $config): TunnelResult
        {
            $result = RealTunnelManager::boot($config);
            // Real Result uses ->active, ->port, ->host
            return new TunnelResult($result->active, $result->port, $result->host);
        }
    }
}
