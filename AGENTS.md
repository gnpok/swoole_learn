# AGENTS.md

## Cursor Cloud specific instructions

### Overview

This is a PHP 8.3+ Swoole learning project (`swoole/learn`). It contains interface-based abstractions for Swoole HTTP server, coroutine, and timer patterns, with PHPUnit tests that use fakes/stubs (no Swoole extension required for tests).

### System dependencies

- **PHP 8.3+** (via `ppa:ondrej/php`): `php8.3-cli`, `php8.3-mbstring`, `php8.3-xml`, `php8.3-curl`, `php8.3-zip`
- **Composer** (installed globally at `/usr/local/bin/composer`)
- **ext-swoole** (via PECL): only needed to run `examples/` scripts, not required for tests

### Common commands

| Task | Command |
|------|---------|
| Install deps | `composer install` |
| Run tests | `composer test` |
| Lint (syntax) | `find src tests examples -name '*.php' -exec php -l {} \;` |

### Running example scripts

The three example scripts under `examples/` require the Swoole extension (`ext-swoole`):

- `php examples/http_server.php` — starts HTTP server on `127.0.0.1:9501` with `/health` and `/time` routes
- `php examples/coroutine_batch.php` — runs concurrent coroutine tasks
- `php examples/timer_tick.php` — schedules 5 ticks at 500ms intervals

### Gotchas

- The project has no dedicated lint tool (e.g. PHP-CS-Fixer or PHPStan). Syntax checking via `php -l` is the available lint mechanism.
- PHPUnit tests do **not** require `ext-swoole`; they use fake implementations of the runtime interfaces.
- The HTTP server example binds to port `9501` by default.
