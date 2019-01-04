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
    private $fulfill;

    public function __construct(Transport $fulfill)
    {
        $this->fulfill = $fulfill;
    }

    public function __invoke(Request $request): Response
    {
        $response = ($this->fulfill)($request);

        if ($response->statusCode()->value() % 400 < 100) {
            throw new ClientError($request, $response);
        }

        return $response;
    }
}
