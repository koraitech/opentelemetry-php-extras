<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry\Tests\Unit;

use Illuminate\Events\Dispatcher;
use Korai\OpenTelemetry\Config;
use Korai\OpenTelemetry\LaravelEvents;
use Korai\OpenTelemetry\Tests\Support\TestCase;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

final class CacheHitLike
{
    public function __construct(public string $key)
    {
    }
}

final class LaravelEventsTest extends TestCase
{
    private Dispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new Dispatcher();
    }

    private function register(array $env = []): void
    {
        LaravelEvents::register($this->hook(), $this->instrumentation, new Config($env));
    }

    private function dispatch(object|string $event, array $payload = []): void
    {
        $this->invoke(LaravelEvents::DISPATCHER, 'dispatch', $this->events, [$event, $payload, false],
            fn () => $this->events->dispatch($event, $payload));
    }

    public function test_one_span_per_event_named_after_it(): void
    {
        $this->register();
        $this->startRoot();
        $this->dispatch(new CacheHitLike('k'));
        $this->dispatch('eloquent.booting: App\\Models\\Page');
        $this->endRoot();

        $this->assertSame([CacheHitLike::class, 'eloquent.booting: App\\Models\\Page'], $this->names());
        $span = $this->spans()[1];
        $this->assertSame(SpanKind::KIND_INTERNAL, $span->getKind());
        $this->assertSame('eloquent.booting: App\\Models\\Page', $span->getAttributes()->get('laravel.event.name'));
    }

    public function test_listener_spans_and_nested_events_are_children(): void
    {
        $this->register();
        $this->events->listen('outer', function () {
            $this->dispatch('inner');
        });
        $this->startRoot();
        $this->dispatch('outer');
        $this->endRoot();

        [$inner, $outer] = $this->spans();
        $this->assertSame('inner', $inner->getName());
        $this->assertSame($outer->getSpanId(), $inner->getParentSpanId());
    }

    public function test_no_spans_outside_a_trace_by_default(): void
    {
        $this->register();
        $this->dispatch('booting');
        $this->assertSame([], $this->names());
    }

    public function test_spans_outside_a_trace_when_parent_not_required(): void
    {
        $this->register(['KORAI_OTEL_LARAVEL_EVENTS_REQUIRE_PARENT' => 'false']);
        $this->dispatch('booting');
        $this->assertSame(['booting'], $this->names());
    }

    public function test_excluded_events_create_no_span_and_keep_nesting_right(): void
    {
        $this->register(['KORAI_OTEL_LARAVEL_EVENTS_EXCLUDE' => 'eloquent.*, Illuminate\\Log\\Events\\MessageLogged']);
        $this->events->listen('eloquent.booted: X', function () {
            $this->dispatch('kept.inside.excluded');
        });
        $this->startRoot();
        $this->dispatch('eloquent.booted: X');
        $this->dispatch('Illuminate\\Log\\Events\\MessageLogged');
        $this->dispatch('kept');
        $this->endRoot();

        $this->assertSame(['kept.inside.excluded', 'kept'], $this->names());
        $root = array_values(array_filter($this->exported->getArrayCopy(), fn ($s) => $s->getName() === 'GET /'))[0];
        foreach ($this->spans() as $s) {
            $this->assertSame($root->getSpanId(), $s->getParentSpanId());
        }
    }

    public function test_listener_exception_marks_the_span(): void
    {
        $this->register();
        $this->events->listen('boom', function () {
            throw new \RuntimeException('listener failed');
        });
        $this->startRoot();
        try {
            $this->dispatch('boom');
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }
        $this->endRoot();

        $span = $this->spans()[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('exception', $span->getEvents()[0]->getName());
    }

    public function test_event_name_and_patterns(): void
    {
        $this->assertNull(LaravelEvents::eventName(null));
        $this->assertNull(LaravelEvents::eventName(''));
        $this->assertSame('x', LaravelEvents::eventName('x'));
        $re = LaravelEvents::patterns(['eloquent.*', 'A\\B']);
        $this->assertTrue(LaravelEvents::excluded('eloquent.booted: Foo', $re));
        $this->assertTrue(LaravelEvents::excluded('A\\B', $re));
        $this->assertFalse(LaravelEvents::excluded('A\\BC', $re));
    }
}
