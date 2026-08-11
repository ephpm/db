<?php

declare(strict_types=1);

namespace Ephpm\Db\Exception;

/**
 * Base exception for all embedded-database errors.
 *
 * The native `ephpm_db_*` functions throw plain {@see \Exception}s shaped
 * like PDO's: message `SQLSTATE[xxxxx]: <backend message>`, code = the
 * mapped MySQL error number (e.g. 1062). {@see fromNative()} parses that
 * shape into this hierarchy at the wrapper boundary, keeping the original
 * exception as `$previous`.
 */
class DbException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $errno = 0,
        private readonly string $sqlstate = 'HY000',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errno, $previous);
    }

    /**
     * The MySQL error number (e.g. 1062 for a duplicate key), or 0 when
     * the native error carried no code.
     */
    public function errno(): int
    {
        return $this->errno;
    }

    /**
     * The five-character SQLSTATE (e.g. `23000`), parsed from the native
     * message's `SQLSTATE[xxxxx]: ` prefix. `HY000` when the message
     * carried no parseable prefix.
     */
    public function sqlstate(): string
    {
        return $this->sqlstate;
    }

    /**
     * Map a native `ephpm_db_*` exception into this hierarchy.
     *
     * - The "no embedded database is active" message becomes
     *   {@see BridgeUnavailableException}.
     * - Otherwise the errno (exception code) selects the subclass:
     *   1062 duplicate key, 1064 syntax, 1205 lock timeout, 1290
     *   read-only, 1452 foreign key; anything else (including litewire's
     *   generic 1105) stays a plain DbException.
     *
     * The original message is preserved verbatim and the native exception
     * is attached as `$previous`.
     */
    public static function fromNative(\Exception $e): self
    {
        $message = $e->getMessage();

        if (\str_contains($message, 'no embedded database is active')) {
            return BridgeUnavailableException::inactive($e);
        }

        $sqlstate = 'HY000';
        if (\preg_match('/^SQLSTATE\[([0-9A-Za-z]{5})\]: /', $message, $m) === 1) {
            $sqlstate = $m[1];
        }

        $errno = (int) $e->getCode();
        $class = match ($errno) {
            1062 => DuplicateKeyException::class,
            1064 => SyntaxException::class,
            1205 => LockTimeoutException::class,
            1290 => ReadOnlyException::class,
            1452 => ForeignKeyException::class,
            default => self::class,
        };

        return new $class($message, $errno, $sqlstate, $e);
    }
}
