<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\Curl\{
    Scheduled,
    Concurrency
};
use Innmind\Http\{
    Request,
    Factory\Header\Factory,
};
use Innmind\Url\Url;
use Innmind\TimeContinuum\{
    Clock,
    Period,
};
use Innmind\IO\IO;
use Innmind\Immutable\Either;

/**
 * @psalm-import-type Errors from Transport
 */
final class Curl implements Implementation
{
    /**
     * @param \Closure(): void $heartbeat
     */
    private function __construct(
        private Factory $headerFactory,
        private IO $io,
        private Concurrency $concurrency,
        private Period $timeout,
        private \Closure $heartbeat,
        private bool $disableSSLVerification,
        private ?Url $proxy,
    ) {
    }

    #[\Override]
    public function __invoke(Request $request): Either
    {
        $scheduled = Scheduled::of(
            $this->headerFactory,
            $this->io,
            $request,
            $this->disableSSLVerification,
            $this->proxy,
        );
        $this->concurrency->add($scheduled);

        return Either::defer(function() use ($scheduled) {
            $this->concurrency->run($this->timeout, $this->heartbeat);

            return $this->concurrency->response($scheduled);
        });
    }

    public static function of(
        Clock $clock,
        ?IO $io = null,
    ): self {
        $io ??= IO::fromAmbientAuthority();

        return new self(
            Factory::new($clock),
            $io,
            Concurrency::new(),
            Period::second(1),
            static fn() => null,
            false,
            null,
        );
    }

    #[\Override]
    public function map(Config $config): self
    {
        return new self(
            $this->headerFactory,
            $this->io,
            Concurrency::new($config->maxConcurrency()->match(
                static fn($max) => $max,
                static fn() => null,
            )),
            $this->timeout,
            $this->heartbeat,
            !$config->verifySSL(),
            $config->proxy()->match(
                static fn($proxy) => $proxy,
                static fn() => null,
            ),
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param Period $timeout Only seconds are allowed
     * @param callable(): void $heartbeat
     */
    #[\NoDiscard]
    public function heartbeat(Period $timeout, ?callable $heartbeat = null): self
    {
        return new self(
            $this->headerFactory,
            $this->io,
            $this->concurrency,
            $timeout,
            match ($heartbeat) {
                null => static fn() => null,
                default => \Closure::fromCallable($heartbeat),
            },
            $this->disableSSLVerification,
            $this->proxy,
        );
    }
}
