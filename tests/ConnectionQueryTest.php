<?php

declare(strict_types=1);

namespace Ephpm\Db\Tests;

use Ephpm\Db\Connection;
use Ephpm\Db\Result;
use PHPUnit\Framework\TestCase;

final class ConnectionQueryTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        \EphpmDbFake::reset();
        $this->db = new Connection();
    }

    public function testRowsAreAssocWithNativeTypes(): void
    {
        $result = $this->db->query("SELECT 1 AS i, 1.5 AS f, 'x' AS s, NULL AS n");

        self::assertSame(
            [['i' => 1, 'f' => 1.5, 's' => 'x', 'n' => null]],
            $result->rows(),
        );
    }

    public function testPlaceholdersBindEverySupportedType(): void
    {
        $row = $this->db->row(
            'SELECT ? AS n, ? AS t, ? AS fa, ? AS i, ? AS f, ? AS s',
            [null, true, false, 42, 2.25, 'hello'],
        );

        self::assertSame(
            ['n' => null, 't' => 1, 'fa' => 0, 'i' => 42, 'f' => 2.25, 's' => 'hello'],
            $row,
        );
    }

    public function testDuplicateColumnNameKeepsLastValue(): void
    {
        $row = $this->db->row('SELECT 1 AS a, 2 AS a');

        self::assertSame(['a' => 2], $row);
    }

    public function testStatementWithoutResultSetReturnsEmptyResult(): void
    {
        $result = $this->db->query('CREATE TABLE t (id INTEGER)');

        self::assertInstanceOf(Result::class, $result);
        self::assertSame([], $result->rows());
        self::assertCount(0, $result);
    }

    public function testResultIsCountableAndIterable(): void
    {
        $this->db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->execute("INSERT INTO t (name) VALUES ('a'), ('b'), ('c')");

        $result = $this->db->query('SELECT id, name FROM t ORDER BY id');

        self::assertCount(3, $result);
        self::assertSame(['id' => 1, 'name' => 'a'], $result->first());

        $names = [];
        foreach ($result as $row) {
            $names[] = $row['name'];
        }
        self::assertSame(['a', 'b', 'c'], $names);
    }

    public function testFirstOnEmptyResultIsNull(): void
    {
        $this->db->execute('CREATE TABLE t (id INTEGER)');

        self::assertNull($this->db->query('SELECT id FROM t')->first());
    }

    public function testScalarRowAndColumnConveniences(): void
    {
        $this->db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->execute("INSERT INTO t (name) VALUES ('a'), ('b')");

        self::assertSame(2, $this->db->scalar('SELECT COUNT(*) FROM t'));
        self::assertNull($this->db->scalar('SELECT id FROM t WHERE id = ?', [999]));
        self::assertSame(
            ['id' => 2, 'name' => 'b'],
            $this->db->row('SELECT id, name FROM t WHERE name = ?', ['b']),
        );
        self::assertNull($this->db->row('SELECT id FROM t WHERE id = ?', [999]));
        self::assertSame(['a', 'b'], $this->db->column('SELECT name FROM t ORDER BY id'));
        self::assertSame([], $this->db->column('SELECT name FROM t WHERE id = ?', [999]));
    }
}
