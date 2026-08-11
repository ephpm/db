<?php

declare(strict_types=1);

namespace Ephpm\Db\Exception;

/**
 * The in-process database bridge is not available.
 *
 * Thrown in two situations:
 *
 * - the native `ephpm_db_query()`/`ephpm_db_execute()` functions do not
 *   exist — the script is not running inside an ePHPm server at all; or
 * - the natives exist but the server has no embedded database configured
 *   (`[db.sqlite]` is not active), in which case they throw the
 *   "no embedded database is active" message that this class wraps.
 */
final class BridgeUnavailableException extends DbException
{
    /**
     * The native functions are not defined in this runtime.
     */
    public static function nativesMissing(): self
    {
        return new self(
            'ephpm/db requires the native ephpm_db_query()/ephpm_db_execute() functions, '
            . 'which only exist when the script is served by an ePHPm binary with an '
            . 'embedded database configured ([db.sqlite]). They are not defined in this runtime.'
        );
    }

    /**
     * The natives exist but reported that no embedded database is active.
     */
    public static function inactive(\Exception $previous): self
    {
        return new self(
            $previous->getMessage()
            . ' — the ePHPm server must be configured with [db.sqlite] for the '
            . 'in-process bridge to work.',
            0,
            'HY000',
            $previous,
        );
    }
}
