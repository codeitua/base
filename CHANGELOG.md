# Changelog

## 2.0.0

- Migrated package source from Zend Framework namespaces to Laminas namespaces.
- Replaced Zend Framework Composer dependencies with Laminas packages.
- Preserved existing `Application\*` integration points.
- Ported the legacy MVC console user creation command to `laminas-cli` as `user:create`.
- Switched controller constructors to `Psr\Container\ContainerInterface`.
- Added PHPUnit configuration and unit tests.
