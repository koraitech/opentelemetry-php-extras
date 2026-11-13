<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry;

use Closure;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * One span per Laravel event dispatched through Illuminate\Events\Dispatcher::dispatch(), named after
 * the event (its class, or the string name such as `eloquent.booting: App\Models\Page`).
 *
 * The span lasts as long as the listeners run, so slow listeners show up in the trace, and spans
 * created by listeners (queries, HTTP calls...) are nested under the event that triggered them.
 *
 * By default only events with a listener of the application's own get a span: listeners that OpenTelemetry
 * itself registers (the Laravel instrumentation's cache and query watchers) do not count, since those events
 * already appear as span events or query spans. KORAI_OTEL_LARAVEL_EVENTS=all traces every event.
 */
final class LaravelEvents
{
    public const DISPATCHER = 'Illuminate\\Events\\Dispatcher';

    /** @param Closure(?string, string, ?Closure, ?Closure): bool $hook */
    public static function register(Closure $hook, CachedInstrumentation $instrumentation, Config $config): void
    {
        $stack = new SpanStack();
        $exclude = self::patterns($config->excludedEvents());
        $requireParent = $config->eventsRequireParent();
        $all = $config->eventsMode() === 'all';

        $pre = static function ($dispatcher, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($stack, $instrumentation, $exclude, $requireParent, $all): void {
            $name = self::eventName($params[0] ?? null);
            if ($name === null || ($requireParent && !SpanStack::hasActiveParent()) || self::excluded($name, $exclude)
                || (!$all && !self::hasOwnListeners($dispatcher, $name))) {
                $stack->skip();

                return;
            }
            $span = $instrumentation->tracer()->spanBuilder($name)
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setAttribute('laravel.event.name', $name)
                ->setAttribute('code.function.name', self::DISPATCHER . '::' . ($function ?? 'dispatch'))
                ->startSpan();
            if (($params[2] ?? false) === true) {
                $span->setAttribute('laravel.event.halt', true);
            }
            $stack->push($span);
        };
        $post = static function ($dispatcher, array $params, $return, ?Throwable $exception) use ($stack): void {
            $stack->pop($exception);
        };

        // dispatch() is the Laravel >= 5.8 entry point; until() calls it with $halt = true.
        $hook(self::DISPATCHER, 'dispatch', $pre, $post);
    }

    /**
     * Whether the event has a listener that is not OpenTelemetry's own. Wildcard listeners count as the
     * application's. Without a dispatcher to look at, the event is traced.
     */
    public static function hasOwnListeners(mixed $dispatcher, string $name): bool
    {
        if (!is_object($dispatcher) || !method_exists($dispatcher, 'getRawListeners')) {
            return true;
        }
        try {
            if (method_exists($dispatcher, 'hasWildcardListeners') && $dispatcher->hasWildcardListeners($name)) {
                return true;
            }
            $raw = $dispatcher->getRawListeners();
            $names = [$name];
            if (class_exists($name, false)) {
                $names = array_merge($names, array_values(class_implements($name) ?: []));
            }
            foreach ($names as $n) {
                foreach ($raw[$n] ?? [] as $listener) {
                    if (!self::isTelemetryListener($listener)) {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    /** A listener registered by OpenTelemetry itself (the unscoped packages, or the distro's scoped copy). */
    public static function isTelemetryListener(mixed $listener): bool
    {
        $class = null;
        if (is_array($listener) && isset($listener[0])) {
            $class = is_object($listener[0]) ? $listener[0]::class : (is_string($listener[0]) ? $listener[0] : null);
        } elseif (is_string($listener)) {
            $class = explode('@', $listener, 2)[0];
        } elseif ($listener instanceof Closure) {
            $reflection = new \ReflectionFunction($listener);
            $class = $reflection->getClosureScopeClass()?->getName();
        } elseif (is_object($listener)) {
            $class = $listener::class;
        }
        if ($class === null) {
            return false;
        }

        return str_starts_with($class, 'OpenTelemetry\\')
            || preg_match('/^[A-Za-z0-9_]*Scoped[A-Za-z0-9_]*\\\\OpenTelemetry\\\\/', $class) === 1;
    }

    public static function eventName(mixed $event): ?string
    {
        if (is_object($event)) {
            return $event::class;
        }
        if (is_string($event) && $event !== '') {
            return $event;
        }

        return null;
    }

    /**
     * @param list<string> $patterns
     * @return list<string> regular expressions
     */
    public static function patterns(array $patterns): array
    {
        return array_map(
            static fn (string $p): string => '/^' . str_replace('\\*', '.*', preg_quote($p, '/')) . '$/',
            $patterns,
        );
    }

    /** @param list<string> $regexes */
    public static function excluded(string $name, array $regexes): bool
    {
        foreach ($regexes as $regex) {
            if (preg_match($regex, $name) === 1) {
                return true;
            }
        }

        return false;
    }
}
