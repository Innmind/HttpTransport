<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\Exception\ServerError;
use Innmind\Http\Message\{
    Request,
    Response
};

final class ThrowOnServerErrorTransport implements Transport
{
    private $transport;

    public function __construct(Transport $transport)
    {
        $this->transport = $transport;
    }

    public function fulfill(Request $request): Response
    {
        $response = $this->transport->fulfill($request);

        if ($response->statusCode()->value() % 500 < 100) {
            throw new ServerError($request, $response);
        }

        return $response;
    }
}
