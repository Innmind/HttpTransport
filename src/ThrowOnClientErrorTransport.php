<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\Exception\ClientError;
use Innmind\Http\Message\{
    Request,
    Response
};

final class ThrowOnClientErrorTransport implements Transport
{
    private $transport;

    public function __construct(Transport $transport)
    {
        $this->transport = $transport;
    }

    public function fulfill(Request $request): Response
    {
        $response = $this->transport->fulfill($request);

        if ($response->statusCode()->value() % 400 < 100) {
            throw new ClientError($request, $response);
        }

        return $response;
    }
}
