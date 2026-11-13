<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry\Tests\Unit;

use Korai\OpenTelemetry\Config;
use Korai\OpenTelemetry\Redis;
use Korai\OpenTelemetry\Tests\Support\TestCase;
use OpenTelemetry\API\Trace\SpanKind;

final class RedisTest extends TestCase
{
    private function register(array $env = []): void
    {
        Redis::register($this->hook(), $this->instrumentation, new Config($env));
    }

    public function test_hooks_phpredis_methods_and_predis(): void
    {
        $this->register();
        $this->assertArrayHasKey('predis\\client::executecommand', $this->hooks);
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('ext-redis not loaded');
        }
        $this->assertArrayHasKey('redis::get', $this->hooks);
        $this->assertArrayHasKey('redis::hgetall', $this->hooks);
        $this->assertArrayHasKey('redis::pconnect', $this->hooks);
    }

    public function test_client_span_per_command_without_key_by_default(): void
    {
        $this->register();
        $this->startRoot();
        $this->invoke('Redis', 'get', null, ['session:abc'], fn () => 'v');
        $this->invoke('Redis', 'pconnect', null, ['10.1.0.6', 6379], fn () => true);
        $this->endRoot();

        [$get, $connect] = $this->spans();
        $this->assertSame('REDIS GET', $get->getName());
        $this->assertSame(SpanKind::KIND_CLIENT, $get->getKind());
        $this->assertSame('redis', $get->getAttributes()->get('db.system.name'));
        $this->assertSame('GET', $get->getAttributes()->get('db.operation.name'));
        $this->assertNull($get->getAttributes()->get('db.query.text'));
        $this->assertSame('REDIS PCONNECT', $connect->getName());
        $this->assertSame('10.1.0.6', $connect->getAttributes()->get('server.address'));
        $this->assertSame(6379, $connect->getAttributes()->get('server.port'));
    }

    public function test_keys_when_enabled_and_raw_command(): void
    {
        $this->register(['KORAI_OTEL_REDIS_KEYS' => 'true']);
        $this->startRoot();
        $this->invoke('Redis', 'mget', null, [['a', 'b']], fn () => []);
        $this->invoke('Redis', 'rawCommand', null, ['client', 'list'], fn () => '');
        $this->endRoot();

        [$mget, $raw] = $this->spans();
        $this->assertSame('MGET a b', $mget->getAttributes()->get('db.query.text'));
        $this->assertSame('REDIS CLIENT', $raw->getName());
    }

    public function test_predis_command_object(): void
    {
        $this->register();
        $command = new class {
            public function getId(): string
            {
                return 'HGETALL';
            }

            public function getArguments(): array
            {
                return ['user:1'];
            }
        };
        $this->startRoot();
        $this->invoke('Predis\\Client', 'executeCommand', null, [$command], fn () => []);
        $this->endRoot();

        $this->assertSame(['REDIS HGETALL'], $this->names());
    }

    public function test_no_span_outside_a_trace(): void
    {
        $this->register();
        $this->invoke('Redis', 'get', null, ['k'], fn () => null);
        $this->assertSame([], $this->names());
    }

    public function test_failed_command_is_an_error(): void
    {
        $this->register();
        $this->startRoot();
        try {
            $this->invoke('Redis', 'get', null, ['k'], fn () => throw new \RuntimeException('READONLY'));
        } catch (\RuntimeException) {
        }
        $this->endRoot();
        $this->assertSame('Error', $this->spans()[0]->getStatus()->getCode());
    }
}
