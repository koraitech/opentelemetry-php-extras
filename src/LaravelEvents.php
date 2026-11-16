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
 * By default only events with a listener defined in the application's own code get a span. Listeners from
 * packages and the framework (anything under `vendor/`, e.g. Telescope, which listens to every event) and the
 * ones OpenTelemetry registers do not count. KORAI_OTEL_LARAVEL_EVENTS=all traces every event.
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

    /** @var array<string, array{string, bool}> event name => [listeners signature, answer] */
    private static array $ownListenersCache = [];

    /**
     * Whether the event has a listener defined in the application's own code. Listeners that live under a
     * `vendor/` directory (the framework itself, Telescope, Pulse, debug bars, error trackers...) and the ones
     * OpenTelemetry registers do not count: tools like Telescope listen to every event, and counting them
     * would trace every model retrieved and every cache read. Wildcard listeners follow the same rule.
     * Without a dispatcher to look at, the event is traced.
     */
    public static function hasOwnListeners(mixed $dispatcher, string $name): bool
    {
        if (!is_object($dispatcher) || !method_exists($dispatcher, 'getRawListeners')) {
            return true;
        }
        try {
            $raw = $dispatcher->getRawListeners();
            $names = [$name];
            if (class_exists($name, false)) {
                $names = array_merge($names, array_values(class_implements($name) ?: []));
            }
            $listeners = [];
            foreach ($names as $n) {
                foreach ($raw[$n] ?? [] as $listener) {
                    $listeners[] = $listener;
                }
            }
            foreach (self::wildcards($dispatcher) as $pattern => $wildcardListeners) {
                if (self::wildcardMatches((string) $pattern, $name)) {
                    foreach ($wildcardListeners as $listener) {
                        $listeners[] = $listener;
                    }
                }
            }
            // Busy pages dispatch the same events thousands of times: remember the answer until the listeners change.
            $signature = (string) count($listeners) . ':' . spl_object_id($dispatcher);
            $cached = self::$ownListenersCache[$name] ?? null;
            if ($cached !== null && $cached[0] === $signature) {
                return $cached[1];
            }
            $own = false;
            foreach ($listeners as $listener) {
                if (!self::isTelemetryListener($listener) && !self::isVendorListener($listener)) {
                    $own = true;
                    break;
                }
            }
            if (count(self::$ownListenersCache) > 5000) {
                self::$ownListenersCache = [];
            }
            self::$ownListenersCache[$name] = [$signature, $own];

            return $own;
        } catch (Throwable) {
            return true;
        }
    }

    /** @return array<string, list<mixed>> the dispatcher's wildcard listeners (a protected property in Laravel) */
    private static function wildcards(object $dispatcher): array
    {
        if (!method_exists($dispatcher, 'hasWildcardListeners')) {
            return [];
        }
        try {
            $property = new \ReflectionProperty($dispatcher, 'wildcards');
            $value = $property->getValue($dispatcher);

            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** Laravel's Str::is() rules: `*` matches anything. */
    public static function wildcardMatches(string $pattern, string $name): bool
    {
        if ($pattern === $name) {
            return true;
        }

        return preg_match('#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '\z#u', $name) === 1;
    }

    /** A listener whose code lives under a `vendor/` directory: a package or the framework, not the application. */
    public static function isVendorListener(mixed $listener): bool
    {
        $file = self::listenerFile($listener);

        return $file !== null && preg_match('#[/\\\\]vendor[/\\\\]#', $file) === 1;
    }

    private static function listenerFile(mixed $listener): ?string
    {
        try {
            if ($listener instanceof Closure) {
                $file = (new \ReflectionFunction($listener))->getFileName();

                return is_string($file) ? $file : null;
            }
            $class = null;
            if (is_array($listener) && isset($listener[0])) {
                $class = is_object($listener[0]) ? $listener[0]::class : (is_string($listener[0]) ? $listener[0] : null);
            } elseif (is_string($listener)) {
                $class = explode('@', $listener, 2)[0];
            } elseif (is_object($listener)) {
                $class = $listener::class;
            }
            if ($class === null || !class_exists($class)) {
                return null;
            }
            $file = (new \ReflectionClass($class))->getFileName();

            return is_string($file) ? $file : null;
        } catch (Throwable) {
            return null;
        }
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
