<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Exception;

use Innmind\Http\Message\Request;

final class ConnectionFailed extends RuntimeException
{
    private Request $request;

    public function __construct(
        Request $request,
        \Exception $e
    ) {
        $this->request = $request;
        parent::__construct(
            $e->getMessage(),
            $e->getCode(),
            $e,
        );
    }

    public function request(): Request
    {
        return $this->request;
    }
}
