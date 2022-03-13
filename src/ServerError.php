<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Message\{
    Request,
    Response,
};

final class ServerError
{
    private Request $request;
    private Response $response;

    public function __construct(Request $request, Response $response)
    {
        if (!$response->statusCode()->serverError()) {
            throw new \LogicException($response->statusCode()->toString());
        }

        $this->request = $request;
        $this->response = $response;
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function response(): Response
    {
        return $this->response;
    }
}
