<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Message\{
    Request,
    Response,
};
use Innmind\Immutable\Either;

/**
 * @psalm-type Errors = ConnectionFailed|Redirection|ClientError|ServerError
 */
interface Transport
{
    /**
     * @return Either<Errors, Success>
     */
    public function __invoke(Request $request): Either;
}
