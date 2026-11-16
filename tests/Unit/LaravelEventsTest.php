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

final class TelemetryWatcherLike
{
    public function record(): void
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

    private function register(array $env = ['KORAI_OTEL_LARAVEL_EVENTS' => 'all']): void
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
        $this->register(['KORAI_OTEL_LARAVEL_EVENTS' => 'all', 'KORAI_OTEL_LARAVEL_EVENTS_REQUIRE_PARENT' => 'false']);
        $this->dispatch('booting');
        $this->assertSame(['booting'], $this->names());
    }

    public function test_excluded_events_create_no_span_and_keep_nesting_right(): void
    {
        $this->register(['KORAI_OTEL_LARAVEL_EVENTS' => 'all', 'KORAI_OTEL_LARAVEL_EVENTS_EXCLUDE' => 'eloquent.*, Illuminate\\Log\\Events\\MessageLogged']);
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

    public function test_by_default_only_events_with_listeners_of_the_application(): void
    {
        $this->register([]);
        $this->events->listen('app.event', fn () => null);
        $this->events->listen('watched.only', [new \OpenTelemetry\Tests\Fake\Watcher(), 'record']);
        $this->startRoot();
        $this->dispatch('app.event');
        $this->dispatch('no.listeners');
        $this->dispatch('watched.only');
        $this->endRoot();

        $this->assertSame(['app.event'], $this->names());
    }

    public function test_wildcard_and_interface_listeners_count(): void
    {
        $this->register([]);
        $this->events->listen('orders.*', fn () => null);
        $this->events->listen(\JsonSerializable::class, fn () => null);
        $event = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return [];
            }
        };
        $this->startRoot();
        $this->dispatch('orders.created');
        $this->dispatch($event);
        $this->endRoot();

        $this->assertCount(2, $this->names());
    }

    public function test_package_listeners_do_not_count_even_wildcards(): void
    {
        // Telescope-like tools listen to every event from vendor/: that must not trace every model and cache read.
        $this->register([]);
        $this->events->listen('*', \Acme\Watcher\WatcherListener::closure());
        $this->events->listen('eloquent.*', [new \Acme\Watcher\WatcherListener(), 'handle']);
        $this->events->listen('cache.hit', \Acme\Watcher\WatcherListener::class . '@handle');
        $this->events->listen('app.event', fn () => null);
        $this->startRoot();
        $this->dispatch('eloquent.retrieved: App\\Models\\Country');
        $this->dispatch('cache.hit');
        $this->dispatch('app.event');
        $this->dispatch('app.event');
        $this->endRoot();

        $this->assertSame(['app.event', 'app.event'], $this->names());
        $this->assertTrue(LaravelEvents::isVendorListener(\Acme\Watcher\WatcherListener::closure()));
        $this->assertFalse(LaravelEvents::isVendorListener(fn () => null));
        $this->assertTrue(LaravelEvents::wildcardMatches('eloquent.*', 'eloquent.retrieved: X'));
        $this->assertFalse(LaravelEvents::wildcardMatches('orders.*', 'eloquent.retrieved: X'));
    }

    public function test_a_listener_added_later_is_noticed(): void
    {
        $this->register([]);
        $this->startRoot();
        $this->dispatch('late.event');
        $this->events->listen('late.event', fn () => null);
        $this->dispatch('late.event');
        $this->endRoot();

        $this->assertSame(['late.event'], $this->names());
    }

    public function test_telemetry_listeners_are_recognised(): void
    {
        $this->assertTrue(LaravelEvents::isTelemetryListener([new \OpenTelemetry\Tests\Fake\Watcher(), 'record']));
        $this->assertTrue(LaravelEvents::isTelemetryListener('OTelDistroScoped\\OpenTelemetry\\Contrib\\Instrumentation\\Laravel\\Watchers\\CacheWatcher@recordCacheHit'));
        $this->assertFalse(LaravelEvents::isTelemetryListener([new TelemetryWatcherLike(), 'record']));
        $this->assertFalse(LaravelEvents::isTelemetryListener(fn () => null));
    }
}
