<?php

declare(strict_types=1);

namespace HumanNotFound\MysqlSshTunnel\Tests;

use HumanNotFound\MysqlSshTunnel\Lockfile\TunnelLockfile;
use PHPUnit\Framework\TestCase;

final class TunnelLockfileTest extends TestCase
{
    private string $dir;

    private TunnelLockfile $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/lockfile-test-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
        $this->store = new TunnelLockfile($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testRoundTripWriteRead(): void
    {
        $path = $this->store->pathForHash('abc123');
        $fh = $this->store->open($path);
        self::assertNotFalse($fh);
        flock($fh, LOCK_EX);
        $this->store->write($fh, 4242, 3307, 'abc123');
        flock($fh, LOCK_UN);
        fclose($fh);

        $data = $this->store->read($path);
        self::assertSame(['pid' => 4242, 'port' => 3307, 'hash' => 'abc123'], $data);

        $perms = fileperms($path) & 0777;
        self::assertSame(0600, $perms);
    }

    public function testReadMissingReturnsNull(): void
    {
        self::assertNull($this->store->read($this->dir . '/nope.lock'));
    }
}
