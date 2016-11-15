<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\Exception\ServerErrorException;
use Innmind\Http\Message\{
    RequestInterface,
    ResponseInterface
};

final class ThrowOnServerErrorTransport implements TransportInterface
{
    private $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    public function fulfill(RequestInterface $request): ResponseInterface
    {
        $response = $this->transport->fulfill($request);
        $level = (int) ($response->statusCode()->value() / 100);

        if ($level === 5) {
            throw new ServerErrorException($request, $response);
        }

        return $response;
    }
}