<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Request,
    Response,
};

final class ClientError
{
    private Request $request;
    private Response $response;

    public function __construct(Request $request, Response $response)
    {
        if (!$response->statusCode()->clientError()) {
            throw new \LogicException($response->statusCode()->toString());
        }

        $this->request = $request;
        $this->response = $response;
    }

    #[\NoDiscard]
    public function request(): Request
    {
        return $this->request;
    }

    #[\NoDiscard]
    public function response(): Response
    {
        return $this->response;
    }
}
