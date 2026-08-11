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
