<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry\Tests\Unit;

use Korai\OpenTelemetry\Config;
use Korai\OpenTelemetry\Methods;
use Korai\OpenTelemetry\Tests\Support\TestCase;
use OpenTelemetry\API\Trace\SpanKind;

final class MethodsTest extends TestCase
{
    public function test_parses_the_method_list(): void
    {
        $config = new Config(['KORAI_OTEL_TRACE_METHODS' => '\\App\\Services\\Odds::format, Vendor\\Cms\\Page::render ,bad, Also::bad-name']);
        $this->assertSame([['App\\Services\\Odds', 'format'], ['Vendor\\Cms\\Page', 'render']], $config->tracedMethods());
    }

    public function test_span_per_call_of_a_listed_method(): void
    {
        Methods::register($this->hook(), $this->instrumentation, new Config(['KORAI_OTEL_TRACE_METHODS' => 'App\\Services\\Odds::format']));
        $this->assertArrayHasKey('app\\services\\odds::format', $this->hooks);

        $this->invoke('App\\Services\\Odds', 'format', null, [], fn () => 'outside a trace');
        $this->startRoot();
        $this->invoke('App\\Services\\Odds', 'format', null, [], fn () => usleep(1000));
        try {
            $this->invoke('App\\Services\\Odds', 'format', null, [], fn () => throw new \RuntimeException('bad odds'));
        } catch (\RuntimeException) {
        }
        $this->endRoot();

        $this->assertSame(['App\\Services\\Odds::format', 'App\\Services\\Odds::format'], $this->names());
        [$ok, $failed] = $this->spans();
        $this->assertSame(SpanKind::KIND_INTERNAL, $ok->getKind());
        $this->assertSame('Error', $failed->getStatus()->getCode());
    }
}
