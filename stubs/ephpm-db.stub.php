<?php

/**
 * @internal IDE stub — do not include at runtime.
 *
 * These declarations describe the native global functions that the ePHPm
 * engine registers when an embedded database (`[db.sqlite]`) is active.
 * This file exists PURELY for IDEs and static analysers (PhpStorm, Psalm,
 * PHPStan). It is never autoloaded and must never be `require`d at
 * runtime — doing so would redefine the native symbols and cause a fatal
 * error.
 *
 * Point your analyzer at the `stubs/` directory to pick these up.
 *
 * Semantics (authoritative source: crates/ephpm-php/ephpm_wrapper.c and
 * crates/ephpm-php/src/db_bridge.rs in github.com/ephpm/ephpm):
 *
 * - SQL executes through a **per-thread litewire session** — the same
 *   backend the MySQL wire frontend serves. MySQL-dialect SQL,
 *   SHOW/DESCRIBE emulation, SET-NAMES no-ops, and BEGIN/COMMIT/ROLLBACK
 *   as plain SQL all behave exactly as they do over the wire, without a
 *   TCP round trip.
 * - The session lives for the **worker thread**, not the request. A
 *   transaction left open at request end is rolled back by the server
 *   (`db_bridge::on_request_end()`, with a server-side warning log) —
 *   abandoned writes are lost, never joined to a later request. COMMIT or
 *   ROLLBACK explicitly.
 * - `?` placeholders bind null, bool, int, float, and string parameters
 *   only (bool binds as 1/0; a non-UTF-8 string binds as a BLOB). Any
 *   other parameter type throws.
 * - Errors throw plain {@see \Exception} shaped like PDO's: message
 *   `SQLSTATE[xxxxx]: <backend message>`, code = the mapped MySQL error
 *   number (1062 duplicate key, 1064 syntax, 1105 generic, 1205 lock
 *   timeout, 1290 read-only, 1452 foreign key). When no embedded
 *   database is configured, both functions throw
 *   "ephpm_db: no embedded database is active (requires [db.sqlite])".
 */

declare(strict_types=1);

/**
 * Execute SQL through the in-process bridge and return the rows.
 *
 * Rows come back as a list of associative arrays keyed by column name (a
 * duplicate column name — `SELECT a, a` — keeps the last value, like
 * `mysqli_fetch_assoc()`). Integer/float columns are PHP int/float, SQL
 * NULL is null, text and blob columns are (binary-safe) strings. A
 * statement with no result set (e.g. `SET NAMES` routed through the
 * query function) returns an empty array.
 *
 * @param string                                 $sql    SQL with `?` placeholders (MySQL dialect)
 * @param list<int|float|string|bool|null>       $params values for the placeholders
 *
 * @return list<array<string, int|float|string|null>> the result rows
 *
 * @throws \Exception on SQL errors (`SQLSTATE[xxxxx]: ...`, code = MySQL
 *                    errno) or when no embedded database is active
 */
function ephpm_db_query(string $sql, array $params = []): array
{
}

/**
 * Execute SQL through the in-process bridge and return the OK metadata.
 *
 * Transactions flow through as SQL — `BEGIN`/`COMMIT`/`ROLLBACK` behave
 * exactly as on the wire path (the per-thread session tracks the
 * transaction state). A SELECT routed through execute returns zeros
 * rather than throwing.
 *
 * @param string                                 $sql    SQL with `?` placeholders (MySQL dialect)
 * @param list<int|float|string|bool|null>       $params values for the placeholders
 *
 * @return array{affected_rows: int, last_insert_id: int}
 *
 * @throws \Exception on SQL errors (`SQLSTATE[xxxxx]: ...`, code = MySQL
 *                    errno) or when no embedded database is active
 */
function ephpm_db_execute(string $sql, array $params = []): array
{
}

/**
 * Execute SQL through the in-process bridge and report what it actually
 * did — the unified entry point (ePHPm issue #263).
 *
 * Runs the statement once and returns both the rows and the OK metadata,
 * so an adapter with a single `query()` API never has to classify the SQL
 * by its first keyword to choose between {@see ephpm_db_query()} and
 * {@see ephpm_db_execute()}. `has_rowset` is the authoritative
 * discriminator, read from the executed statement (not inferred from the
 * SQL text): `WITH ... INSERT`, `INSERT ... RETURNING`, and `CALL` all
 * classify correctly. `rows` is always an array (empty, never null, when
 * `has_rowset` is false). `columns` carries the column metadata even for a
 * zero-row result set (issue #262). `affected_rows`/`last_insert_id` are
 * zero for a result set.
 *
 * @param string                           $sql    SQL with `?` placeholders (MySQL dialect)
 * @param list<int|float|string|bool|null> $params values for the placeholders
 *
 * @return array{has_rowset: bool, rows: list<array<string, int|float|string|null>>, columns: list<array{name: string, type: ?string}>, affected_rows: int, last_insert_id: int}
 *
 * @throws \Exception on SQL errors (`SQLSTATE[xxxxx]: ...`, code = MySQL
 *                    errno) or when no embedded database is active
 */
function ephpm_db_run(string $sql, array $params = []): array
{
}

/**
 * Column metadata of the LAST `ephpm_db_*` statement on this thread, as a
 * list of `['name' => string, 'type' => ?string]` (ePHPm issue #262).
 *
 * Empty list when that statement produced no result set, when nothing has
 * run yet, or when no embedded database is active. `type` is the column's
 * DECLARED schema type; it is null both for a column with no declared type
 * and for an expression (`SELECT a + 1`). Exists because a zero-row result
 * cannot carry its own column names: {@see ephpm_db_query()} returns `[]`
 * and the names go with it.
 *
 * Reads metadata only — never runs SQL, never disturbs the staged
 * result/error, and never throws.
 *
 * @return list<array{name: string, type: ?string}>
 */
function ephpm_db_columns(): array
{
}

/**
 * Whether THIS THREAD's session is inside an explicit transaction
 * (ePHPm issue #260).
 *
 * Reads the session's own flag — the same state the MySQL wire frontend
 * reports as `SERVER_STATUS_IN_TRANS` — rather than guessing from the
 * statements that have gone past. False when the thread has no session yet
 * or no embedded database is active (in both cases no transaction can be
 * open, so it is not a guess). Never runs SQL and never throws.
 */
function ephpm_db_in_transaction(): bool
{
}

/**
 * Whether an `ephpm_db_*` statement issued right now would reach a
 * database (ePHPm issue #259) — a backend is registered AND, in per-site
 * mode, this request has a tenant identity.
 *
 * The pre-flight form of the reserved-code contract: rules out
 * `EPHPM_DB_ERR_UNAVAILABLE` (2000) and `EPHPM_DB_ERR_NO_SITE_CONTEXT`
 * (2001) without running a probe query. It does NOT open the database, so
 * a true result can still be followed by a connect failure if the storage
 * underneath is broken. Distinct from `function_exists('ephpm_db_query')`,
 * which only tells you that you are running inside ePHPm. Never throws.
 */
function ephpm_db_available(): bool
{
}

/**
 * Error code of the last `ephpm_db_*` statement on this thread, or 0 if it
 * succeeded (or none has run). Survives the exception being caught
 * (ePHPm issue #259).
 *
 * A SQL failure reports the mapped MySQL SERVER error code (1062, 1064,
 * 1105, ...). An infrastructure failure reports one of the reserved
 * `EPHPM_DB_ERR_*` codes from the CLIENT range (2000-2999), which a server
 * never emits — that separation is the stable "bridge problem vs. your
 * SQL" signal, replacing message-text matching. Cleared by the next
 * statement on this thread and at request end. Never throws.
 */
function ephpm_db_errno(): int
{
}

/**
 * The last error in parts, or null when the last statement succeeded
 * (ePHPm issue #259).
 *
 * The structured companion to {@see ephpm_db_errno()}, for
 * `mysqli_error()` / `mysqli_sqlstate()` / `PDO::errorInfo()`-shaped
 * adapter APIs. `message` is the backend message on its own — the
 * `SQLSTATE[xxxxx]: ` prefix belongs to the exception's composed message,
 * not to this. Never throws.
 *
 * @return array{code: int, sqlstate: string, message: string}|null
 */
function ephpm_db_error(): ?array
{
}
