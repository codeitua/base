# Upgrade Guide

## 2.1.x to 2.2.0

Version 2.2.0 makes Laminas MVC optional so Mezzio and other PSR-15 applications can
install the model/cache library without the MVC runtime. No model API or cache format changes
are introduced by this release; an existing 2.1.3 cache does not need flushing for this update.

For MVC applications, declare the previously transitive dependency explicitly before updating:

```bash
composer require laminas/laminas-mvc:^3.8 codeit/base:^2.2
```

Mezzio applications keep their own HTTP stack and PSR container. Register the container with
`CodeIT\Utils\Registry::set('sm', $container)` and provide `dbAdapter` and `cache` services.
Do not use the optional CodeIT MVC controllers/plugins without installing Laminas MVC.
See [the Mezzio integration notice](README.md#mezzio-and-other-psr-15-applications-220).

The release version comes from the `2.2.0` Git tag, following the existing release convention;
Composer's package metadata does not hard-code a version.

## 1.x to 2.x

1. Replace all `Zend\*` imports in consuming code with `Laminas\*`.
2. Keep existing `Application\*` classes available; `codeit/base` still integrates with them.
3. Replace legacy `php public/index.php user create <email> <password> [<level>]` calls with `vendor/bin/laminas user:create <email> <password> [level]`. The command remains compatible with projects that still store local passwords.
4. Remove `laminas/laminas-mvc-console` and `Laminas\Mvc\Console`; the package command now uses `laminas-cli`.
5. Pass PSR containers to CodeIT controllers instead of relying on concrete `ServiceManager` type hints.
6. Run the package tests and the consuming application smoke tests under PHP 8.3.

## PHP Version Policy

The package supports PHP `^8.1`. Production applications may standardize on PHP 8.3 or 8.4 without forcing the reusable library to reject PHP 8.1/8.2 installs.

## Upgrading the cache layer

`CodeIT\Model\CachedTable` now types its cache as `CodeIT\Cache\CacheInterface`. A custom cache class
must implement it; `CodeIT\Cache\Redis` and `CodeIT\Cache\Memcache` already do. Beyond the methods
that already existed it adds:

- `setMany(array $pairs, int $ttl = 0)` — write a batch, ideally in one round trip.
- `acquireLock(string $key, int $ttlMilliseconds)` — take a lock in a single command, released by
  expiry. Return `true` when the caller took it.

The row rebuild guard no longer uses the database. It used to call `GET_LOCK`/`RELEASE_LOCK`, which
cost two database round trips per cache miss and could block a worker for ten seconds holding a
connection; a timeout then surfaced as `CannotGetLockException` from a plain read. It now takes the
single-command cache lock, and a caller that loses the race re-reads once and rebuilds anyway.

This guard only de-duplicates cache rebuilds. It never protected write correctness, and nothing about
transactions changed: `startTransaction()`, `commit()`, `rollback()` and the `getLock()`/
`releaseLock()` application mutex on `AppTable` are untouched, and remain what you use around writes.

Set `protected $rebuildLockTtl = 0;` on a model to switch the guard off entirely.
