<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Fake;

/** Stands in for a listener registered by the OpenTelemetry Laravel instrumentation. */
final class Watcher
{
    public function record(): void
    {
    }
}
