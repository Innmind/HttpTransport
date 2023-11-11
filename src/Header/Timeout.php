<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Header;

use Innmind\Http\{
    Header,
    Header\Value\Value,
};
use Innmind\Immutable\Set;

/**
 * This class is to be used to specify the timeout for a given request
 *
 * @psalm-immutable
 */
final class Timeout implements Header
{
    /** @var positive-int */
    private int $seconds;

    /**
     * @param positive-int $seconds
     */
    private function __construct(int $seconds)
    {
        $this->seconds = $seconds;
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

    public function name(): string
    {
        return 'X-Innmind-Timeout';
    }

    public function values(): Set
    {
        return Set::of(new Value((string) $this->seconds));
    }

    public function toString(): string
    {
        return (new Header\Header($this->name(), new Value((string) $this->seconds)))->toString();
    }
}
