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
    Message\Request,
    Factory\Header\TryFactory,
    Factory\Header\Factories,
};
use Innmind\TimeContinuum\{
    Clock,
    ElapsedPeriod,
    Earth,
};
use Innmind\Filesystem\{
    File\Content,
    Chunk,
};
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
    /** @var callable(Content): Sequence<Str> */
    private $chunk;
    private Concurrency $concurrency;
    private ElapsedPeriod $timeout;
    /** @var callable(): void */
    private $heartbeat;

    /**
     * @param callable(Content): Sequence<Str> $chunk
     * @param callable(): void $heartbeat
     */
    private function __construct(
        TryFactory $headerFactory,
        Capabilities $capabilities,
        callable $chunk,
        Concurrency $concurrency,
        ElapsedPeriod $timeout,
        callable $heartbeat,
    ) {
        $this->headerFactory = $headerFactory;
        $this->capabilities = $capabilities;
        $this->chunk = $chunk;
        $this->concurrency = $concurrency;
        $this->timeout = $timeout;
        $this->heartbeat = $heartbeat;
    }

    public function __invoke(Request $request): Either
    {
        $scheduled = Scheduled::of(
            $this->headerFactory,
            $this->capabilities,
            $this->chunk,
            $request,
        );
        $this->concurrency->add($scheduled);

        return Either::defer(function() use ($scheduled) {
            $this->concurrency->run($this->timeout, $this->heartbeat);

            return $this->concurrency->response($scheduled);
        });
    }

    /**
     * @param callable(Content): Sequence<Str> $chunk
     */
    public static function of(
        Clock $clock,
        callable $chunk = new Chunk,
        Capabilities $capabilities = null,
    ): self {
        return new self(
            new TryFactory(
                Factories::default($clock),
            ),
            $capabilities ?? Streams::fromAmbientAuthority(),
            $chunk,
            Concurrency::new(),
            new Earth\ElapsedPeriod(1_000), // 1 second
            static fn() => null,
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
            $this->chunk,
            Concurrency::new($max),
            $this->timeout,
            $this->heartbeat,
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
            $this->chunk,
            $this->concurrency,
            $timeout,
            $heartbeat ?? static fn() => null,
        );
    }
}
