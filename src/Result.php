<?php

declare(strict_types=1);

namespace Ephpm\Db;

/**
 * The rows returned by {@see Connection::query()}.
 *
 * A thin, immutable wrapper over the native result shape: a list of
 * associative arrays keyed by column name. Integer/float columns are PHP
 * int/float, SQL NULL is null, text and blob columns are (binary-safe)
 * strings. A duplicate column name (`SELECT a, a`) keeps the last value,
 * like `mysqli_fetch_assoc()`.
 *
 * @implements \IteratorAggregate<int, array<string, int|float|string|null>>
 */
final class Result implements \IteratorAggregate, \Countable
{
    /**
     * @param list<array<string, int|float|string|null>> $rows
     */
    public function __construct(private readonly array $rows)
    {
    }

    /**
     * All rows as a list of associative arrays.
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * The first row, or null when the result is empty.
     *
     * @return array<string, int|float|string|null>|null
     */
    public function first(): ?array
    {
        return $this->rows[0] ?? null;
    }

    public function count(): int
    {
        return \count($this->rows);
    }

    /**
     * @return \ArrayIterator<int, array<string, int|float|string|null>>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->rows);
    }
}
