<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Message\{
    RequestInterface,
    ResponseInterface
};

interface TransportInterface
{
    public function fulfill(RequestInterface $request): ResponseInterface;
}
