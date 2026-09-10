# Changelog

## Unreleased

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
- Added `AGENTS.md` and `docs/reference.md`.

## 2.0.0

- Migrated package source from Zend Framework namespaces to Laminas namespaces.
- Replaced Zend Framework Composer dependencies with Laminas packages.
- Preserved existing `Application\*` integration points.
- Ported the legacy MVC console user creation command to `laminas-cli` as `user:create`.
- Switched controller constructors to `Psr\Container\ContainerInterface`.
- Added PHPUnit configuration and unit tests.
