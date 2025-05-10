<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Header;

use Innmind\Http\{
    Header,
    Header\Value,
    Header\Custom,
};

/**
 * This class is to be used to specify the timeout for a given request
 *
 * @psalm-immutable
 */
final class Timeout implements Custom
{
    /**
     * @param positive-int $seconds
     */
    private function __construct(
        private int $seconds,
    ) {
    }

    /**
     * @psalm-pure
     *
     * @param positive-int $seconds
     */
    public static function of(int $seconds): self
    {
        return new self($seconds);
    }

    /**
     * @return positive-int
     */
    public function seconds(): int
    {
        return $this->seconds;
    }

    #[\Override]
    public function normalize(): Header
    {
        return Header::of(
            'X-Innmind-Timeout',
            Value::of((string) $this->seconds),
        );
    }
}
