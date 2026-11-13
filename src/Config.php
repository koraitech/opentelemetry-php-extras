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
            'KORAI_OTEL_LARAVEL_EVENTS_EXCLUDE',
            'KORAI_OTEL_LARAVEL_EVENTS_REQUIRE_PARENT',
            'KORAI_OTEL_REDIS_KEYS',
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
     * every instrumentation: `korai-laravel-events`, `korai-redis`, or `all`.
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
     * Only create event spans inside an existing trace (default). Events fired while the
     * framework boots, before any request span exists, would otherwise each start a new trace.
     */
    public function eventsRequireParent(): bool
    {
        return self::bool($this->env['KORAI_OTEL_LARAVEL_EVENTS_REQUIRE_PARENT'] ?? null, true);
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
