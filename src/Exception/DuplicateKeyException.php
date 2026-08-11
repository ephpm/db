<?php

declare(strict_types=1);

namespace Ephpm\Db\Exception;

/**
 * Duplicate key: a unique or primary key constraint was violated.
 *
 * MySQL error 1062, SQLSTATE 23000.
 */
final class DuplicateKeyException extends DbException
{
}
