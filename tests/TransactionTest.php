<?php

declare(strict_types=1);

namespace Ephpm\Db\Tests;

use Ephpm\Db\Connection;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        \EphpmDbFake::reset();
        $this->db = new Connection();
        $this->db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
    }

    public function testTransactionCommitsAndReturnsCallbackResult(): void
    {
        $value = $this->db->transaction(function (Connection $db): int {
            $db->execute('INSERT INTO t (name) VALUES (?)', ['a']);
            $db->execute('INSERT INTO t (name) VALUES (?)', ['b']);

            return 42;
        });

        self::assertSame(42, $value);
        self::assertSame(2, $this->db->scalar('SELECT COUNT(*) FROM t'));
    }

    public function testTransactionRollsBackAndRethrowsOnThrow(): void
    {
        $boom = new \DomainException('boom');

        try {
            $this->db->transaction(function (Connection $db) use ($boom): void {
                $db->execute('INSERT INTO t (name) VALUES (?)', ['a']);

                throw $boom;
            });
            self::fail('transaction() must rethrow');
        } catch (\DomainException $e) {
            self::assertSame($boom, $e);
        }

        self::assertSame(0, $this->db->scalar('SELECT COUNT(*) FROM t'));
        // The session must be usable again (no transaction left open).
        $this->db->execute('INSERT INTO t (name) VALUES (?)', ['after']);
        self::assertSame(1, $this->db->scalar('SELECT COUNT(*) FROM t'));
    }

    public function testManualBeginRollBack(): void
    {
        $this->db->begin();
        $this->db->execute('INSERT INTO t (name) VALUES (?)', ['a']);
        self::assertSame(1, $this->db->scalar('SELECT COUNT(*) FROM t'));
        $this->db->rollBack();

        self::assertSame(0, $this->db->scalar('SELECT COUNT(*) FROM t'));
    }

    public function testManualBeginCommit(): void
    {
        $this->db->begin();
        $this->db->execute('INSERT INTO t (name) VALUES (?)', ['a']);
        $this->db->commit();

        self::assertSame(1, $this->db->scalar('SELECT COUNT(*) FROM t'));
    }
}
