<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry;

/**
 * Settings, read from environment variables.
 *
 * Under PHP-FPM, variables reach PHP through the pool (`env[NAME] = ...` or `clear_env = no`),
 * so both getenv() and $_SERVER are checked.
 */
final class Config
{
    public const LARAVEL_EVENTS = 'korai-laravel-events';
    public const REDIS = 'korai-redis';
    public const METHODS = 'korai-methods';
    public const LARAVEL_VIEWS = 'korai-laravel-views';

    /** @param array<string, string> $env */
    public function __construct(private readonly array $env)
    {
    }

    public static function fromEnvironment(): self
    {
        $env = [];
        foreach ([
            'OTEL_SDK_DISABLED',
            'OTEL_PHP_DISABLED_INSTRUMENTATIONS',
            'KORAI_OTEL_LARAVEL_EVENTS',
            'KORAI_OTEL_LARAVEL_EVENTS_EXCLUDE',
            'KORAI_OTEL_LARAVEL_EVENTS_REQUIRE_PARENT',
            'KORAI_OTEL_LARAVEL_VIEWS_EXCLUDE',
            'KORAI_OTEL_REDIS_KEYS',
            'KORAI_OTEL_TRACE_METHODS',
            'KORAI_OTEL_REDIS_MIN_DURATION',
        ] as $name) {
            $value = getenv($name);
            if ($value === false && isset($_SERVER[$name]) && is_string($_SERVER[$name])) {
                $value = $_SERVER[$name];
            }
            if ($value !== false) {
                $env[$name] = $value;
            }
        }

        return new self($env);
    }

    public function sdkDisabled(): bool
    {
        return self::bool($this->env['OTEL_SDK_DISABLED'] ?? null, false);
    }

    /**
     * Uses the standard OTEL_PHP_DISABLED_INSTRUMENTATIONS list, so one variable controls
     * every instrumentation: `korai-laravel-events`, `korai-laravel-views`, `korai-redis`, `korai-methods`, or `all`.
     */
    public function isDisabled(string $instrumentation): bool
    {
        if ($this->sdkDisabled()) {
            return true;
        }
        $list = self::list($this->env['OTEL_PHP_DISABLED_INSTRUMENTATIONS'] ?? '');

        return in_array('all', $list, true) || in_array($instrumentation, $list, true);
    }

    /**
     * Event names to skip. Comma-separated, `*` matches anything,
     * e.g. `Illuminate\Log\Events\MessageLogged,eloquent.*`.
     *
     * @return list<string>
     */
    public function excludedEvents(): array
    {
        return self::list($this->env['KORAI_OTEL_LARAVEL_EVENTS_EXCLUDE'] ?? '');
    }

    /**
     * View names to skip. Comma-separated, `*` matches anything, e.g. `components.icon,mail.*`.
     *
     * @return list<string>
     */
    public function excludedViews(): array
    {
        return self::list($this->env['KORAI_OTEL_LARAVEL_VIEWS_EXCLUDE'] ?? '');
    }

    /**
     * Which events get a span: `listeners` (default) only events that have a listener of the application's own,
     * so the span shows where listener time goes; `all` every dispatched event, as Datadog does (many more spans:
     * every cache read, every model retrieved...).
     */
    public function eventsMode(): string
    {
        $mode = strtolower(trim((string) ($this->env['KORAI_OTEL_LARAVEL_EVENTS'] ?? '')));

        return $mode === 'all' ? 'all' : 'listeners';
    }

    /**
     * Only create event spans inside an existing trace (default). Events fired while the
     * framework boots, before any request span exists, would otherwise each start a new trace.
     */
    public function eventsRequireParent(): bool
    {
        return self::bool($this->env['KORAI_OTEL_LARAVEL_EVENTS_REQUIRE_PARENT'] ?? null, true);
    }

    /**
     * Redis commands faster than this get no span (failed commands always do), in nanoseconds.
     * Set as milliseconds: `2`, `2ms`, `0.5ms`. Default 0: every command.
     */
    public function redisMinDurationNs(): int
    {
        $value = strtolower(trim((string) ($this->env['KORAI_OTEL_REDIS_MIN_DURATION'] ?? '')));
        if (str_ends_with($value, 'ms')) {
            $value = substr($value, 0, -2);
        }

        return is_numeric($value) && (float) $value > 0 ? (int) round((float) $value * 1_000_000) : 0;
    }

    /**
     * Methods to trace, `Class::method` separated by commas (a leading backslash is ignored).
     *
     * @return list<array{string, string}>
     */
    public function tracedMethods(): array
    {
        $out = [];
        foreach (self::list($this->env['KORAI_OTEL_TRACE_METHODS'] ?? '') as $item) {
            $parts = explode('::', ltrim($item, '\\'), 2);
            if (count($parts) === 2 && preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $parts[0]) === 1
                && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $parts[1]) === 1) {
                $out[] = [$parts[0], $parts[1]];
            }
        }

        return $out;
    }

    /** Record the Redis key (first argument) in `db.query.text`. Values are never recorded. */
    public function redisKeys(): bool
    {
        return self::bool($this->env['KORAI_OTEL_REDIS_KEYS'] ?? null, false);
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    private static function bool(?string $value, bool $default): bool
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
