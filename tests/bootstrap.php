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

        /** Drop all state: fresh in-memory database, bridge available. */
        public static function reset(): void
        {
            if (self::$db !== null) {
                self::$db->close();
            }
            self::$db = null;
            self::$unavailable = false;
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

                return $result;
            } catch (\Exception $e) {
                throw self::mapError($e);
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
}
