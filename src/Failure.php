<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Message\Request;

final class Failure
{
    private Request $request;
    private string $reason;

    public function __construct(Request $request, string $reason)
    {
        $this->request = $request;
        $this->reason = $reason;
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
