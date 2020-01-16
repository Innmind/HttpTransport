<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\Exception\{
    ClientError,
    ServerError,
};
use Innmind\Http\Message\{
    Request,
    Response,
    StatusCode\StatusCode,
};

final class ThrowOnErrorTransport implements Transport
{
    private Transport $fulfill;

    public function __construct(Transport $fulfill)
    {
        $this->fulfill = $fulfill;
    }

    /**
     * @throws ClientError When the status code is 4**
     * @throws ServerError When the status code is 5**
     */
    public function __invoke(Request $request): Response
    {
        $response = ($this->fulfill)($request);

        if (StatusCode::isClientError($response->statusCode())) {
            throw new ClientError($request, $response);
        }

        if (StatusCode::isServerError($response->statusCode())) {
            throw new ServerError($request, $response);
        }

        return $response;
    }
}
