<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\{
    Curl\Scheduled,
    Curl\Ready,
    Curl\Multi,
    Transport,
    Failure,
    Success,
};
use Innmind\Http\{
    Message\Request,
    Factory\Header\TryFactory,
    Factory\Header\Factories,
};
use Innmind\TimeContinuum\Clock;
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
    private Multi $multi;

    /**
     * @param callable(Content): Sequence<Str> $chunk
     */
    private function __construct(
        Clock $clock,
        Capabilities $capabilities,
        callable $chunk,
    ) {
        $this->headerFactory = new TryFactory(
            Factories::default($clock),
        );
        $this->capabilities = $capabilities;
        $this->chunk = $chunk;
        $this->multi = Multi::new();
    }

    public function __invoke(Request $request): Either
    {
        $scheduled = Scheduled::of(
            $this->headerFactory,
            $this->capabilities,
            $this->chunk,
            $request,
        );
        $this->multi->add($scheduled);

        return Either::defer(function() use ($scheduled) {
            $this->multi->exec();

            return $this->multi->response($scheduled);
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
        return new self($clock, $capabilities ?? Streams::fromAmbientAuthority(), $chunk);
    }
}
