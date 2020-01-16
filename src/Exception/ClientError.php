<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Exception;

use Innmind\Http\Message\{
    Request,
    Response,
};

final class ClientError extends RuntimeException
{
    private Request $request;
    private Response $response;

    public function __construct(
        Request $request,
        Response $response
    ) {
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
