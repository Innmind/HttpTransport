<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Config;

use Innmind\IO\IO;
use Innmind\Time\Clock;
use Innmind\Time\Halt;

/**
 * @psalm-immutable
 * @internal
 */
final class Async
{
    public function __construct(
        private Clock $clock,
        private Halt $halt,
        private IO $io,
    ) {
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function halt(): Halt
    {
        return $this->halt;
    }

    public function io(): IO
    {
        return $this->io;
    }
}
