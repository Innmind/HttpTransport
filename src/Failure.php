<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Request;

/**
 * @psalm-immutable
 */
final class Failure
{
    public function __construct(
        private Request $request,
        private string $reason,
    ) {
    }

    #[\NoDiscard]
    public function request(): Request
    {
        return $this->request;
    }

    #[\NoDiscard]
    public function reason(): string
    {
        return $this->reason;
    }
}
