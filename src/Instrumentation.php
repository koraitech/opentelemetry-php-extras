<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry;

use Closure;
use Composer\InstalledVersions;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;

/**
 * Entry point: registers every enabled instrumentation.
 */
final class Instrumentation
{
    public const SCOPE = 'tech.korai.opentelemetry.php-extras';
    public const PACKAGE = 'koraitech/opentelemetry-php-extras';

    private static bool $registered = false;

    /**
     * @param (Closure(?string, string, ?Closure, ?Closure): bool)|null $hook defaults to OpenTelemetry\Instrumentation\hook()
     */
    public static function register(?Config $config = null, ?Closure $hook = null, ?CachedInstrumentation $instrumentation = null): bool
    {
        if (self::$registered && $hook === null) {
            return false;
        }
        $config ??= Config::fromEnvironment();
        if ($hook === null) {
            if (!function_exists('OpenTelemetry\\Instrumentation\\hook')) {
                return false;
            }
            $hook = \OpenTelemetry\Instrumentation\hook(...);
        }
        $instrumentation ??= new CachedInstrumentation(self::SCOPE, self::version());

        if (!$config->isDisabled(Config::LARAVEL_EVENTS)) {
            LaravelEvents::register($hook, $instrumentation, $config);
        }
        if (!$config->isDisabled(Config::REDIS)) {
            Redis::register($hook, $instrumentation, $config);
        }
        if (!$config->isDisabled(Config::METHODS)) {
            Methods::register($hook, $instrumentation, $config);
        }
        self::$registered = true;

        return true;
    }

    public static function version(): ?string
    {
        try {
            return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)
                ? InstalledVersions::getPrettyVersion(self::PACKAGE)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
