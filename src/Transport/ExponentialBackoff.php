<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Transport;

use Innmind\HttpTransport\{
    Success,
    Information,
    Redirection,
    ClientError,
    ServerError,
    ConnectionFailed,
    MalformedResponse,
    Failure,
};
use Innmind\Http\{
    Request,
    Response\StatusCode,
};
use Innmind\Time\{
    Halt,
    Period,
};
use Innmind\Immutable\{
    Sequence,
    Either,
};

/**
 * @internal
 * @psalm-import-type Errors from Implementation
 */
final class ExponentialBackoff implements Implementation
{
    /**
     * @psalm-mutation-free
     *
     * @param Sequence<Period> $retries
     */
    private function __construct(
        private Implementation $fulfill,
        private Halt $halt,
        private Sequence $retries,
    ) {
    }

    #[\Override]
    public function __invoke(Request $request): Either
    {
        return $this->fulfill($request, $this->retries);
    }

    /**
     * @psalm-pure
     *
     * @param ?Sequence<Period> $retries
     */
    public static function of(
        Implementation $fulfill,
        Halt $halt,
        ?Sequence $retries = null,
    ): self {
        /** @psalm-suppress ArgumentTypeCoercion Periods are necessarily positive */
        return new self(
            $fulfill,
            $halt,
            $retries ?? Sequence::of(
                Period::millisecond((int) (\exp(0) * 100.0)),
                Period::millisecond((int) (\exp(1) * 100.0)),
                Period::millisecond((int) (\exp(2) * 100.0)),
                Period::millisecond((int) (\exp(3) * 100.0)),
                Period::millisecond((int) (\exp(4) * 100.0)),
            ),
        );
    }

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    public function map(callable $map): self
    {
        return self::of(
            $this->fulfill->map($map),
            $this->halt,
        );
    }

    /**
     * @param Sequence<Period> $retries
     *
     * @return Either<Errors, Success>
     */
    private function fulfill(Request $request, Sequence $retries): Either
    {
        return ($this->fulfill)($request)->otherwise(
            fn($error) => $this->maybeRetry($error, $request, $retries),
        );
    }

    /**
     * @param Sequence<Period> $retries
     *
     * @return Either<Errors, Success> $result
     */
    private function maybeRetry(
        Failure|ConnectionFailed|MalformedResponse|Information|Redirection|ClientError|ServerError $error,
        Request $request,
        Sequence $retries,
    ): Either {
        return match (true) {
            $error instanceof ClientError &&
            $error->response()->statusCode() === StatusCode::tooManyRequests => $this->retry($error, $request, $retries),
            $error instanceof ServerError => $this->retry($error, $request, $retries),
            $error instanceof ConnectionFailed => $this->retry($error, $request, $retries),
            default => $this->return($error),
        };
    }

    /**
     * @return Either<Errors, Success>
     */
    private function return(Redirection|ClientError|Information|MalformedResponse|Failure $error): Either
    {
        /** @var Either<Errors, Success> */
        return Either::left($error);
    }

    /**
     * @param Sequence<Period> $retries
     *
     * @return Either<Errors, Success>
     */
    private function retry(
        Failure|ConnectionFailed|MalformedResponse|Information|Redirection|ClientError|ServerError $error,
        Request $request,
        Sequence $retries,
    ): Either {
        return $retries
            ->first()
            ->either()
            ->eitherWay(
                fn($period) => ($this->halt)($period)
                    ->either()
                    ->leftMap(static fn($error) => new Failure($request, $error::class))
                    ->flatMap(fn() => $this->fulfill($request, $retries->drop(1))),
                static fn() => Either::left($error),
            );
    }
}
