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
 *   HTTP request. A transaction left open at the end of a request is
 *   rolled back by the server at request end (with a server-side warning
 *   log) — abandoned writes are lost, never leaked into a later request.
 *   Commit or roll back explicitly; prefer {@see transaction()} which
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
     * Execute SQL and report what it actually did — the unified entry
     * point over the native `ephpm_db_run()` (ePHPm issue #263).
     *
     * Runs the statement once and returns both the rows and the OK
     * metadata, so callers with a single query API never have to classify
     * the SQL by its first keyword to choose between {@see query()} and
     * {@see execute()}. {@see RunResult::$hasRowset} is the authoritative
     * discriminator, read from the executed statement. The returned
     * Result's column metadata is populated even for a zero-row result set
     * (ePHPm issue #262).
     *
     * Requires the native `ephpm_db_run()` (ePHPm >= v0.6.3). On an older
     * floor that lacks it, this composes the same answer from
     * {@see query()}/{@see execute()} by inspecting the leading keyword —
     * the exact classification `ephpm_db_run()` exists to make unnecessary,
     * kept only as a floor-preserving fallback.
     *
     * @param list<int|float|string|bool|null> $params values for `?` placeholders
     *
     * @throws DbException
     */
    public function run(string $sql, array $params = []): RunResult
    {
        if (!\function_exists('ephpm_db_run')) {
            return $this->runFallback($sql, $params);
        }

        /**
         * @var array{has_rowset: bool, rows: list<array<string, int|float|string|null>>, columns: list<array{name: string, type: ?string}>, affected_rows: int, last_insert_id: int} $out
         */
        $out = $this->call('ephpm_db_run', $sql, $params);

        return new RunResult(
            (bool) ($out['has_rowset'] ?? false),
            new Result($out['rows'] ?? [], $out['columns'] ?? []),
            (int) ($out['affected_rows'] ?? 0),
            (int) ($out['last_insert_id'] ?? 0),
        );
    }

    /**
     * Floor-preserving `run()` for an ePHPm without `ephpm_db_run()`.
     *
     * @param list<int|float|string|bool|null> $params
     *
     * @throws DbException
     */
    private function runFallback(string $sql, array $params): RunResult
    {
        $keyword = \strtoupper(\substr(\ltrim($sql), 0, 6));
        $looksLikeQuery = \str_starts_with($keyword, 'SELECT')
            || \str_starts_with($keyword, 'WITH')
            || \str_starts_with($keyword, 'PRAGMA')
            || \str_starts_with($keyword, 'SHOW')
            || \str_starts_with(\ltrim(\strtoupper($sql)), 'DESC');

        if ($looksLikeQuery) {
            $result = $this->query($sql, $params);

            return new RunResult(true, $result, 0, 0);
        }

        $ok = $this->execute($sql, $params);

        return new RunResult(false, new Result([]), $ok->affectedRows, $ok->lastInsertId);
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
     * This helper is the safest way to use transactions: the outcome is
     * always explicit. (A transaction left open at request end is rolled
     * back by the server as a safety net — with a server-side warning and
     * the writes lost — so relying on manual begin()/commit() and
     * forgetting the commit means silent data loss, not a leak.)
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
            // Only roll back if the session is actually still in a
            // transaction. After a failed BEGIN, or once the request-end
            // safety net has already rolled back, there is nothing to undo
            // and firing ROLLBACK blind would raise (and we would swallow) a
            // spurious "no transaction is active" error. ephpm_db_in_transaction()
            // reads the session's own flag (ePHPm issue #260); when the native
            // is absent (older ePHPm floor) we fall back to the best-effort
            // blind rollback that has always been safe here.
            if ($this->inTransaction()) {
                try {
                    $this->rollBack();
                } catch (\Throwable) {
                    // Rolling back a session whose transaction already died is
                    // best-effort; the caller's error is the one that matters.
                }
            }

            throw $e;
        }

        return $result;
    }

    /**
     * Whether this thread's session is currently inside an explicit
     * transaction.
     *
     * Uses the native {@see ephpm_db_in_transaction()} (ePHPm issue #260)
     * when present. On an older ePHPm that predates it the native is absent,
     * so this reports `true` to preserve the previous behaviour — a
     * best-effort rollback on any failure.
     */
    public function inTransaction(): bool
    {
        if (\function_exists('ephpm_db_in_transaction')) {
            return ephpm_db_in_transaction();
        }

        return true;
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
