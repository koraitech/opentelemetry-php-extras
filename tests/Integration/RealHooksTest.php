<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry\Tests\Integration;

use ArrayObject;
use Illuminate\Events\Dispatcher;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

/**
 * With the `opentelemetry` extension loaded, Composer's autoloader has already registered the
 * hooks (_register.php). These tests only install an SDK and exercise real code.
 */
final class RealHooksTest extends TestCase
{
    private ArrayObject $exported;
    private ScopeInterface $sdk;

    protected function setUp(): void
    {
        if (!extension_loaded('opentelemetry')) {
            $this->markTestSkipped('the opentelemetry extension is not loaded');
        }
        $this->exported = new ArrayObject();
        $provider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($this->exported)));
        $this->sdk = Sdk::builder()->setTracerProvider($provider)->setAutoShutdown(false)->buildAndRegisterGlobal();
    }

    protected function tearDown(): void
    {
        if (isset($this->sdk)) {
            $this->sdk->detach();
        }
    }

    /** @return list<string> */
    private function inRequest(callable $work): array
    {
        $root = Globals::tracerProvider()->getTracer('test')->spanBuilder('GET /')->startSpan();
        $scope = $root->activate();
        try {
            $work();
        } finally {
            $scope->detach();
            $root->end();
        }

        return array_values(array_filter(
            array_map(static fn ($s): string => $s->getName(), $this->exported->getArrayCopy()),
            static fn (string $n): bool => $n !== 'GET /',
        ));
    }

    public function test_laravel_dispatcher_creates_event_spans(): void
    {
        $events = new Dispatcher();
        $events->listen('cache.hit', static fn () => null);
        $events->listen(\ArrayObject::class, static fn () => null);
        $events->listen('eloquent.*', static fn () => null);
        $names = $this->inRequest(static function () use ($events): void {
            $events->dispatch('cache.hit');
            $events->dispatch(new \ArrayObject());
            $events->until('eloquent.booting: App\\Models\\Page');
            $events->dispatch('no.listeners'); // default mode: no span
        });

        $this->assertSame(['cache.hit', 'ArrayObject', 'eloquent.booting: App\\Models\\Page'], $names);
    }

    public function test_phpredis_commands_create_client_spans(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis not loaded');
        }
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $names = $this->inRequest(static function () use ($host): void {
            $redis = new \Redis();
            try {
                $redis->connect($host, 6379, 1.0);
            } catch (\Throwable $e) {
                self::markTestSkipped('no Redis server at ' . $host . ': ' . $e->getMessage());
            }
            $redis->set('korai:test', '1');
            $redis->get('korai:test');
            $redis->del('korai:test');
        });

        // PHP 8.1 cannot hook methods of extensions, so phpredis is not traced there.
        $this->assertSame(PHP_VERSION_ID >= 80200 ? ['REDIS CONNECT', 'REDIS SET', 'REDIS GET', 'REDIS DEL'] : [], $names);
    }
}
