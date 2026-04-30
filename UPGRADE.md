# Upgrade Guide

## 1.x to 2.x

1. Replace all `Zend\*` imports in consuming code with `Laminas\*`.
2. Keep existing `Application\*` classes available; `codeit/base` still integrates with them.
3. Replace legacy `php public/index.php user create <email> <password> [<level>]` calls with `vendor/bin/laminas user:create <email> <password> [level]`. The command remains compatible with projects that still store local passwords.
4. Remove `laminas/laminas-mvc-console` and `Laminas\Mvc\Console`; the package command now uses `laminas-cli`.
5. Pass PSR containers to CodeIT controllers instead of relying on concrete `ServiceManager` type hints.
6. Run the package tests and the consuming application smoke tests under PHP 8.3.

## PHP Version Policy

The package supports PHP `^8.1`. Production applications may standardize on PHP 8.3 or 8.4 without forcing the reusable library to reject PHP 8.1/8.2 installs.
