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
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    Period,
};
use Innmind\IO\IO;
use Innmind\Immutable\Either;

/**
 * @psalm-import-type Errors from Transport
 */
final class Curl implements Transport
{
    private Factory $headerFactory;
    private IO $io;
    private Concurrency $concurrency;
    private ElapsedPeriod $timeout;
    /** @var callable(): void */
    private $heartbeat;
    private bool $disableSSLVerification;

    /**
     * @param callable(): void $heartbeat
     */
    private function __construct(
        Factory $headerFactory,
        IO $io,
        Concurrency $concurrency,
        ElapsedPeriod $timeout,
        callable $heartbeat,
        bool $disableSSLVerification,
    ) {
        $this->headerFactory = $headerFactory;
        $this->io = $io;
        $this->concurrency = $concurrency;
        $this->timeout = $timeout;
        $this->heartbeat = $heartbeat;
        $this->disableSSLVerification = $disableSSLVerification;
    }

    #[\Override]
    public function __invoke(Request $request): Either
    {
        $scheduled = Scheduled::of(
            $this->headerFactory,
            $this->io,
            $request,
            $this->disableSSLVerification,
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
            Period::second(1)->asElapsedPeriod(),
            static fn() => null,
            false,
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param positive-int $max
     */
    public function maxConcurrency(int $max): self
    {
        return new self(
            $this->headerFactory,
            $this->io,
            Concurrency::new($max),
            $this->timeout,
            $this->heartbeat,
            $this->disableSSLVerification,
        );
    }

    /**
     * @psalm-mutation-free
     *
     * @param callable(): void $heartbeat
     */
    public function heartbeat(ElapsedPeriod $timeout, ?callable $heartbeat = null): self
    {
        return new self(
            $this->headerFactory,
            $this->io,
            $this->concurrency,
            $timeout,
            $heartbeat ?? static fn() => null,
            $this->disableSSLVerification,
        );
    }

    /**
     * You should use this method only when trying to call a server you own that
     * uses a self signed certificate that will fail the verification.
     *
     * @psalm-mutation-free
     */
    public function disableSSLVerification(): self
    {
        return new self(
            $this->headerFactory,
            $this->io,
            $this->concurrency,
            $this->timeout,
            $this->heartbeat,
            true,
        );
    }
}
