<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Exception;

use Innmind\Http\Message\{
    RequestInterface,
    ResponseInterface
};

final class ClientErrorException extends RuntimeException
{
    private $request;
    private $response;

    public function __construct(
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $this->request = $request;
        $this->response = $response;
    }

    public function request(): RequestInterface
    {
        return $this->request;
    }

    public function response(): ResponseInterface
    {
        return $this->response;
    }
}
