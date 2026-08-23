<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Transport;

use Innmind\HttpTransport\{
    Config,
    Transport\Curl\Scheduled,
    Transport\Curl\Concurrency,
};
use Innmind\Http\{
    Request,
    Factory\Header\Factory,
};
use Innmind\Url\Url;
use Innmind\Time\{
    Clock,
    Period,
};
use Innmind\IO\IO;
use Innmind\Immutable\Either;

/**
 * @internal
 * @psalm-import-type Errors from Implementation
 */
final class Curl implements Implementation
{
    /**
     * @param \Closure(): void $heartbeat
     */
    private function __construct(
        private Config $config,
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
            Config::new(),
            Factory::new($clock),
            $io,
            Concurrency::new(),
            Period::second(1),
            static fn() => null,
            false,
            null,
        );
    }

    /**
     * @internal
     *
     * @param Period $timeout Only seconds are allowed
     * @param callable(): void $heartbeat
     */
    public static function async(
        Clock $clock,
        IO $io,
        Period $timeout,
        callable $heartbeat,
    ): self {
        return new self(
            Config::new(),
            Factory::new($clock),
            $io,
            Concurrency::new(),
            $timeout,
            \Closure::fromCallable($heartbeat),
            false,
            null,
        );
    }

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    public function map(callable $map): self
    {
        /** @psalm-suppress ImpureFunctionCall */
        $config = $map($this->config);

        return new self(
            $config,
            $this->headerFactory,
            $config->async()->match(
                static fn($async) => $async->io(),
                fn() => $this->io,
            ),
            Concurrency::new($config->maxConcurrency()->match(
                static fn($max) => $max,
                static fn() => null,
            )),
            $config->async()->match(
                static fn() => Period::millisecond(10), // this is blocking the active task so it needs to be low
                fn() => $this->timeout,
            ),
            $config
                ->async()
                ->map(static fn($async) => $async->halt())
                ->match(
                    static fn($halt) => static fn() => $halt(Period::millisecond(1))->unwrap(), // this allows to jump between tasks
                    fn() => $this->heartbeat,
                ),
            !$config->verifySSL(),
            $config->proxy()->match(
                static fn($proxy) => $proxy,
                static fn() => null,
            ),
        );
    }

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    public function config(): Config
    {
        return $this->config;
    }
}
