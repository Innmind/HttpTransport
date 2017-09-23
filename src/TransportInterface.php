<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Message\{
    Request,
    Response
};

interface TransportInterface
{
    public function fulfill(Request $request): Response;
}
