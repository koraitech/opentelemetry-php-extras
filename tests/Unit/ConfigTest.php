<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry\Tests\Unit;

use Korai\OpenTelemetry\Config;
use Korai\OpenTelemetry\Instrumentation;
use Korai\OpenTelemetry\Tests\Support\TestCase;

final class ConfigTest extends TestCase
{
    public function test_defaults(): void
    {
        $c = new Config([]);
        $this->assertFalse($c->isDisabled(Config::LARAVEL_EVENTS));
        $this->assertFalse($c->isDisabled(Config::REDIS));
        $this->assertTrue($c->eventsRequireParent());
        $this->assertFalse($c->redisKeys());
        $this->assertSame([], $c->excludedEvents());
    }

    public function test_standard_disabled_list(): void
    {
        $c = new Config(['OTEL_PHP_DISABLED_INSTRUMENTATIONS' => 'pdo, korai-redis']);
        $this->assertTrue($c->isDisabled(Config::REDIS));
        $this->assertFalse($c->isDisabled(Config::LARAVEL_EVENTS));
        $this->assertTrue((new Config(['OTEL_PHP_DISABLED_INSTRUMENTATIONS' => 'all']))->isDisabled(Config::LARAVEL_EVENTS));
        $this->assertTrue((new Config(['OTEL_SDK_DISABLED' => 'true']))->isDisabled(Config::REDIS));
    }

    public function test_register_only_enabled_parts(): void
    {
        Instrumentation::register(new Config(['OTEL_PHP_DISABLED_INSTRUMENTATIONS' => 'korai-redis']), $this->hook(), $this->instrumentation);
        $this->assertArrayHasKey('illuminate\\events\\dispatcher::dispatch', $this->hooks);
        $this->assertArrayNotHasKey('predis\\client::executecommand', $this->hooks);
    }
}
