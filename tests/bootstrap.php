<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * ePHPm's `ephpm_db_*` functions only exist inside the ePHPm SAPI, so when
 * the suite runs under a stock PHP CLI we polyfill them on top of the
 * `sqlite3` extension. This lets every branch of the wrapper be exercised
 * offline while remaining behaviourally faithful to the real bridge:
 *
 *  - rows are a list of assoc arrays; a duplicate column name keeps the
 *    last value; int/float columns are native PHP int/float, NULL is
 *    null, text/blob are strings;
 *  - a statement with no result set returns [] from the query function;
 *  - execute() returns {affected_rows, last_insert_id}, zeros for a
 *    SELECT;
 *  - `?` placeholders bind null/bool/int/float/string only — anything
 *    else throws the native "unsupported parameter type" \Exception;
 *  - BEGIN/COMMIT/ROLLBACK flow through as plain SQL on one shared
 *    session (the fake's single SQLite3 handle mirrors the real bridge's
 *    per-thread session — PHPUnit is single-threaded);
 *  - errors throw plain \Exception with message "SQLSTATE[xxxxx]: <msg>"
 *    and code = the mapped MySQL errno (1062/1064/1205/1290/1452, 1105
 *    fallback), the same mapping litewire applies;
 *  - EphpmDbFake::$unavailable simulates a server without [db.sqlite]:
 *    both natives throw the real "no embedded database is active"
 *    message.
 *
 * If the real SAPI functions are already present (i.e. the suite is
 * somehow run inside ePHPm) we defer to them and define nothing.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (!extension_loaded('sqlite3')) {
    fwrite(STDERR, "The test polyfill requires the sqlite3 extension.\n");
    exit(1);
}

if (!function_exists('ephpm_db_query')) {

    /**
     * Backing store shared by the fake native functions: one SQLite3
     * handle, mirroring the real bridge's one-session-per-thread model.
     */
    final class EphpmDbFake
    {
        /** Simulate a server with no [db.sqlite] configured. */
        public static bool $unavailable = false;

        private static ?\SQLite3 $db = null;

        /** This thread's explicit-transaction flag (issue #260). */
        private static bool $inTransaction = false;

        /**
         * Column metadata of the last statement (issue #262), kept after
         * the rows are released so a zero-row SELECT still reports its
         * column names.
         *
         * @var list<array{name: string, type: ?string}>
         */
        private static array $lastColumns = [];

        /** Did the last statement produce a result set? (issue #263) */
        private static bool $lastHadRowset = false;

        /**
         * How many ROLLBACK statements reached the bridge since reset().
         * Lets a test assert that Connection::transaction() no longer fires
         * ROLLBACK blind after the session already left its transaction.
         */
        public static int $rollbackAttempts = 0;

        /** Drop all state: fresh in-memory database, bridge available. */
        public static function reset(): void
        {
            if (self::$db !== null) {
                self::$db->close();
            }
            self::$db = null;
            self::$unavailable = false;
            self::$inTransaction = false;
            self::$lastColumns = [];
            self::$lastHadRowset = false;
            self::$rollbackAttempts = 0;
        }

        public static function inTransaction(): bool
        {
            return self::$inTransaction;
        }

        /** @return list<array{name: string, type: ?string}> */
        public static function lastColumns(): array
        {
            return self::$lastColumns;
        }

        public static function lastHadRowset(): bool
        {
            return self::$lastHadRowset;
        }

        public static function db(): \SQLite3
        {
            if (self::$db === null) {
                self::$db = new \SQLite3(':memory:');
                self::$db->enableExceptions(true);
                // The MySQL dialect the real bridge speaks always enforces
                // foreign keys; SQLite needs the pragma.
                self::$db->exec('PRAGMA foreign_keys = ON');
            }

            return self::$db;
        }

        /**
         * Prepare + bind + execute, mirroring the native parameter and
         * error contracts.
         *
         * @param list<mixed> $params
         */
        public static function run(string $sql, array $params): \SQLite3Result
        {
            if (str_starts_with(strtoupper(ltrim($sql)), 'ROLLBACK')) {
                self::$rollbackAttempts++;
            }

            if (self::$unavailable) {
                throw new \Exception(
                    'ephpm_db: no embedded database is active (requires [db.sqlite])'
                );
            }

            foreach ($params as $p) {
                if ($p !== null && !is_bool($p) && !is_int($p) && !is_float($p) && !is_string($p)) {
                    throw new \Exception(sprintf(
                        'ephpm_db: unsupported parameter type %s (only null, bool, '
                        . 'int, float, and string parameters bind)',
                        get_debug_type($p),
                    ));
                }
            }

            try {
                $stmt = self::db()->prepare($sql);
                $i = 1;
                foreach ($params as $p) {
                    match (true) {
                        $p === null => $stmt->bindValue($i, null, SQLITE3_NULL),
                        is_bool($p) => $stmt->bindValue($i, $p ? 1 : 0, SQLITE3_INTEGER),
                        is_int($p) => $stmt->bindValue($i, $p, SQLITE3_INTEGER),
                        is_float($p) => $stmt->bindValue($i, $p, SQLITE3_FLOAT),
                        default => $stmt->bindValue(
                            $i,
                            $p,
                            preg_match('//u', $p) === 1 ? SQLITE3_TEXT : SQLITE3_BLOB,
                        ),
                    };
                    $i++;
                }
                $result = $stmt->execute();
                if ($result === false) {
                    throw new \Exception(self::db()->lastErrorMsg());
                }

                self::recordMetadata($sql, $result);

                return $result;
            } catch (\Exception $e) {
                throw self::mapError($e);
            }
        }

        /**
         * Capture the last statement's column metadata / rowset flag and
         * advance the transaction flag — the state the real bridge keeps in
         * its per-thread session (issues #260, #262, #263).
         */
        private static function recordMetadata(string $sql, \SQLite3Result $result): void
        {
            $ncols = $result->numColumns();
            self::$lastHadRowset = $ncols > 0;

            $columns = [];
            for ($c = 0; $c < $ncols; $c++) {
                // SQLite exposes no declared type through the sqlite3
                // extension, and the real bridge reports null for an
                // expression column too — so null is faithful here.
                $columns[] = ['name' => $result->columnName($c), 'type' => null];
            }
            self::$lastColumns = $columns;

            $keyword = strtoupper(substr(ltrim($sql), 0, 5));
            if (str_starts_with($keyword, 'BEGIN') || str_starts_with($keyword, 'START')) {
                self::$inTransaction = true;
            } elseif (str_starts_with($keyword, 'COMMI') || str_starts_with($keyword, 'ROLLB')) {
                self::$inTransaction = false;
            }
        }

        /**
         * Map a SQLite error onto the native error shape: message
         * "SQLSTATE[xxxxx]: <msg>", code = MySQL errno — the same mapping
         * litewire's session applies for the real bridge.
         */
        private static function mapError(\Exception $e): \Exception
        {
            $msg = preg_replace(
                '/^Unable to (?:prepare|execute) statement: (?:\d+, )?/',
                '',
                $e->getMessage(),
            ) ?? $e->getMessage();

            [$errno, $sqlstate] = match (true) {
                str_contains($msg, 'UNIQUE constraint failed') => [1062, '23000'],
                str_contains($msg, 'syntax error') => [1064, '42000'],
                str_contains($msg, 'FOREIGN KEY constraint failed') => [1452, '23000'],
                str_contains($msg, 'database is locked') => [1205, 'HY000'],
                str_contains($msg, 'readonly database') => [1290, 'HY000'],
                default => [1105, 'HY000'],
            };

            return new \Exception("SQLSTATE[{$sqlstate}]: {$msg}", $errno);
        }
    }

    function ephpm_db_query(string $sql, array $params = []): array
    {
        $result = EphpmDbFake::run($sql, $params);

        // No result set (DDL, INSERT, SET routed through query, ...) -> [].
        if ($result->numColumns() === 0) {
            $result->finalize();

            return [];
        }

        $rows = [];
        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }
        $result->finalize();

        return $rows;
    }

    function ephpm_db_execute(string $sql, array $params = []): array
    {
        $result = EphpmDbFake::run($sql, $params);
        $hasRows = $result->numColumns() > 0;
        $result->finalize();

        if ($hasRows) {
            // A SELECT routed through execute returns zeros, not an error.
            return ['affected_rows' => 0, 'last_insert_id' => 0];
        }

        return [
            'affected_rows' => EphpmDbFake::db()->changes(),
            'last_insert_id' => EphpmDbFake::db()->lastInsertRowID(),
        ];
    }

    /**
     * The unified entry point (issue #263): run once, report both the rows
     * and the OK metadata, plus the authoritative has_rowset flag read from
     * the executed statement.
     */
    function ephpm_db_run(string $sql, array $params = []): array
    {
        $result = EphpmDbFake::run($sql, $params);
        $hasRowset = EphpmDbFake::lastHadRowset();

        $rows = [];
        if ($hasRowset) {
            while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                $rows[] = $row;
            }
        }
        $result->finalize();

        return [
            'has_rowset' => $hasRowset,
            'rows' => $rows,
            'columns' => EphpmDbFake::lastColumns(),
            'affected_rows' => $hasRowset ? 0 : EphpmDbFake::db()->changes(),
            'last_insert_id' => $hasRowset ? 0 : EphpmDbFake::db()->lastInsertRowID(),
        ];
    }

    /**
     * Column metadata of the last statement (issue #262) — valid even after
     * a zero-row result set, whose rows cannot carry the column names.
     */
    function ephpm_db_columns(): array
    {
        return EphpmDbFake::lastColumns();
    }

    /** This thread's explicit-transaction flag (issue #260). */
    function ephpm_db_in_transaction(): bool
    {
        return EphpmDbFake::inTransaction();
    }

    /** Whether a statement issued now would reach a database (issue #259). */
    function ephpm_db_available(): bool
    {
        return !EphpmDbFake::$unavailable;
    }
}
