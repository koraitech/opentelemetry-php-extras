<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * Pairs each pre hook with its post hook.
 *
 * A pre hook may decide not to create a span (excluded event, no parent trace...). The post
 * hook must then leave the current scope alone, because it belongs to someone else. Hooked calls
 * nest strictly, so a stack per instrumentation is enough.
 */
final class SpanStack
{
    /** @var list<array{SpanInterface, ScopeInterface}|null> */
    private array $entries = [];

    public function push(SpanInterface $span): void
    {
        $this->entries[] = [$span, Context::storage()->attach($span->storeInContext(Context::getCurrent()))];
    }

    public function skip(): void
    {
        $this->entries[] = null;
    }

    public function pop(?Throwable $exception = null): void
    {
        $entry = array_pop($this->entries);
        if ($entry === null) {
            return;
        }
        [$span, $scope] = $entry;
        $scope->detach();
        if ($exception !== null) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }
        $span->end();
    }

    public function depth(): int
    {
        return count($this->entries);
    }

    public static function hasActiveParent(): bool
    {
        return Span::getCurrent()->getContext()->isValid();
    }
}
