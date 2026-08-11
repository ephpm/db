<?php

declare(strict_types=1);

namespace Ephpm\Db\Tests;

use Ephpm\Db\Exception\BridgeUnavailableException;
use PHPUnit\Framework\TestCase;

final class BridgeUnavailableTest extends TestCase
{
    protected function setUp(): void
    {
        \EphpmDbFake::reset();
    }

    /**
     * With the polyfill loaded the natives exist in this process, so the
     * absent-natives branch is exercised in a fresh PHP subprocess that
     * loads only the Composer autoloader.
     */
    public function testConstructorThrowsWhenNativesAreMissing(): void
    {
        $script = <<<'PHP'
            <?php
            require $argv[1];
            try {
                new \Ephpm\Db\Connection();
                echo 'NOTHROW';
            } catch (\Ephpm\Db\Exception\BridgeUnavailableException $e) {
                echo 'BRIDGE_UNAVAILABLE|', $e->getMessage();
            }
            PHP;

        $file = tempnam(sys_get_temp_dir(), 'ephpmdb');
        self::assertNotFalse($file);
        file_put_contents($file, $script);

        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' '
                . escapeshellarg(__DIR__ . '/../vendor/autoload.php');
            $output = shell_exec($cmd);
        } finally {
            @unlink($file);
        }

        self::assertIsString($output);
        self::assertStringStartsWith('BRIDGE_UNAVAILABLE|', $output);
        self::assertStringContainsString('ephpm_db_query()', $output);
        self::assertStringContainsString('[db.sqlite]', $output);
    }

    public function testBridgeUnavailableIsADbException(): void
    {
        self::assertInstanceOf(
            \Ephpm\Db\Exception\DbException::class,
            BridgeUnavailableException::nativesMissing(),
        );
    }
}
