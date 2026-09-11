<?php

declare(strict_types=1);

namespace Ephpm\Db\Tests;

use Ephpm\Db\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionRunTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        \EphpmDbFake::reset();
        $this->db = new Connection();
        $this->db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
    }

    public function testRunClassifiesASelectAsARowset(): void
    {
        $this->db->execute("INSERT INTO t (name) VALUES ('a'), ('b')");

        $run = $this->db->run('SELECT id, name FROM t ORDER BY id');

        self::assertTrue($run->hasRowset);
        self::assertSame(0, $run->affectedRows);
        self::assertSame(0, $run->lastInsertId);
        self::assertCount(2, $run->result);
        self::assertSame(['a', 'b'], array_column($run->result->rows(), 'name'));
        self::assertSame(['id', 'name'], $run->result->columnNames());
    }

    public function testRunClassifiesAnInsertAsOk(): void
    {
        $run = $this->db->run('INSERT INTO t (name) VALUES (?)', ['a']);

        self::assertFalse($run->hasRowset);
        self::assertSame(1, $run->affectedRows);
        self::assertSame(1, $run->lastInsertId);
        self::assertCount(0, $run->result);
    }

    /**
     * A zero-row result set still carries its column names — the whole
     * point of ephpm_db_columns() / the columns field (issue #262). The
     * rows alone could not, since there are none.
     */
    public function testRunCarriesColumnNamesForAZeroRowResultSet(): void
    {
        $run = $this->db->run('SELECT id, name FROM t WHERE id = ?', [999]);

        self::assertTrue($run->hasRowset);
        self::assertCount(0, $run->result);
        self::assertSame(['id', 'name'], $run->result->columnNames());
    }

    public function testQueryBuiltResultHasNoColumnMetadata(): void
    {
        // query() does not fetch column metadata — columns() is empty there,
        // and the rows still key by column name as before.
        $result = $this->db->query("SELECT 1 AS one");

        self::assertSame([], $result->columns());
        self::assertSame([['one' => 1]], $result->rows());
    }
}
