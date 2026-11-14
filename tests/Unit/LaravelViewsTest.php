<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry\Tests\Unit;

use Korai\OpenTelemetry\Config;
use Korai\OpenTelemetry\LaravelViews;
use Korai\OpenTelemetry\Tests\Support\TestCase;
use OpenTelemetry\API\Trace\SpanKind;

final class LaravelViewsTest extends TestCase
{
    public function test_span_per_view_named_after_the_view_and_nested(): void
    {
        LaravelViews::register($this->hook(), $this->instrumentation, new Config([]));
        $this->assertArrayHasKey('illuminate\\view\\view::render', $this->hooks);

        $page = new FakeView('pages.home', '/app/resources/views/pages/home.blade.php');
        $block = new FakeView('cms.blocks.hero', '/app/resources/views/cms/blocks/hero.blade.php');

        $this->invoke(LaravelViews::CLASS_NAME, 'render', $page, [], fn () => 'outside a trace');
        $this->startRoot();
        $this->invoke(LaravelViews::CLASS_NAME, 'render', $page, [], fn () => $this->invoke(LaravelViews::CLASS_NAME, 'render', $block, [], fn () => '<div>hero</div>'));
        $this->endRoot();

        $this->assertSame(['cms.blocks.hero', 'pages.home'], $this->names());
        [$hero, $home] = $this->spans();
        $this->assertSame(SpanKind::KIND_INTERNAL, $hero->getKind());
        $this->assertSame($home->getSpanId(), $hero->getParentSpanId());
        $this->assertSame($this->rootSpanId(), $home->getParentSpanId());
        $this->assertSame('cms.blocks.hero', $hero->getAttributes()->get('laravel.view.name'));
        $this->assertSame('/app/resources/views/cms/blocks/hero.blade.php', $hero->getAttributes()->get('code.file.path'));
    }

    public function test_excluded_views_and_failures(): void
    {
        LaravelViews::register($this->hook(), $this->instrumentation, new Config(['KORAI_OTEL_LARAVEL_VIEWS_EXCLUDE' => 'components.*']));
        $this->startRoot();
        $this->invoke(LaravelViews::CLASS_NAME, 'render', new FakeView('components.icon', null), [], fn () => '<svg/>');
        try {
            $this->invoke(LaravelViews::CLASS_NAME, 'render', new FakeView('cms.blocks.odds', null), [], fn () => throw new \RuntimeException('feed down'));
        } catch (\RuntimeException) {
        }
        $this->endRoot();

        $this->assertSame(['cms.blocks.odds'], $this->names());
        $this->assertSame('Error', $this->spans()[0]->getStatus()->getCode());
        $this->assertNull($this->spans()[0]->getAttributes()->get('code.file.path'));
    }
}

final class FakeView
{
    public function __construct(private readonly string $name, private readonly ?string $path)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }
}
