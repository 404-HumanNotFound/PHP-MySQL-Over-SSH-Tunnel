<?php
/**
 * smoke-test-shim.php  (per-agent adapter — EDIT THIS FILE PER AGENT)
 *
 * Copy this file into the root of each generated package (next to its own
 * composer.json), rename it to smoke-test-shim.php, and edit ONLY the
 * block marked below so it calls whatever that agent's actual bootstrap
 * entry point turned out to be.
 *
 * Your only job here is a mechanical translation: call the agent's real
 * API with the given $config array, then return a plain array shaped
 * exactly like this:
 *
 *   [
 *       'active' => bool,    // is the tunnel actually active?
 *       'port'   => int,     // port to connect to (local if active, remote if not)
 *       'host'   => string,  // host to connect to ('127.0.0.1' if active, else remote server)
 *   ]
 *
 * Do not add scoring logic, retries, or error-swallowing here beyond what's
 * needed to translate shapes — smoke-test-core.php owns all the actual
 * checks. If the agent's real boot call throws, just let it throw; the
 * core test handles that itself (see Scenario 2, which deliberately
 * records whether an agent throws or falls back on a bad ssh_binary_path).
 *
 * The two global constants (MYSQL_SSH_TUNNEL_LOCAL_PORT,
 * MYSQL_SSH_TUNNEL_ACTIVE) are NOT shimmed — those names are part of the
 * fixed contract for every agent, so smoke-test-core.php reads them
 * directly regardless of which agent built the package.
 */

require __DIR__ . '/vendor/autoload.php';

function tunnel_boot(array $config): array
{
    // =====================================================================
    // EDIT BELOW THIS LINE PER AGENT — everything above stays the same.
    // =====================================================================

    // --- Example as filled in for the original Fable 5 run, where the
    //     real API happened to be:
    //     HumanNotFound\MysqlSshTunnel\TunnelManager::boot(array $config): TunnelResult
    //     with ->active / ->localPort / ->host readonly properties.
    //     Replace this whole block with whatever the agent under test
    //     actually produced (different namespace, different method name,
    //     array return instead of object, different property names, etc.)

    $result = \HumanNotFound\MysqlSshTunnel\TunnelManager::boot($config);

    return [
        'active' => $result->active,
        'port'   => $result->localPort,
        'host'   => $result->host,
    ];

    // =====================================================================
    // EDIT ABOVE THIS LINE PER AGENT.
    // =====================================================================
}
