<?php

declare(strict_types=1);

namespace Ephpm\Db;

/**
 * The OK metadata returned by {@see Connection::execute()}.
 *
 * Mirrors the native `ephpm_db_execute()` return shape
 * (`{affected_rows, last_insert_id}`). A SELECT routed through
 * execute() yields zeros for both fields rather than an error.
 */
final class ExecuteResult
{
    public function __construct(
        public readonly int $affectedRows,
        public readonly int $lastInsertId,
    ) {
    }
}
