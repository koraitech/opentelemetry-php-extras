<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry\Tests\Support;

use ArrayObject;
use Closure;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase as Base;

/**
 * Runs the instrumentation's pre/post closures around real calls, the way the extension does,
 * so the logic is tested without the extension being loaded.
 */
abstract class TestCase extends Base
{
    protected ArrayObject $exported;
    protected TracerProvider $provider;
    protected CachedInstrumentation $instrumentation;
    /** @var array<string, array{?Closure, ?Closure}> */
    protected array $hooks = [];
    private ?ScopeInterface $rootScope = null;
    private ?ScopeInterface $sdkScope = null;
    private ?SpanInterface $root = null;

    protected function setUp(): void
    {
        $this->exported = new ArrayObject();
        $this->provider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($this->exported)));
        $this->sdkScope = \OpenTelemetry\SDK\Sdk::builder()->setTracerProvider($this->provider)->setAutoShutdown(false)->buildAndRegisterGlobal();
        $this->instrumentation = new CachedInstrumentation('test');
        $this->hooks = [];
    }

    protected function tearDown(): void
    {
        $this->endRoot();
        $this->sdkScope?->detach();
    }

    protected function hook(): Closure
    {
        return function (?string $class, string $function, ?Closure $pre = null, ?Closure $post = null): bool {
            $this->hooks[strtolower(($class ?? '') . '::' . $function)] = [$pre, $post];

            return true;
        };
    }

    /** Calls $call between the pre and post hooks registered for $class::$function. */
    protected function invoke(string $class, string $function, ?object $self, array $params, Closure $call): mixed
    {
        [$pre, $post] = $this->hooks[strtolower($class . '::' . $function)] ?? [null, null];
        $pre?->__invoke($self, $params, $class, $function, __FILE__, __LINE__);
        $return = null;
        $exception = null;
        try {
            $return = $call();
        } catch (\Throwable $e) {
            $exception = $e;
        }
        $post?->__invoke($self, $params, $return, $exception);
        if ($exception) {
            throw $exception;
        }

        return $return;
    }

    protected function startRoot(): void
    {
        $this->root = $this->provider->getTracer('test')->spanBuilder('GET /')->startSpan();
        $this->rootScope = $this->root->activate();
    }

    protected function endRoot(): void
    {
        $this->rootScope?->detach();
        $this->root?->end();
        $this->rootScope = null;
        $this->root = null;
    }

    /** @return list<ImmutableSpan> */
    protected function spans(): array
    {
        return array_values(array_filter($this->exported->getArrayCopy(), static fn ($s): bool => $s->getName() !== 'GET /'));
    }

    /** @return list<string> */
    protected function names(): array
    {
        return array_map(static fn (ImmutableSpan $s): string => $s->getName(), $this->spans());
    }
}
