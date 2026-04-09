<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Request;
use Innmind\IO\IO;
use Innmind\Time\{
    Clock,
    Period,
    Halt,
};
use Innmind\Immutable\{
    Either,
    Sequence,
};
use Psr\Log\LoggerInterface;

/**
 * @psalm-type Errors = Failure|ConnectionFailed|MalformedResponse|Information|Redirection|ClientError|ServerError
 */
final class Transport
{
    private function __construct(
        private Implementation $implementation,
    ) {
    }

    /**
     * @return Either<Errors, Success>
     */
    #[\NoDiscard]
    public function __invoke(Request $request): Either
    {
        return ($this->implementation)($request);
    }

    public static function curl(
        Clock $clock,
        ?IO $io = null,
    ): self {
        return new self(Curl::of($clock, $io));
    }

    public static function circuitBreaker(
        self $transport,
        Clock $clock,
        Period $delayBeforeRetry,
    ): self {
        return new self(CircuitBreaker::of(
            $transport->implementation,
            $clock,
            $delayBeforeRetry,
        ));
    }

    /**
     * @param ?Sequence<Period> $retries
     */
    public static function exponentialBackoff(
        self $transport,
        Halt $halt,
        ?Sequence $retries = null,
    ): self {
        return new self(ExponentialBackoff::of(
            $transport->implementation,
            $halt,
            $retries,
        ));
    }

    public static function followRedirections(self $transport): self
    {
        return new self(FollowRedirections::of($transport->implementation));
    }

    public static function logger(
        self $transport,
        LoggerInterface $logger,
    ): self {
        return new self(Logger::psr(
            $transport->implementation,
            $logger,
        ));
    }

    /**
     * @internal
     *
     * @param callable(): void $heartbeat
     */
    public static function async(
        Clock $clock,
        IO $io,
        Period $timeout,
        callable $heartbeat,
    ): self {
        return new self(Curl::async(
            $clock,
            $io,
            $timeout,
            $heartbeat,
        ));
    }

    /**
     * @internal
     *
     * @param callable(Request): Either<Errors, Success> $via
     */
    public static function via(callable $via): self
    {
        return new self(Via::of($via));
    }

    /**
     * @psalm-mutation-free
     *
     * @param callable(Config): Config $map
     */
    #[\NoDiscard]
    public function map(callable $map): self
    {
        return new self($this->implementation->map($map));
    }
}
