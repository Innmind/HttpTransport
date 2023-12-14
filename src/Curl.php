<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\{
    Curl\Scheduled,
    Curl\Ready,
    Curl\Concurrency,
    Transport,
    Failure,
    Success,
};
use Innmind\Http\{
    Request,
    Factory\Header\TryFactory,
    Factory\Header\Factories,
};
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    Earth,
};
use Innmind\IO\IO;
use Innmind\Stream\{
    Capabilities,
    Streams,
};
use Innmind\Immutable\{
    Either,
    Str,
    Sequence,
};

/**
 * @psalm-import-type Errors from Transport
 */
final class Curl implements Transport
{
    private TryFactory $headerFactory;
    private Capabilities $capabilities;
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
        TryFactory $headerFactory,
        Capabilities $capabilities,
        IO $io,
        Concurrency $concurrency,
        ElapsedPeriod $timeout,
        callable $heartbeat,
        bool $disableSSLVerification,
    ) {
        $this->headerFactory = $headerFactory;
        $this->capabilities = $capabilities;
        $this->io = $io;
        $this->concurrency = $concurrency;
        $this->timeout = $timeout;
        $this->heartbeat = $heartbeat;
        $this->disableSSLVerification = $disableSSLVerification;
    }

    public function __invoke(Request $request): Either
    {
        $scheduled = Scheduled::of(
            $this->headerFactory,
            $this->capabilities,
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
        Capabilities $capabilities = null,
        IO $io = null,
    ): self {
        $capabilities ??= Streams::fromAmbientAuthority();
        $io ??= IO::of(static fn(?ElapsedPeriod $timeout) => match ($timeout) {
            null => $capabilities->watch()->waitForever(),
            default => $capabilities->watch()->timeoutAfter($timeout),
        });

        return new self(
            new TryFactory(
                Factories::default($clock),
            ),
            $capabilities,
            $io,
            Concurrency::new(),
            new Earth\ElapsedPeriod(1_000), // 1 second
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
            $this->capabilities,
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
    public function heartbeat(ElapsedPeriod $timeout, callable $heartbeat = null): self
    {
        return new self(
            $this->headerFactory,
            $this->capabilities,
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
            $this->capabilities,
            $this->io,
            $this->concurrency,
            $this->timeout,
            $this->heartbeat,
            true,
        );
    }
}
