<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Request;
use Innmind\Immutable\Either;

/**
 * @internal
 * @psalm-type Errors = Failure|ConnectionFailed|MalformedResponse|Information|Redirection|ClientError|ServerError
 */
interface Implementation extends Transport
{
    /**
     * @return Either<Errors, Success>
     */
    #[\NoDiscard]
    #[\Override]
    public function __invoke(Request $request): Either;
}
