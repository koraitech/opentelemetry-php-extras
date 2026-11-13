# OpenTelemetry PHP extras

Extra auto-instrumentation for PHP applications traced with OpenTelemetry. It fills in the parts of a request that
the official PHP instrumentation leaves blank:

| What | Spans | Works with |
|---|---|---|
| **Laravel events** | One span per event dispatched through `Illuminate\Events\Dispatcher`, named after the event: `Illuminate\Cache\Events\CacheHit`, `eloquent.booting: App\Models\Page`, `Illuminate\Routing\Events\RouteMatched`, your own events… Listeners run inside the span, so slow listeners and the queries they run are visible. | Laravel 9 to 13 |
| **Redis commands** | One client span per command (`REDIS GET`, `REDIS HGETALL`, `REDIS PCONNECT`…), with server address, port and database. | phpredis (`ext-redis`, `Redis` and `RedisCluster`, PHP 8.2 or later) and Predis, in any PHP application |

Nothing changes in your code: the package registers itself through Composer's autoloader.

## Requirements

- PHP 8.1 or later. Tracing the phpredis extension needs PHP 8.2 or later: older versions cannot hook methods of PHP extensions. Predis and Laravel events work on 8.1.
  <!-- tested -->Tested on PHP 8.1, 8.2, 8.3, 8.4 and 8.5, and Laravel 9 to 13.<!-- /tested -->
- One of:
  - the [`opentelemetry` PECL extension](https://github.com/open-telemetry/opentelemetry-php-instrumentation) with the OpenTelemetry SDK, or
  - the [OpenTelemetry PHP distro](https://github.com/open-telemetry/opentelemetry-php-distro) (`opentelemetry-php-distro`).

Without either, the package does nothing.

## Install

```bash
composer require koraitech/opentelemetry-php-extras
```

### With the OpenTelemetry PHP distro

The distro keeps its own copy of the OpenTelemetry libraries apart from your application's. Enable its bridge so the
spans from this package join the distro's traces:

```bash
OTEL_PHP_SCOPED_DEPS_BRIDGE_ENABLED=true
```

Under PHP-FPM, pass environment variables to PHP through the pool (`env[NAME] = value`, or `clear_env = no`).

### With the PECL extension

Install and configure the OpenTelemetry SDK as usual (`OTEL_PHP_AUTOLOAD_ENABLED=true`, exporter, service name). No
extra setting is needed.

## Configuration

All settings are environment variables and all are optional.

| Variable | Default | What it does |
|---|---|---|
| `OTEL_PHP_DISABLED_INSTRUMENTATIONS` | | Standard OpenTelemetry list. Add `korai-laravel-events` or `korai-redis` to turn a part off. |
| `KORAI_OTEL_LARAVEL_EVENTS_EXCLUDE` | | Comma-separated event names to skip. `*` matches anything: `Illuminate\Log\Events\MessageLogged,eloquent.*` |
| `KORAI_OTEL_LARAVEL_EVENTS_REQUIRE_PARENT` | `true` | Only create event spans inside an existing trace. Events fired while the framework boots would otherwise each start a trace of their own. |
| `KORAI_OTEL_REDIS_KEYS` | `false` | Record the command and key in `db.query.text` (`GET session:abc`). Values are never recorded. |

## Recommended settings for Laravel

These are settings of the OpenTelemetry PHP distro and SDK, not of this package, but they make the biggest
difference together with it:

```bash
# Fill time not covered by any span with spans sampled from the PHP stack (distro only)
OTEL_PHP_INFERRED_SPANS_ENABLED=true
OTEL_PHP_INFERRED_SPANS_SAMPLING_INTERVAL=20ms
OTEL_PHP_INFERRED_SPANS_MIN_DURATION=10ms

# Laravel records cache hits as span events; the default limit of 128 per span drops the rest
OTEL_SPAN_EVENT_COUNT_LIMIT=1024

# Each SQL query is traced by both the PDO and the Laravel instrumentation; keep one
OTEL_PHP_DISABLED_INSTRUMENTATIONS=pdo
```

If you use this package, do not call `Redis::enableEvents()` only to get Redis spans: with Laravel's own Redis
instrumentation also active, each command would be traced twice.

### Span volume

One span per Laravel event is detailed: a typical page can dispatch hundreds of events (every cache hit, every model
booting and booted). Use `KORAI_OTEL_LARAVEL_EVENTS_EXCLUDE` for events you don't need, and keep your sampler in mind.

## Span reference

**Laravel events**: kind `INTERNAL`, name = event name. Attributes: `laravel.event.name`, `code.function.name`,
`laravel.event.halt` (for `until()`).

**Redis**: kind `CLIENT`, name `REDIS <COMMAND>`. Attributes: `db.system.name` (and the older `db.system`) = `redis`,
`db.operation.name`, `server.address`, `server.port`, `db.namespace` (database number), `db.query.text` (only with
`KORAI_OTEL_REDIS_KEYS=true`), `code.function.name`.

Spans are created only inside an active trace, and failures record the exception and set the span status to error.

## Development

```bash
composer install
composer test:unit
# with the opentelemetry and redis extensions loaded and a Redis server on REDIS_HOST:
composer test:integration
```

## Releases

Releases are automatic. When CI passes on `main`, the next version is worked out from the commit messages since the
last release ([Conventional Commits](https://www.conventionalcommits.org/): `feat:` adds a minor version, `fix:` and
others a patch, `!` or `BREAKING CHANGE` a major), tagged, published as a GitHub release and sent to Packagist.
Changes that only touch tests, CI or docs don't produce a release.

A daily job also checks php.net and Packagist for new PHP and Laravel versions. When one appears, it runs the full
test suite on it and, if it passes, adds it to the tested versions and publishes a new minor release. If the tests
fail, it opens an issue instead.

## License

Apache-2.0. See [LICENSE](LICENSE).
