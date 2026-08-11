<?php

declare(strict_types=1);

namespace Ephpm\Db\Exception;

/**
 * SQL syntax error (or SQL that failed to parse/translate).
 *
 * MySQL error 1064, SQLSTATE 42000.
 */
final class SyntaxException extends DbException
{
}
