<?php

declare(strict_types=1);

namespace Korai\OpenTelemetry;

use Closure;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

/**
 * Client spans for Redis commands, from the PHP Redis extension (phpredis, PHP 8.2 or later) and from Predis.
 *
 * The extension is hooked directly, so commands are traced whoever issues them: Laravel's cache,
 * queue and session drivers, other libraries, or the application itself. There is no need to call
 * Redis::enableEvents() in Laravel; doing so with Laravel's own Redis instrumentation enabled would
 * trace each command twice.
 */
final class Redis
{
    /** phpredis methods traced, as they are spelled in the extension (PHP method names are case-insensitive). */
    public const COMMANDS = [
        // connection
        'connect', 'pconnect', 'open', 'popen', 'auth', 'select', 'ping', 'close',
        // strings and keys
        'get', 'set', 'setex', 'psetex', 'setnx', 'getEx', 'getDel', 'getSet', 'mget', 'mset', 'msetnx', 'append',
        'del', 'unlink', 'exists', 'expire', 'pexpire', 'expireAt', 'pexpireAt', 'ttl', 'pttl', 'persist', 'type', 'rename',
        'incr', 'incrBy', 'incrByFloat', 'decr', 'decrBy', 'strlen', 'keys', 'scan',
        // hashes
        'hGet', 'hSet', 'hSetNx', 'hMGet', 'hMSet', 'hGetAll', 'hDel', 'hExists', 'hIncrBy', 'hIncrByFloat', 'hKeys', 'hVals', 'hLen', 'hScan',
        // lists
        'lPush', 'rPush', 'lPop', 'rPop', 'blPop', 'brPop', 'lRange', 'lLen', 'lRem', 'lTrim', 'lIndex', 'lSet', 'rPopLPush', 'brPopLPush', 'lMove', 'blMove',
        // sets
        'sAdd', 'sRem', 'sMembers', 'sIsMember', 'sCard', 'sPop', 'sRandMember', 'sInter', 'sUnion', 'sDiff', 'sScan',
        // sorted sets
        'zAdd', 'zRem', 'zRange', 'zRevRange', 'zRangeByScore', 'zRevRangeByScore', 'zRemRangeByScore', 'zRemRangeByRank',
        'zScore', 'zCard', 'zCount', 'zIncrBy', 'zRank', 'zRevRank', 'zPopMin', 'zPopMax', 'bzPopMin', 'bzPopMax', 'zScan',
        // streams, pub/sub, scripting, transactions
        'xAdd', 'xRead', 'xReadGroup', 'xAck', 'xLen', 'xRange', 'xDel', 'xTrim',
        'publish', 'eval', 'evalSha', 'script', 'multi', 'exec', 'discard', 'watch', 'unwatch', 'pipeline',
        // server
        'flushDb', 'flushAll', 'dbSize', 'info', 'rawCommand',
    ];

    /** @param Closure(?string, string, ?Closure, ?Closure): bool $hook */
    public static function register(Closure $hook, CachedInstrumentation $instrumentation, Config $config): void
    {
        $stack = new SpanStack();
        $keys = $config->redisKeys();
        $minNs = $config->redisMinDurationNs();
        /** @var list<array{\OpenTelemetry\API\Trace\SpanBuilderInterface, int}|null> $pending */
        $pending = [];

        $pre = static function ($client, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($stack, $instrumentation, $keys, $minNs, &$pending): void {
            if (!SpanStack::hasActiveParent()) {
                $minNs > 0 ? $pending[] = null : $stack->skip();

                return;
            }
            $operation = strtoupper((string) $function);
            if ($operation === 'RAWCOMMAND' && isset($params[0]) && is_string($params[0])) {
                $operation = strtoupper($params[0]);
                $params = array_slice($params, 1);
            }
            $builder = $instrumentation->tracer()->spanBuilder('REDIS ' . $operation)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setAttribute('db.system.name', 'redis')
                ->setAttribute('db.system', 'redis')
                ->setAttribute('db.operation.name', $operation)
                ->setAttribute('code.function.name', ($class ?? 'Redis') . '::' . $function);
            foreach (self::server($client, $function, $params) as $k => $v) {
                $builder->setAttribute($k, $v);
            }
            if ($keys && ($key = self::key($params)) !== null) {
                $builder->setAttribute('db.query.text', $operation . ' ' . $key);
            }
            if ($minNs > 0) {
                // The span is only created when the command turns out slow enough (or fails), backdated to its start.
                $pending[] = [$builder, self::now()];

                return;
            }
            $stack->push($builder->startSpan());
        };
        $post = static function ($client, array $params, $return, ?Throwable $exception) use ($stack, $minNs, &$pending): void {
            if ($minNs <= 0) {
                $stack->pop($exception);

                return;
            }
            $entry = array_pop($pending);
            if ($entry === null) {
                return;
            }
            [$builder, $start] = $entry;
            $end = self::now();
            if ($exception === null && $end - $start < $minNs) {
                return;
            }
            $span = $builder->setStartTimestamp($start)->startSpan();
            if ($exception !== null) {
                $span->recordException($exception);
                $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            }
            $span->end($end);
        };

        // Methods of PHP extensions can only be hooked from PHP 8.2 (observer API for internal functions).
        foreach (PHP_VERSION_ID >= 80200 ? ['Redis', 'RedisCluster'] : [] as $redisClass) {
            if (!class_exists($redisClass, false)) {
                continue;
            }
            foreach (self::COMMANDS as $command) {
                if (method_exists($redisClass, $command)) {
                    $hook($redisClass, $command, $pre, $post);
                }
            }
        }

        // Predis: every command goes through executeCommand(CommandInterface $command).
        $predisPre = static function ($client, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($pre): void {
            $command = $params[0] ?? null;
            if (!is_object($command) || !method_exists($command, 'getId')) {
                $pre($client, [], $class, 'command', $filename, $lineno);

                return;
            }
            $args = method_exists($command, 'getArguments') ? (array) $command->getArguments() : [];
            $pre($client, $args, $class, (string) $command->getId(), $filename, $lineno);
        };
        $hook('Predis\\Client', 'executeCommand', $predisPre, $post);
    }

    /** Wall-clock time in nanoseconds since the epoch, as span timestamps are. */
    private static function now(): int
    {
        return (int) (microtime(true) * 1_000_000_000);
    }

    /**
     * @param array<mixed> $params
     * @return array<string, string|int>
     */
    public static function server(mixed $client, ?string $function, array $params): array
    {
        $out = [];
        try {
            $fn = strtolower((string) $function);
            if (in_array($fn, ['connect', 'pconnect', 'open', 'popen'], true)) {
                if (isset($params[0]) && is_string($params[0])) {
                    $out['server.address'] = $params[0];
                }
                if (isset($params[1]) && is_int($params[1]) && $params[1] > 0) {
                    $out['server.port'] = $params[1];
                }

                return $out;
            }
            if (is_object($client) && $client instanceof \Redis) {
                $host = $client->getHost();
                if (is_string($host) && $host !== '') {
                    $out['server.address'] = $host;
                }
                $port = $client->getPort();
                if (is_int($port) && $port > 0) {
                    $out['server.port'] = $port;
                }
                $db = $client->getDbNum();
                if (is_int($db)) {
                    $out['db.namespace'] = (string) $db;
                }
            } elseif (is_object($client) && method_exists($client, 'getConnection')) {
                $connection = $client->getConnection();
                if (is_object($connection) && method_exists($connection, 'getParameters')) {
                    $p = $connection->getParameters();
                    if (is_object($p) && isset($p->host) && is_string($p->host)) {
                        $out['server.address'] = $p->host;
                    }
                    if (is_object($p) && isset($p->port) && is_numeric($p->port)) {
                        $out['server.port'] = (int) $p->port;
                    }
                    if (is_object($p) && isset($p->database) && is_numeric($p->database)) {
                        $out['db.namespace'] = (string) $p->database;
                    }
                }
            }
        } catch (Throwable) {
            // not connected yet, or a cluster client: no server attributes
        }

        return $out;
    }

    /** @param array<mixed> $params */
    public static function key(array $params): ?string
    {
        $first = $params[0] ?? null;
        if (is_string($first) || is_int($first)) {
            return (string) $first;
        }
        if (is_array($first) && $first !== []) {
            $names = array_is_list($first) ? $first : array_keys($first);
            $names = array_filter($names, static fn ($k): bool => is_string($k) || is_int($k));

            return implode(' ', array_slice(array_map('strval', $names), 0, 10)) . (count($names) > 10 ? ' …' : '');
        }

        return null;
    }
}
