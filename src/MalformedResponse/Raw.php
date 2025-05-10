<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\MalformedResponse;

use Innmind\Filesystem\File\Content;
use Innmind\Immutable\{
    Sequence,
    Str,
};

/**
 * @psalm-immutable
 */
final class Raw
{
    /**
     * @param Sequence<Str> $headers
     */
    private function __construct(
        private Str $statusLine,
        private Sequence $headers,
        private Content $body,
    ) {
    }

    /**
     * @psalm-pure
     *
     * @param Sequence<Str> $headers
     */
    public static function of(Str $statusLine, Sequence $headers, Content $body): self
    {
        return new self($statusLine, $headers, $body);
    }

    /**
     * @psalm-pure
     */
    public static function none(): self
    {
        return new self(Str::of(''), Sequence::of(), Content::none());
    }

    public function statusLine(): Str
    {
        return $this->statusLine;
    }

    /**
     * @return Sequence<Str>
     */
    public function headers(): Sequence
    {
        return $this->headers;
    }

    public function body(): Content
    {
        return $this->body;
    }
}
