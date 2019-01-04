<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Message\{
    Request,
    Response,
};

interface Transport
{
    public function __invoke(Request $request): Response;
}
