<?php

declare(strict_types=1);

namespace Acme\Watcher;

/** Stands in for a package listener such as Telescope's watchers (it lives under a vendor/ directory). */
final class WatcherListener
{
    public function handle(mixed ...$payload): void
    {
    }

    public static function closure(): \Closure
    {
        return static function (mixed ...$payload): void {
        };
    }
}
