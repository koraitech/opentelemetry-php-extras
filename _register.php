<?php

declare(strict_types=1);

use Korai\OpenTelemetry\Config;
use Korai\OpenTelemetry\Instrumentation;

// Runs from Composer's autoloader, before the application boots.
// Needs OpenTelemetry\Instrumentation\hook(), provided by the `opentelemetry` PECL extension or by
// the OpenTelemetry PHP distro (with OTEL_PHP_SCOPED_DEPS_BRIDGE_ENABLED=true so the spans join its traces).
(static function (): void {
    $config = Config::fromEnvironment();
    if ($config->isDisabled(Config::LARAVEL_EVENTS) && $config->isDisabled(Config::REDIS) && $config->isDisabled(Config::METHODS)) {
        return;
    }
    if (!function_exists('OpenTelemetry\\Instrumentation\\hook')) {
        return;
    }
    Instrumentation::register($config);
})();
