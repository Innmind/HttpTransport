<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Request;
use Innmind\Http\Response\StatusCode;
use Innmind\TimeWarp\Halt;
use Innmind\TimeContinuum\Period;
use Innmind\Immutable\{
    Sequence,
    Either,
};

/**
 * @psalm-import-type Errors from Transport
 */
final class ExponentialBackoff implements Transport
{
    /**
     * @param Sequence<Period> $retries
     */
    private function __construct(
        private Transport $fulfill,
        private Halt $halt,
        private Sequence $retries,
    ) {
    }

    #[\Override]
    public function __invoke(Request $request): Either
    {
        return $this->fulfill($request, $this->retries);
    }

    public static function of(Transport $fulfill, Halt $halt): self
    {
        /** @psalm-suppress ArgumentTypeCoercion Periods are necessarily positive */
        return new self(
            $fulfill,
            $halt,
            Sequence::of(
                Period::millisecond((int) (\exp(0) * 100.0)),
                Period::millisecond((int) (\exp(1) * 100.0)),
                Period::millisecond((int) (\exp(2) * 100.0)),
                Period::millisecond((int) (\exp(3) * 100.0)),
                Period::millisecond((int) (\exp(4) * 100.0)),
            ),
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
