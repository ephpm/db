<?php

declare(strict_types=1);

namespace Ephpm\Db;

/**
 * The outcome of {@see Connection::run()} — the unified entry point over
 * the native `ephpm_db_run()` (ePHPm issue #263).
 *
 * Reports what the statement actually did, read from the executed
 * statement rather than inferred from the SQL text: `$hasRowset` is true
 * for a result set (its {@see Result} in `$result`), false for an OK
 * outcome (`$affectedRows`/`$lastInsertId`). A single `run()` therefore
 * classifies `WITH ... INSERT`, `INSERT ... RETURNING`, and `CALL`
 * correctly without the caller inspecting the first keyword. The Result's
 * column metadata is populated even for a zero-row result set.
 */
final class RunResult
{
    public function __construct(
        public readonly bool $hasRowset,
        public readonly Result $result,
        public readonly int $affectedRows,
        public readonly int $lastInsertId,
    ) {
    }
}
