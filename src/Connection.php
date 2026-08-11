<?php

declare(strict_types=1);

namespace Ephpm\Db;

use Ephpm\Db\Exception\BridgeUnavailableException;
use Ephpm\Db\Exception\DbException;

/**
 * Typed wrapper over ePHPm's in-process database bridge.
 *
 * This class is a **stateless facade** — no connection object actually
 * exists behind it. The native `ephpm_db_query()`/`ephpm_db_execute()`
 * functions execute SQL through a per-thread litewire session inside the
 * ePHPm server: one session per PHP worker OS thread, created lazily on
 * first use and managed entirely by the server. Constructing two
 * Connection instances gives you the same underlying session; there is
 * nothing to open, close, or pool.
 *
 * Consequences of the per-thread session model:
 *
 * - Transaction state (`BEGIN`/`COMMIT`/`ROLLBACK`, which flow through as
 *   plain SQL) belongs to the worker thread, not to this object or to the
 *   HTTP request. A transaction left open at the end of a request stays
 *   open on that thread until its next `ephpm_db_*` call — always commit
 *   or roll back before returning; prefer {@see transaction()} which
 *   guarantees it.
 * - The SQL dialect is MySQL: `SHOW`/`DESCRIBE` are emulated,
 *   `SET NAMES` is a no-op, and `?` placeholders bind null, bool, int,
 *   float, and string parameters (bool binds as 1/0; a non-UTF-8 string
 *   binds as a blob).
 */
final class Connection
{
    /**
     * @throws BridgeUnavailableException when the native bridge functions
     *         are not defined (not running under ePHPm)
     */
    public function __construct()
    {
        if (!\function_exists('ephpm_db_query') || !\function_exists('ephpm_db_execute')) {
            throw BridgeUnavailableException::nativesMissing();
        }
    }

    /**
     * Execute SQL and return the rows.
     *
     * A statement with no result set (e.g. `SET NAMES` routed through
     * query) yields an empty Result, not an error.
     *
     * @param list<int|float|string|bool|null> $params values for `?` placeholders
     *
     * @throws DbException
     */
    public function query(string $sql, array $params = []): Result
    {
        /** @var list<array<string, int|float|string|null>> $rows */
        $rows = $this->call('ephpm_db_query', $sql, $params);

        return new Result($rows);
    }

    /**
     * Execute SQL and return the OK metadata (affected rows, last insert
     * id). A SELECT routed through execute() returns zeros rather than
     * throwing.
     *
     * @param list<int|float|string|bool|null> $params values for `?` placeholders
     *
     * @throws DbException
     */
    public function execute(string $sql, array $params = []): ExecuteResult
    {
        $ok = $this->call('ephpm_db_execute', $sql, $params);

        return new ExecuteResult(
            (int) ($ok['affected_rows'] ?? 0),
            (int) ($ok['last_insert_id'] ?? 0),
        );
    }

    /**
     * The first column of the first row, or null when the query returns
     * no rows.
     *
     * @param list<int|float|string|bool|null> $params
     *
     * @throws DbException
     */
    public function scalar(string $sql, array $params = []): int|float|string|null
    {
        $row = $this->row($sql, $params);
        if ($row === null || $row === []) {
            return null;
        }

        return $row[\array_key_first($row)];
    }

    /**
     * The first row as an associative array, or null when the query
     * returns no rows.
     *
     * @param list<int|float|string|bool|null> $params
     *
     * @return array<string, int|float|string|null>|null
     *
     * @throws DbException
     */
    public function row(string $sql, array $params = []): ?array
    {
        return $this->query($sql, $params)->first();
    }

    /**
     * The first column of every row, as a list.
     *
     * @param list<int|float|string|bool|null> $params
     *
     * @return list<int|float|string|null>
     *
     * @throws DbException
     */
    public function column(string $sql, array $params = []): array
    {
        $out = [];
        foreach ($this->query($sql, $params) as $row) {
            $out[] = $row === [] ? null : $row[\array_key_first($row)];
        }

        return $out;
    }

    /**
     * Run `$fn` inside a transaction.
     *
     * Issues `BEGIN`, invokes `$fn($this)`, and issues `COMMIT`. If `$fn`
     * (or the COMMIT) throws anything, the transaction is rolled back and
     * the original throwable is rethrown. Returns whatever `$fn` returned.
     *
     * Because the underlying session is per worker thread — not per
     * request — this helper is the safest way to use transactions: it
     * guarantees the thread's session never leaks an open transaction
     * into a later request.
     *
     * @template T
     *
     * @param callable(self): T $fn
     *
     * @return T
     *
     * @throws \Throwable whatever $fn threw, after rolling back
     */
    public function transaction(callable $fn): mixed
    {
        $this->begin();

        try {
            $result = $fn($this);
            $this->commit();
        } catch (\Throwable $e) {
            try {
                $this->rollBack();
            } catch (\Throwable) {
                // Rolling back a session whose transaction already died is
                // best-effort; the caller's error is the one that matters.
            }

            throw $e;
        }

        return $result;
    }

    /**
     * Start a transaction (`BEGIN` as plain SQL on this thread's session).
     *
     * @throws DbException
     */
    public function begin(): void
    {
        $this->execute('BEGIN');
    }

    /**
     * Commit the current transaction.
     *
     * @throws DbException
     */
    public function commit(): void
    {
        $this->execute('COMMIT');
    }

    /**
     * Roll back the current transaction.
     *
     * @throws DbException
     */
    public function rollBack(): void
    {
        $this->execute('ROLLBACK');
    }

    /**
     * Invoke a native bridge function, mapping its plain \Exception into
     * the {@see DbException} hierarchy at this boundary.
     *
     * @param list<int|float|string|bool|null> $params
     *
     * @return array<array-key, mixed>
     */
    private function call(string $native, string $sql, array $params): array
    {
        try {
            /** @var array<array-key, mixed> */
            return $native($sql, $params);
        } catch (DbException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw DbException::fromNative($e);
        }
    }
}
