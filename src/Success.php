<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Request,
    Response,
};

final class Success
{
    private Request $request;
    private Response $response;

    public function __construct(Request $request, Response $response)
    {
        if (!$response->statusCode()->successful()) {
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
