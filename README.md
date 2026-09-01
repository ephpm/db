# ephpm/db

Base PHP library for [ePHPm](https://github.com/ephpm/ephpm)'s **in-process
database bridge**. It wraps the native `ephpm_db_query()` /
`ephpm_db_execute()` functions with a typed `Connection` facade, a real
exception hierarchy, and IDE stubs.

Unlike `pdo_mysql` pointed at `127.0.0.1:3306`, the bridge executes SQL
through a per-thread litewire session **inside the server process** — the same
backend the MySQL wire frontend serves, so MySQL-dialect SQL, `SHOW`/`DESCRIBE`
emulation, `SET NAMES` no-ops, and `BEGIN`/`COMMIT`/`ROLLBACK` all behave
exactly as they do over the wire, without a TCP round trip.

## Requirements

- PHP **8.2+**
- The application must be served by an **ePHPm** binary, **v0.6.3 or newer**
  (current release: v0.8.6 — the `ephpm_db_*` natives first shipped in
  v0.6.3), with an embedded database configured
  (`[db.sqlite]`). Outside that environment every entry point throws
  `Ephpm\Db\Exception\BridgeUnavailableException` with an actionable message.

## Installation

ePHPm packages are distributed via their GitHub repositories, not Packagist.
Add this repo as a Composer `vcs` repository; `ephpm/db` is tagged
(currently `v0.1.1`), so `^0.1` resolves:

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/ephpm/db" }
  ],
  "require": {
    "ephpm/db": "^0.1"
  }
}
```

## There is no connection

`Connection` is a **stateless facade** — no connection object exists behind
it, and there is nothing to open, close, or pool. The server maintains one
lazily-created database session **per PHP worker thread**; every
`ephpm_db_*` call on a thread goes through that thread's session.
Constructing two `Connection` instances gives you the same underlying
session.

The consequence that matters: **transaction state belongs to the worker
thread, not to the request.** The server protects the next request from your
mistakes: if a script leaves a transaction open at the end of a request, the
server **rolls it back at request end** (logging a warning server-side) —
abandoned writes are lost, never silently joined to an unrelated later
request. Don't lean on that safety net: commit or roll back explicitly,
preferably via `transaction()`, which guarantees it.

## Usage

### Queries

```php
use Ephpm\Db\Connection;

$db = new Connection();

$rows = $db->query('SELECT id, name FROM users WHERE role = ?', ['admin']);
foreach ($rows as $row) {          // Result is iterable and countable
    echo $row['name'];             // assoc rows, native int/float/null types
}
$rows->rows();                     // list<array<string, int|float|string|null>>
$rows->first();                    // first row or null
```

Rows are associative arrays keyed by column name; a duplicate column name
(`SELECT a, a`) keeps the last value, like `mysqli_fetch_assoc()`. Integer
and float columns come back as PHP `int`/`float`, SQL `NULL` as `null`, text
and blob columns as (binary-safe) strings.

### Writes

```php
$ok = $db->execute('INSERT INTO users (name) VALUES (?)', ['ada']);
$ok->affectedRows;   // 1
$ok->lastInsertId;   // the new row's id
```

A `SELECT` routed through `execute()` returns zeros rather than throwing; a
no-result-set statement routed through `query()` returns an empty `Result`.

### Conveniences

```php
$count = $db->scalar('SELECT COUNT(*) FROM users');            // first column, first row
$user  = $db->row('SELECT * FROM users WHERE id = ?', [42]);   // first row or null
$names = $db->column('SELECT name FROM users ORDER BY id');    // first column of every row
```

### Transactions

```php
$db->transaction(function (Connection $db) {
    $ok = $db->execute('INSERT INTO orders (user_id) VALUES (?)', [42]);
    $db->execute('INSERT INTO order_items (order_id) VALUES (?)', [$ok->lastInsertId]);
});
```

`transaction()` issues `BEGIN`, runs the callback, and issues `COMMIT`; if
the callback (or the commit) throws **anything**, it rolls back and rethrows
the original throwable. Manual `begin()` / `commit()` / `rollBack()` are also
available — they flow through as plain SQL on the thread's session. A
transaction you leave open is rolled back by the server at request end (with
a server-side warning), so finish it explicitly if you want the writes.

### Parameters

`?` placeholders bind `null`, `bool`, `int`, `float`, and `string` values
only — matching what the MySQL binary protocol can carry. `bool` binds as
1/0; a non-UTF-8 string binds as a BLOB. Any other type throws.

## Exceptions

All errors are `Ephpm\Db\Exception\DbException` (extends `RuntimeException`),
carrying `errno()` (the MySQL error number, also the exception code) and
`sqlstate()`. The native exception is preserved as `getPrevious()` and its
message (`SQLSTATE[xxxxx]: <backend message>`) is kept verbatim. The errno
selects the subclass:

| Exception | errno | SQLSTATE | Meaning |
|---|---|---|---|
| `DuplicateKeyException` | 1062 | 23000 | unique / primary key violation |
| `SyntaxException` | 1064 | 42000 | SQL syntax error |
| `LockTimeoutException` | 1205 | HY000 | lock wait timeout (usually retryable) |
| `ReadOnlyException` | 1290 | HY000 | write rejected — node is read-only (e.g. a replica) |
| `ForeignKeyException` | 1452 | 23000 | foreign key violation |
| `DbException` | 1105 / other | HY000 | anything else |
| `BridgeUnavailableException` | — | — | natives missing, or no `[db.sqlite]` configured |

```php
use Ephpm\Db\Exception\DuplicateKeyException;

try {
    $db->execute('INSERT INTO users (email) VALUES (?)', [$email]);
} catch (DuplicateKeyException) {
    // email already taken
}
```

## IDE stubs

The native function declarations live at `stubs/ephpm-db.stub.php` and are
intentionally **excluded from Composer's autoloader** — at runtime the real
functions are provided by the ePHPm engine, and loading the stub there would
redefine them and fatal.

- **PhpStorm** — the `stubs/` directory is indexed automatically once the
  package is in `vendor/`.
- **Psalm** — add to `psalm.xml`:
  ```xml
  <stubs>
      <file name="vendor/ephpm/db/stubs/ephpm-db.stub.php"/>
  </stubs>
  ```
- **PHPStan** — add to `phpstan.neon`:
  ```neon
  parameters:
      stubFiles:
          - vendor/ephpm/db/stubs/ephpm-db.stub.php
  ```

## Testing

```bash
composer install
vendor/bin/phpunit
```

The test suite polyfills the `ephpm_db_*` functions on top of the `sqlite3`
extension (see `tests/bootstrap.php`), mirroring the real bridge's row
shapes, parameter rules, transaction flow, and error format — so the full
wrapper logic can be exercised on a stock PHP CLI without an ePHPm binary.

## License

MIT — see [LICENSE](LICENSE).
