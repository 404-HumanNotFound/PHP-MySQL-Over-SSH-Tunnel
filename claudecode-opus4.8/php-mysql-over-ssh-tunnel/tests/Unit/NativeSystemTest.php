<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests\Unit;

use HumanNotFound\MysqlSshTunnel\System\NativeSystem;
use PHPUnit\Framework\TestCase;

/**
 * Tests the few real, non-network-facing bits of {@see NativeSystem}: the
 * escapeshellarg-based command builder (a security-critical seam) and the
 * ephemeral port allocator. No ssh process is ever started here.
 */
final class NativeSystemTest extends TestCase
{
    public function testBuildCommandLineEscapesEveryArgumentIndividually(): void
    {
        $line = NativeSystem::buildCommandLine([
            '/usr/bin/ssh',
            '-L',
            '3306:127.0.0.1:3306',
            'evil; rm -rf / #@host',
        ]);

        // Each part is individually single-quoted by escapeshellarg().
        self::assertStringContainsString(escapeshellarg('/usr/bin/ssh'), $line);
        self::assertStringContainsString(escapeshellarg('evil; rm -rf / #@host'), $line);

        // The dangerous metacharacters must be neutralised (inside quotes),
        // never left as a bare shell separator.
        self::assertStringNotContainsString('; rm -rf / #@host ', $line.' ');
    }

    public function testFindFreePortReturnsUsablePort(): void
    {
        $system = new NativeSystem();
        $port = $system->findFreePort();

        self::assertGreaterThan(0, $port);
        self::assertLessThanOrEqual(65535, $port);

        // The port should be free right now — we can bind it ourselves.
        $sock = @stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $errstr);
        self::assertIsResource($sock, 'Allocated port should be bindable: '.$errstr);
        fclose($sock);
    }

    public function testIsExecutableReflectsRealFilesystem(): void
    {
        $system = new NativeSystem();

        self::assertTrue($system->isExecutable(PHP_BINARY));
        self::assertFalse($system->isExecutable('/no/such/binary/'.uniqid('', true)));
    }

    public function testIsListeningIsFalseForClosedPort(): void
    {
        $system = new NativeSystem();
        // Pick a very likely-closed high port.
        self::assertFalse($system->isListening('127.0.0.1', 1, 0.2));
    }
}
