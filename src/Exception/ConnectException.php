<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Exception;

use Innmind\Http\Message\RequestInterface;

final class ConnectException extends RuntimeException
{
    private $request;

    public function __construct(
        RequestInterface $request,
        \Exception $e
    ) {
        $this->request = $request;
        parent::__construct(
            $e->getMessage(),
            $e->getCode(),
            $e
        );
    }

    public function request(): RequestInterface
    {
        return $this->request;
    }
}
