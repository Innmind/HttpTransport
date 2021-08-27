<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Translator\Response\FromPsr7,
    Factory\Header\Factories,
};
use Innmind\TimeWarp\Halt;
use Innmind\TimeContinuum\{
    Clock,
    Period,
};
use GuzzleHttp\{
    ClientInterface,
    Client,
};
use Psr\Log\LoggerInterface;

/**
 * @return array{
 *   default: callable(?ClientInterface): Transport,
 *   logger: callable(LoggerInterface): (callable(Transport): Transport),
 *   exponential_backoff: callable(Transport, Halt, Clock): Transport,
 *   circuit_breaker: callable(Transport, Clock, Period): Transport
 * }
 */
function bootstrap(Clock $clock): array
{
    /**
     * @var array{
     *   default: callable(?ClientInterface): Transport,
     *   logger: callable(LoggerInterface): (callable(Transport): Transport),
     *   exponential_backoff: callable(Transport, Halt): Transport,
     *   circuit_breaker: callable(Transport, Clock, Period): Transport
     * }
     */
    return [
        'default' => static function(ClientInterface $client = null) use ($clock): Transport {
            return new DefaultTransport(
                $client ?? new Client,
                new FromPsr7(Factories::default($clock)),
            );
        },
        'logger' => static function(LoggerInterface $logger): callable {
            return static function(Transport $transport) use ($logger): Transport {
                return new LoggerTransport(
                    $transport,
                    $logger,
                );
            };
        },
        'exponential_backoff' => static function(Transport $transport, Halt $halt): Transport {
            return ExponentialBackoffTransport::of($transport, $halt);
        },
        'circuit_breaker' => static function(Transport $transport, Clock $clock, Period $delayBeforeRetry): Transport {
            return new CircuitBreakerTransport($transport, $clock, $delayBeforeRetry);
        },
    ];
}
