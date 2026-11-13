<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry;

use Closure;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * One span per Laravel event: every call to Illuminate\Events\Dispatcher::dispatch(), named after
 * the event (its class, or the string name such as `eloquent.booting: App\Models\Page`).
 *
 * The span lasts as long as the listeners run, so slow listeners show up in the trace, and spans
 * created by listeners (queries, HTTP calls...) are nested under the event that triggered them.
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

        $pre = static function ($dispatcher, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($stack, $instrumentation, $exclude, $requireParent): void {
            $name = self::eventName($params[0] ?? null);
            if ($name === null || ($requireParent && !SpanStack::hasActiveParent()) || self::excluded($name, $exclude)) {
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
