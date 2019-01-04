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
    private $fulfill;

    public function __construct(Transport $fulfill)
    {
        $this->fulfill = $fulfill;
    }

    public function __invoke(Request $request): Response
    {
        $response = ($this->fulfill)($request);

        if ($response->statusCode()->value() % 500 < 100) {
            throw new ServerError($request, $response);
        }

        return $response;
    }
}
