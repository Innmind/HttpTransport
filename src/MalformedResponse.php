<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\MalformedResponse\Raw;
use Innmind\Http\Request;

final class MalformedResponse
{
    private Request $request;
    private Raw $raw;

    public function __construct(Request $request, ?Raw $raw = null)
    {
        $this->request = $request;
        $this->raw = $raw ?? Raw::none();
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function raw(): Raw
    {
        return $this->raw;
    }
}
