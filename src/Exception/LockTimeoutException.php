<?php

declare(strict_types=1);

namespace Ephpm\Db\Exception;

/**
 * Lock wait timeout: the statement waited too long for a competing
 * transaction's lock (SQLite "database is locked" surfaces this way).
 *
 * MySQL error 1205, SQLSTATE HY000. Usually retryable.
 */
final class LockTimeoutException extends DbException
{
}
