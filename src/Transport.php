<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Request;
use Innmind\Immutable\Either;

/**
 * @psalm-type Errors = Failure|ConnectionFailed|MalformedResponse|Information|Redirection|ClientError|ServerError
 */
interface Transport
{
    /**
     * @return Either<Errors, Success>
     */
    public function __invoke(Request $request): Either;
}
