<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Request;

final class Failure
{
    public function __construct(
        private Request $request,
        private string $reason,
    ) {
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
