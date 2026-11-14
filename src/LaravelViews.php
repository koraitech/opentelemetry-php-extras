<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry;

use Closure;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * A span per Laravel view rendered (Blade or plain PHP), named after the view: `cms.blocks.hero`,
 * `layouts.app`, `components.card`... Views rendered inside another view (`@include`, components,
 * layouts) nest under it, so a page shows which partial its time went into, as Datadog's
 * `laravel.view` spans do.
 *
 * Hooks Illuminate\View\View::render(), which every way of rendering a view goes through.
 */
final class LaravelViews
{
    public const CLASS_NAME = 'Illuminate\\View\\View';

    /** @param Closure(?string, string, ?Closure, ?Closure): bool $hook */
    public static function register(Closure $hook, CachedInstrumentation $instrumentation, Config $config): void
    {
        $stack = new SpanStack();
        $excluded = LaravelEvents::patterns($config->excludedViews());

        $pre = static function ($view, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($stack, $instrumentation, $excluded): void {
            $name = self::viewName($view);
            if ($name === null || !SpanStack::hasActiveParent() || LaravelEvents::excluded($name, $excluded)) {
                $stack->skip();

                return;
            }
            $builder = $instrumentation->tracer()->spanBuilder($name)
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->setAttribute('laravel.view.name', $name)
                ->setAttribute('code.function.name', self::CLASS_NAME . '::render');
            $path = self::viewPath($view);
            if ($path !== null) {
                $builder->setAttribute('code.file.path', $path);
            }
            $stack->push($builder->startSpan());
        };
        $post = static function ($view, array $params, $return, ?Throwable $exception) use ($stack): void {
            $stack->pop($exception);
        };
        $hook(self::CLASS_NAME, 'render', $pre, $post);
    }

    public static function viewName(mixed $view): ?string
    {
        try {
            if (is_object($view) && method_exists($view, 'name')) {
                $name = $view->name();
                if (is_string($name) && $name !== '') {
                    return $name;
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    public static function viewPath(mixed $view): ?string
    {
        try {
            if (is_object($view) && method_exists($view, 'getPath')) {
                $path = $view->getPath();
                if (is_string($path) && $path !== '') {
                    return $path;
                }
            }
        } catch (Throwable) {
        }

        return null;
    }
}
