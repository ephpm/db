<?php

declare(strict_types=1);

namespace Ephpm\Db\Tests;

use Ephpm\Db\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionExecuteTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        \EphpmDbFake::reset();
        $this->db = new Connection();
        $this->db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
    }

    public function testInsertReportsAffectedRowsAndLastInsertId(): void
    {
        $ok = $this->db->execute('INSERT INTO t (name) VALUES (?)', ['a']);

        self::assertSame(1, $ok->affectedRows);
        self::assertSame(1, $ok->lastInsertId);

        $ok = $this->db->execute('INSERT INTO t (name) VALUES (?)', ['b']);

        self::assertSame(1, $ok->affectedRows);
        self::assertSame(2, $ok->lastInsertId);
    }

    public function testUpdateReportsAffectedRows(): void
    {
        $this->db->execute("INSERT INTO t (name) VALUES ('a'), ('b'), ('c')");

        $ok = $this->db->execute("UPDATE t SET name = 'x' WHERE id > ?", [1]);

        self::assertSame(2, $ok->affectedRows);
    }

    public function testDeleteReportsAffectedRows(): void
    {
        $this->db->execute("INSERT INTO t (name) VALUES ('a'), ('b')");

        $ok = $this->db->execute('DELETE FROM t');

        self::assertSame(2, $ok->affectedRows);
    }

    public function testSelectThroughExecuteReturnsZeros(): void
    {
        $this->db->execute("INSERT INTO t (name) VALUES ('a')");

        $ok = $this->db->execute('SELECT * FROM t');

        self::assertSame(0, $ok->affectedRows);
        self::assertSame(0, $ok->lastInsertId);
    }
}
