<?php

declare(strict_types=1);

namespace Ephpm\Db\Exception;

/**
 * Foreign key constraint violation.
 *
 * MySQL error 1452, SQLSTATE 23000.
 */
final class ForeignKeyException extends DbException
{
}
