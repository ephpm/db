<?php

declare(strict_types=1);

namespace Ephpm\Db\Exception;

/**
 * Write rejected because the database is read-only — typically because
 * this ePHPm node is currently a replica in a clustered deployment.
 *
 * MySQL error 1290, SQLSTATE HY000.
 */
final class ReadOnlyException extends DbException
{
}
