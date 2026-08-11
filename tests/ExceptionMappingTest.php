<?php

declare(strict_types=1);

namespace Ephpm\Db\Tests;

use Ephpm\Db\Connection;
use Ephpm\Db\Exception\BridgeUnavailableException;
use Ephpm\Db\Exception\DbException;
use Ephpm\Db\Exception\DuplicateKeyException;
use Ephpm\Db\Exception\ForeignKeyException;
use Ephpm\Db\Exception\LockTimeoutException;
use Ephpm\Db\Exception\ReadOnlyException;
use Ephpm\Db\Exception\SyntaxException;
use PHPUnit\Framework\TestCase;

final class ExceptionMappingTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        \EphpmDbFake::reset();
        $this->db = new Connection();
    }

    public function testDuplicateKeyMapsTo1062(): void
    {
        $this->db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT UNIQUE)');
        $this->db->execute('INSERT INTO t (name) VALUES (?)', ['a']);

        try {
            $this->db->execute('INSERT INTO t (name) VALUES (?)', ['a']);
            self::fail('expected DuplicateKeyException');
        } catch (DuplicateKeyException $e) {
            self::assertSame(1062, $e->errno());
            self::assertSame(1062, $e->getCode());
            self::assertSame('23000', $e->sqlstate());
            self::assertStringStartsWith('SQLSTATE[23000]: ', $e->getMessage());
            self::assertInstanceOf(\Exception::class, $e->getPrevious());
        }
    }

    public function testSyntaxErrorMapsTo1064(): void
    {
        try {
            $this->db->query('SELEC 1');
            self::fail('expected SyntaxException');
        } catch (SyntaxException $e) {
            self::assertSame(1064, $e->errno());
            self::assertSame('42000', $e->sqlstate());
            self::assertStringStartsWith('SQLSTATE[42000]: ', $e->getMessage());
        }
    }

    public function testForeignKeyViolationMapsTo1452(): void
    {
        $this->db->execute('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
        $this->db->execute(
            'CREATE TABLE child (id INTEGER PRIMARY KEY, pid INTEGER REFERENCES parent(id))'
        );

        try {
            $this->db->execute('INSERT INTO child (pid) VALUES (?)', [999]);
            self::fail('expected ForeignKeyException');
        } catch (ForeignKeyException $e) {
            self::assertSame(1452, $e->errno());
            self::assertSame('23000', $e->sqlstate());
        }
    }

    public function testLockTimeoutShapeMapsTo1205(): void
    {
        $e = DbException::fromNative(
            new \Exception('SQLSTATE[HY000]: database is locked', 1205),
        );

        self::assertInstanceOf(LockTimeoutException::class, $e);
        self::assertSame(1205, $e->errno());
        self::assertSame('HY000', $e->sqlstate());
    }

    public function testReadOnlyShapeMapsTo1290(): void
    {
        $e = DbException::fromNative(new \Exception(
            'SQLSTATE[HY000]: The MySQL server is running with the --read-only option '
            . 'so it cannot execute this statement',
            1290,
        ));

        self::assertInstanceOf(ReadOnlyException::class, $e);
        self::assertSame(1290, $e->errno());
        self::assertSame('HY000', $e->sqlstate());
    }

    public function testGeneric1105FallsBackToDbException(): void
    {
        $e = DbException::fromNative(
            new \Exception('SQLSTATE[HY000]: something backend-specific', 1105),
        );

        self::assertSame(DbException::class, $e::class);
        self::assertSame(1105, $e->errno());
        self::assertSame('HY000', $e->sqlstate());
    }

    public function testMessageWithoutSqlstatePrefixFallsBackToHY000(): void
    {
        $e = DbException::fromNative(new \Exception('completely unshaped error', 7));

        self::assertSame(DbException::class, $e::class);
        self::assertSame(7, $e->errno());
        self::assertSame('HY000', $e->sqlstate());
        self::assertSame('completely unshaped error', $e->getMessage());
    }

    public function testUnsupportedParameterTypeSurfacesAsDbException(): void
    {
        try {
            $this->db->query('SELECT ?', [[1, 2]]);
            self::fail('expected DbException');
        } catch (DbException $e) {
            self::assertSame(DbException::class, $e::class);
            self::assertStringContainsString('unsupported parameter type array', $e->getMessage());
        }
    }

    public function testOriginalExceptionIsKeptAsPrevious(): void
    {
        $native = new \Exception('SQLSTATE[23000]: UNIQUE constraint failed: t.name', 1062);

        $e = DbException::fromNative($native);

        self::assertSame($native, $e->getPrevious());
        self::assertSame($native->getMessage(), $e->getMessage());
    }

    public function testNoEmbeddedDatabaseMessageMapsToBridgeUnavailable(): void
    {
        \EphpmDbFake::$unavailable = true;

        try {
            $this->db->query('SELECT 1');
            self::fail('expected BridgeUnavailableException');
        } catch (BridgeUnavailableException $e) {
            self::assertStringContainsString('[db.sqlite]', $e->getMessage());
            self::assertInstanceOf(\Exception::class, $e->getPrevious());
        }
    }
}
