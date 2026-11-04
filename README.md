# codeit/base

Reusable CodeIT base utilities for Laminas applications.

## Requirements

- PHP `^8.1`
- Laminas MVC `^3.8` only when using the optional MVC controllers and plugins
- Current Laminas component packages for DB, forms, filters, validators, view helpers, and service manager

The package intentionally allows PHP 8.1+ instead of pinning to PHP 8.3/8.4. Applications can run it on PHP 8.3 while libraries and CI can still cover PHP 8.1 and 8.2 where the Laminas dependency set supports them.

## Mezzio and other PSR-15 applications (2.2.0+)

Models, caching, forms, and utilities can be used without installing Laminas MVC.
Mezzio is supplied by the consuming application; this library does not require an HTTP framework.
Wire the existing `dbAdapter` and `cache` services into your PSR container and register it as
`CodeIT\Utils\Registry::set('sm', $container)` for the model infrastructure.
Use `CodeIT\Model\AppTable`, `CachedTable`, and `LocalizableTable` directly as before.
The complete-row cache, cached list invalidation, and multilingual behavior are unchanged.

Applications using `CodeIT\Controller` or `CodeIT\Controller\Plugin` must explicitly install
`laminas/laminas-mvc:^3.8`. MVC remains a development dependency so the existing adapters
continue to be tested. Applications removing a Composer package override can use this
repository's Composer metadata directly.

## What Changed In 2.0

- Zend Framework namespaces were migrated to Laminas namespaces.
- `zendframework/*` dependencies were replaced with `laminas/*`.
- Existing `Application\*` integration points are preserved for backward compatibility.
- The legacy user creation command was ported to `laminas-cli`.
- Controllers accept `Psr\Container\ContainerInterface`.
- PHPUnit configuration and unit tests were added.

## Console Commands

The user creation command is registered for `laminas-cli`:

```bash
vendor/bin/laminas user:create user@example.com secret-password admin
```

The command preserves the legacy create-user contract: `email`, `password`, and optional `level`.
Applications that have a password field or password setter on `Application\Model\User` receive the password through `setData()`.
SSO-only applications can ignore it at the model/schema level.

## ACL

`CodeIT\ACL\Authentication` keeps using `Application\Lib\Acl` and the existing `call(string $method, array $args)` ACL API.

## Documentation

- [CodeIT Base Reference](docs/reference.md) describes the package structure, controllers, ACL integration, cache services, table models, form classes, and view helpers.

## Tests

```bash
composer install
composer cs
composer test
```

`composer test` also runs `tests/Smoke`, which exercises `AppTable`/`CachedTable`
against a real database (admin vs. client `$publicOnly` reads, and writes that
must invalidate the cache) instead of the stubs the rest of the suite uses. It
skips itself when no database is reachable.

```bash
docker compose up -d          # MariaDB on localhost:3306, Redis on localhost:16379
composer test:smoke

SMOKE_DB=sqlite composer test:smoke                                       # no docker: in-memory SQLite instead
SMOKE_CACHE=redis SMOKE_REDIS_PORT=16379 composer test:smoke              # cache reads/invalidation through real Redis (needs ext-redis)
```

## Coding Standard

All hand-maintained PHP source and tests must follow PSR-12. Run `composer cs` before committing and
`composer cs:fix` for safe mechanical formatting.
