# AGENTS.md

Guidance for agents and developers working in this repository and in applications that depend on it.

## Model Updates

- Do not add new helper, relation, query-builder, or compatibility classes when migrating or updating project models.
- Use `codeit/base` table classes directly:
  - `CodeIT\Model\AppTable` for normal table models.
  - `CodeIT\Model\CachedTable` for models whose rows should be cached.
- Follow the `codeit/base` reference documentation in `vendor/codeit/base/docs/reference.md`.
- Keep relation and computed-field hydration inside the model's `getUncached()` method when using `CachedTable`.
- Do not use joins or handwritten SQL to hydrate model relations; load related rows through existing model `get()`/`find()` calls inside `getUncached()`.
- Prefer existing `AppTable`/`CachedTable` methods such as `get()`, `find()`, `findSimple()`, `insert()`, `create()`, `update()`, `set()`, `delete()`, and `deleteById()` instead of inventing a parallel model API.
- Use `find()` conditions in the documented `AppTable` format:

```php
['id', '>=', '135']
['user.name', 'LIKE', "{$fullName}%"]
['status', 'IN', ['active', 'pending']]
['subscription', 'IS', null]
```

Example table model:

```php
<?php

namespace Application\Model;

use CodeIT\Model\AppTable;

class UserTable extends AppTable
{
    public function __construct($userId = null)
    {
        parent::__construct('user', $userId);
    }
}
```

Example search:

```php
$socialnetworkTable = new \Application\Model\User\SocialnetworkTable();
$socialnetworkProfiles = $socialnetworkTable->find([
    ['userId', '=', $id],
], false, 0);
```

## Cached Tables

`CodeIT\Model\CachedTable` keeps one cache entry per row. Everything else — reading one row, reading
many rows, invalidating them — is built on that single entry, so there is exactly one place a row is
loaded from the database and exactly one place it is cached.

### Reading

- Read a row with the constructor (`new NewsTable($id)`) or with `get($id)`. Nothing else.
- **Never override `get()` in a model.** `CachedTable::get()` is the method that consults the cache,
  takes the row lock, and stores the result. A model that overrides it either bypasses the cache or
  caches an incomplete row.
- Put every relation, computed field, and derived value in `getUncached()`. It runs once per cache
  miss, and whatever it returns is what the cache holds:

```php
class OrdersTable extends CachedTable
{
    public function getUncached($id)
    {
        $order = parent::getUncached($id);
        $order->items = (new OrderItemsTable())->find([['order_id', '=', (int) $id]]);
        return $order;
    }
}
```

- Read many rows with `mget($ids)` or `find($params, ...)`. Both issue **one batched cache request**
  (`MGET` on Redis, a multi-key `get()` on Memcached) for the whole set and only fall back to `get()`
  for the ids the cache did not hold. Never loop over ids calling `get()` yourself.
- A row that disappeared between the id lookup and the fetch is skipped, not deleted. `mget()` never
  writes to the database.
- Pass `$publicOnly = true` to strip `$privateFields` from the result. The cache always keeps the
  complete row, so a public read can never hide fields from a later internal one.

### Cached find()

`find()` caches the **matching ids and the total**, never the hydrated rows. Rows stay in their own
per-row entries, so editing a row is visible in every cached list at once and no list has to be
rebuilt for it.

It is opt-in per model, because an application that writes to the table with raw SQL has to keep the
default:

```php
class NewsTable extends CachedTable
{
    protected $findCacheTtl = 300; // seconds; 0 (default) queries the ids every time
}
```

Invalidation uses a generation token stored at `table.<name>.findgen`. `insert()`, `update()`,
`delete()`, `deleteById()`, and `LocalizableTable::updateLocData()` replace it, which makes every
cached list of that table unreachable in a single cache write — no `KEYS`, no `SCAN`, and it behaves
the same on Redis and Memcached. Orphaned entries fall out on their own TTL.

Call `invalidateFindCache()` yourself only after a change that never went through those methods, for
example when a model writes rows with raw SQL.

### Scoping rows

A table that caches a row per language, per tenant, or per any other scope overrides two hooks so
that batched reads land on the same keys single reads write. `LocalizableTable` is the worked example:

```php
protected function itemCacheKey($id)      // key used by get() and mget()
{
    return $this->cacheKey($id . '.' . $this->lang);
}

protected function findCacheScope()       // extra parts of the find() cache key
{
    return [$this->lang];
}
```

`LocalizableTable` fills `$this->lang` from `CodeIT\Utils\Registry::get('lang')` when the
application registers that key, and leaves it at `1` when it does not.

### Cache backend

- `CodeIT\Cache\Redis` supports everything: batched reads, key deletion, and mask deletion
  (`SCAN`-based, so it does not block the server).
- `CodeIT\Cache\Memcache` supports batched reads and key deletion. It cannot delete by mask, so
  `LocalizableTable` requires Redis; plain `CachedTable`, including cached `find()`, works on either.

## Tests

```bash
composer install
composer test
```

Model tests run without a database: subclass the table under test, stub `findSimple()` and
`getUncached()`, and hand it the in-memory cache from `tests/Model/FakeCache.php`. See
`tests/Model/CachedTableTest.php`.

## Benchmarking

```bash
php bin/benchmark.php --cycles=3000 --rows=50 --redis-db=14
php bin/benchmark.php --cycles=3000 --dsn="mysql:host=127.0.0.1;port=3306" --user=root --password=root
```

The report puts cache commands per cycle next to the time, because round trips are what this
library costs: on a loopback Redis a round trip is ~0.6 ms while unserialising a 615-byte row is
0.0016 ms. Optimise the command count, never the PHP. Read p99 rather than the mean — a lock or a
stampede shows in the tail first. The `--dsn` form adds the lock comparison; the Redis database is
flushed, so point it at a scratch index.

## Deferred reads

Reads registered with a `BatchLoader` are served together instead of one round trip each. Measured
over 2000 cycles, five warm lists cost 10 cache commands and 6.04 ms one at a time, and 2 commands
and 1.66 ms deferred.

```php
$loader = new BatchLoader($cache);
$news = $newsTable->deferFind($loader, [['enabled', '=', 1]], 3, 0, 'added desc');
$shop = $shopTable->deferFind($loader, [['enabled', '=', 1]], 4);
$rows = $news->rows();   // one request for both ID lists, one for all rows
```

Nothing is fetched until a handle is read, and reading any handle resolves them all. `deferMget()`
does the same for known ids. A `find()` without `findCacheTtl` still runs its own ID query when the
handle is read: SQL cannot be batched across tables without an event loop, and this library stays
blocking on purpose.

Do not hold a loader across requests, and do not register a read whose result decides whether you
register another — that needs two loaders.

## Locking

Two different things are called locking here, and only one of them is about correctness.

- **`AppTable::getLock()` / `releaseLock()`** is the application mutex, alongside
  `startTransaction()` / `commit()` / `rollback()`. Use it around writes. Nothing in the cache layer
  touches it.
- **`CachedTable`'s rebuild guard** only stops several processes rebuilding the same cache entry at
  once. It is one `SET NX PX` released by expiry, it never blocks, and losing it just means reading
  the row again. It is on by default (`$rebuildLockTtl = 3000` ms); `0` turns it off.

The rebuild guard is not a consistency mechanism: a writer can always land between another process's
`getUncached()` and its `cacheSet()`, with or without it. Cache coherency comes from invalidating on
write, which `set()`, `update()`, `delete()` and `deleteById()` do.
