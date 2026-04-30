# codeit/base

Reusable CodeIT base utilities for Laminas applications.

## Requirements

- PHP `^8.1`
- Laminas MVC `^3.8`
- Current Laminas component packages for DB, forms, filters, validators, view helpers, and service manager

The package intentionally allows PHP 8.1+ instead of pinning to PHP 8.3/8.4. Applications can run it on PHP 8.3 while libraries and CI can still cover PHP 8.1 and 8.2 where the Laminas dependency set supports them.

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

## Tests

```bash
composer install
composer test
```
