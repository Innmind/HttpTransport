<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Transport;

use Innmind\HttpTransport\{
    Config,
    Success,
    Information,
    Redirection,
    ClientError,
    ServerError,
    Failure,
    ConnectionFailed,
    MalformedResponse,
};
use Innmind\Http\Request;
use Innmind\Immutable\Either;

/**
 * @internal
 * @psalm-type Errors = Failure|ConnectionFailed|MalformedResponse|Information|Redirection|ClientError|ServerError
 */
interface Implementation
{
    /**
     * @return Either<Errors, Success>
     */
    #[\NoDiscard]
    public function __invoke(Request $request): Either;

    /**
     * @psalm-mutation-free
     *
     * @param callable(Config): Config $map
     */
    #[\NoDiscard]
    public function map(callable $map): self;
}
