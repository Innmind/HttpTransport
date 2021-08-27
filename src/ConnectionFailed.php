<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Message\Request;

final class ConnectionFailed
{
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function request(): Request
    {
        return $this->request;
    }
}
