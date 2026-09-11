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
     * @param list<array{name: string, type: ?string}>   $columns column
     *        metadata for this result set, in select order. Carries the
     *        column names even for a zero-row result set (ePHPm issue #262),
     *        which the rows alone cannot. Empty when unknown (a plain
     *        {@see Connection::query()} does not fetch it).
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $columns = [],
    ) {
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
     * Column metadata for this result set as a list of
     * `['name' => string, 'type' => ?string]`, in select order.
     *
     * Populated by {@see Connection::run()} (and available even when the
     * result matched zero rows, ePHPm issue #262). Empty for a Result built
     * by {@see Connection::query()}, which does not fetch column metadata.
     *
     * @return list<array{name: string, type: ?string}>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * The column names in select order.
     *
     * @return list<string>
     */
    public function columnNames(): array
    {
        return \array_map(static fn (array $c): string => $c['name'], $this->columns);
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
