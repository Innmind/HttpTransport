<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\Curl\Handle;
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

final class Curl implements Transport
{
    private TryFactory $headerFactory;
    private Capabilities $capabilities;
    /** @var callable(Content): Sequence<Str> */
    private $chunk;

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
    }

    public function __invoke(Request $request): Either
    {
        $handle = Handle::of(
            $this->headerFactory,
            $this->capabilities,
            $this->chunk,
            $request,
        );

        return Either::defer(static function() use ($handle) {
            return $handle(static function(\CurlHandle $handle): int {
                $multiHandle = \curl_multi_init();
                \curl_multi_add_handle($multiHandle, $handle);

                do {
                    $status = \curl_multi_exec($multiHandle, $stillActive);

                    if ($stillActive) {
                        // Wait a short time for more activity
                        \curl_multi_select($multiHandle);
                    }
                } while ($stillActive && $status === \CURLM_OK);

                /** @var int */
                return \curl_multi_info_read($multiHandle)['result'];
            });
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
