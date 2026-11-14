<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry;

use Closure;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * A span per call of the methods listed in KORAI_OTEL_TRACE_METHODS, without changing their code:
 * `App\Services\OddsFormatter::format,Vendor\Cms\Renderer::render`. Useful for your own libraries,
 * where time goes into PHP code that no instrumentation knows about.
 */
final class Methods
{
    /** @param Closure(?string, string, ?Closure, ?Closure): bool $hook */
    public static function register(Closure $hook, CachedInstrumentation $instrumentation, Config $config): void
    {
        foreach ($config->tracedMethods() as [$class, $method]) {
            $stack = new SpanStack();
            $name = $class . '::' . $method;
            $pre = static function ($object, array $params, ?string $c, ?string $f, ?string $filename, ?int $lineno) use ($stack, $instrumentation, $name, $class): void {
                if (!SpanStack::hasActiveParent()) {
                    $stack->skip();

                    return;
                }
                $builder = $instrumentation->tracer()->spanBuilder($name)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->setAttribute('code.function.name', $name);
                if ($object !== null && $object::class !== $class) {
                    $builder->setAttribute('code.class.runtime', $object::class);
                }
                if ($filename !== null) {
                    $builder->setAttribute('code.file.path', $filename);
                }
                if ($lineno !== null) {
                    $builder->setAttribute('code.line.number', $lineno);
                }
                $stack->push($builder->startSpan());
            };
            $post = static function ($object, array $params, $return, ?Throwable $exception) use ($stack): void {
                $stack->pop($exception);
            };
            $hook($class, $method, $pre, $post);
        }
    }
}
