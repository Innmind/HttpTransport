<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Translator\Response\Psr7Translator,
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
 *   logger: callable(LoggerInterface): Transport,
 *   throw_on_error: callable(Transport): Transport,
 *   exponential_backoff: callable(Transport, Halt, Clock): Transport,
 *   circuit_breaker: callable(Transport, Clock, Period): Transport
 * }
 */
function bootstrap(): array
{
    /**
     * @var array{
     *   default: callable(?ClientInterface): Transport,
     *   logger: callable(LoggerInterface): Transport,
     *   throw_on_error: callable(Transport): Transport,
     *   exponential_backoff: callable(Transport, Halt, Clock): Transport,
     *   circuit_breaker: callable(Transport, Clock, Period): Transport
     * }
     */
    return [
        'default' => static function(ClientInterface $client = null): Transport {
            return new DefaultTransport(
                $client ?? new Client,
                new Psr7Translator(Factories::default()),
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
        'throw_on_error' => static function(Transport $transport): Transport {
            return new ThrowOnErrorTransport($transport);
        },
        'exponential_backoff' => static function(Transport $transport, Halt $halt, Clock $clock): Transport {
            return ExponentialBackoffTransport::of($transport, $halt, $clock);
        },
        'circuit_breaker' => static function(Transport $transport, Clock $clock, Period $delayBeforeRetry): Transport {
            return new CircuitBreakerTransport($transport, $clock, $delayBeforeRetry);
        },
    ];
}
