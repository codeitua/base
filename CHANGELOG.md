# Changelog

## 2.1.3

- `LocalizableTable::getLocalData()` honours an explicitly requested row id instead of combining
  it with the model's bound id. Reading another row through `get()` or `find()` now loads its
  translations correctly, preventing untranslated fallback values from entering the language cache.
  Calls without an explicit id still use the model's bound row, including additional filters.
- Added SQL regression coverage for explicit and implicit row ids, cold and warm single and batch
  reads, and isolation between language caches.

Invalidate affected raw and per-language row cache entries once when upgrading: entries written
before this fix can still contain untranslated fallback values.

## 2.1.2

- `CachedTable::getList()` no longer runs a `count()` query when it already served the whole,
  unpaginated list from cache: `$ids` in that case is the full table, so `sizeof($res)` was already
  the exact total, and the `count()` call happening anyway meant a caller passing `$total` never
  actually got a zero-query warm read. `count()` still runs for a paginated call (`$limit`/`$offset`
  given), which is the only case that can hide rows past its window.

## 2.1.1

- `CodeIT\Cache\Redis::mget()` restores the previous error handler unconditionally instead of only when
  `set_error_handler()` returned one. With no handler already installed (the common case) it returned
  `null`, so the guard around unserializing missing keys was left installed for the rest of the process,
  silencing every warning the application raised afterwards. Found by the new database/cache smoke tests
  (`tests/Smoke`, `SMOKE_CACHE=redis`), the first thing in this repo to call `CodeIT\Cache\Redis` against
  a real Redis server instead of the `FakeCache` test double.
- `find()` reads the generation token and the cached ID list in one command instead of two, because
  the token moved out of the key and into the entry. The first `find()` of a request costs 2 cache
  commands rather than 3, entries superseded by a write are overwritten in place instead of orphaned
  until their TTL, and the key is shorter.
- `get()` and `mget()` serve from a per-request identity map, so a row read twice in one request costs
  one command, and a batched read only asks for the ids it does not already hold. `$localRowCache`
  opts a model out; a worker that keeps the process alive calls `CachedTable::forgetLocalRows()`.
- `getList()` caches only the ids and hydrates through `mget()`. It used to hydrate with one `get()`
  per row and cache the whole hydrated list, which went stale as soon as a row was edited.
- `LocalizableTable::cacheDelete()` no longer scans the keyspace on every row write, and no longer
  deletes its numeric neighbours: the mask `table.news.1` also matched rows 10-19 and 100-199. A model
  that declares `$languages` gets one exact delete instead.
- `CodeIT\Cache\Redis` picks a serializer when the connection has none, instead of storing rows as
  the string `Object`, and `Redis::bestSerializer()` avoids the fatal error that naming
  `Redis::SERIALIZER_IGBINARY` causes on a build without igbinary.
- `AppTable::set()` returns the id it wrote; `delete()` and `deleteById()` share one path, so deleting
  by a where array now drops the cached list as deleting by id always did; `CachedTable::update()`
  honours a model's `ID_COLUMN` instead of looking for a literal `id` key.
- `getId()`/`setId()` no longer create a dynamic property for a model whose id column is not `$id`,
  deprecated since PHP 8.2.
- SQL assembly moved to `CodeIT\Model\SqlBuilder`, and `execute()`/`query()` report errors from one
  place. `$cachePrefix` makes the per-key prefix configurable.
- The benchmark covers deferred reads, the first `find()` of a request, serializer choice and payload
  shape.

## 2.1.0

Requires one change in applications that supply their own cache class: it must implement
`CodeIT\Cache\CacheInterface`. See UPGRADE.md. Everything else is additive.

- `CachedTable::find()` can cache the ids and total of a query instead of running it every time.
  Opt in per model with `protected $findCacheTtl = <seconds>`; the default of `0` keeps the previous
  behaviour. Rows are never stored in a list, so an edited row is current in every cached list.
- Cached lists are invalidated through a generation token (`table.<name>.findgen`) that writes
  replace, so one cache write drops every list of the table without scanning the keyspace.
- `CachedTable::mget()` no longer deletes a row from the database when it cannot be loaded, applies
  `$publicOnly` to cache hits, and handles id arrays that are not zero-indexed.
- `LocalizableTable::mget()` was removed in favour of the `itemCacheKey()`/`findCacheScope()` hooks
  it now overrides, so batched reads, `$publicOnly`, and per-language keys behave the same
  everywhere.
- `LocalizableTable::get()` caches the complete row: a `$publicOnly` read no longer stores a row
  with its private fields stripped.
- `CodeIT\Cache\Redis::deleteByMask()` uses `SCAN` instead of `KEYS` and returns the number of
  deleted keys.
- `CodeIT\Cache\Memcache` gained a batched `mget()`, and its `set()` now takes `($key, $value,
  $ttl, $try)` like the Redis adapter instead of silently using the TTL as the compression flag.
- Fixed PHP 8.4 implicit-nullable deprecations in `AppTable` and the `Registry::offsetExists()`
  return type.
- `LocalizableTable` takes the active language from `Registry::get('lang')` when the application
  registers one, instead of always using `1`, and sets `$locTable` before the constructor can load
  a row through it.
- `AppTable::quoteValue()` casts its argument, so an int, float, or null in a `find()` condition no
  longer raises a `TypeError` under this package's `strict_types`.
- `mget()` loads everything the cache could not serve through one `getUncachedMany()` call and writes
  it back in one batch, instead of falling back to `get()` per row. Measured over 3000 cycles, a cold
  list of 50 rows went from 151 cache commands and 88.9 ms to 2 commands and 1.6 ms; with a real query
  per row behind it, implementing `getUncachedMany()` in the model takes 31.5 ms down to 2.4 ms.
- The rebuild lock is taken with one cache command (`SET NX PX`) and released by expiry, instead of two
  MySQL round trips through `GET_LOCK`/`RELEASE_LOCK`. Losing the race no longer blocks for ten seconds
  holding a database connection: the row is read once more and then rebuilt anyway. `$rebuildLockTtl`
  sets the hold time, `0` turns the guard off, and a cache backend without `acquireLock()` keeps
  working with no lock at all.
- `CodeIT\Cache\Redis::setMany()` pipelines a batch of writes into one round trip, and `acquireLock()`
  implements the single command lock; `Memcache` provides both through `add()`.
- `itemCacheKeyPart()` and `prepareCachedRow()` replace the `itemCacheKey()` override, so the batched
  path caches rows in the same shape and under the same keys as `get()`.
- Added `CodeIT\Cache\CacheInterface`, implemented by `Redis` and `Memcache`, and typed
  `CachedTable::$cache` against it, which removed the capability checks around the batched and
  locking calls. A custom cache class now has to implement it; see UPGRADE.md.
- Added `CodeIT\Cache\BatchLoader` and `Deferred` with `CachedTable::deferMget()` and `deferFind()`,
  so reads registered together are served together. Five warm lists cost 10 cache commands and
  6.04 ms one at a time, and 2 commands and 1.66 ms deferred.
- `Memcache::deleteByMask()` throws instead of silently doing nothing, because memcached cannot
  enumerate keys and `LocalizableTable` needs a Redis cache.
- Added `bin/benchmark.php` (`composer bench`), which measures the model layer over thousands of
  cycles and reports mean/p50/p95/p99 next to the number of cache commands each operation costs.
- Added `AGENTS.md` and `docs/reference.md`.

## 2.0.0

- Migrated package source from Zend Framework namespaces to Laminas namespaces.
- Replaced Zend Framework Composer dependencies with Laminas packages.
- Preserved existing `Application\*` integration points.
- Ported the legacy MVC console user creation command to `laminas-cli` as `user:create`.
- Switched controller constructors to `Psr\Container\ContainerInterface`.
- Added PHPUnit configuration and unit tests.
