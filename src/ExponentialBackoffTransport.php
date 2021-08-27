<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Message\{
    Request,
    Response,
    StatusCode,
};
use Innmind\TimeContinuum\{
    Period,
    Earth\Period\Millisecond,
};
use Innmind\TimeWarp\Halt;
use Innmind\Immutable\{
    Sequence,
    Either,
};

/**
 * @psalm-import-type Errors from Transport
 */
final class ExponentialBackoffTransport implements Transport
{
    private Transport $fulfill;
    private Halt $halt;
    /** @var Sequence<Period> */
    private Sequence $retries;

    public function __construct(
        Transport $fulfill,
        Halt $halt,
        Period $retry,
        Period ...$retries
    ) {
        $this->fulfill = $fulfill;
        $this->halt = $halt;
        $this->retries = Sequence::of($retry, ...$retries);
    }

    public function __invoke(Request $request): Either
    {
        /** @psalm-suppress MixedArgumentTypeCoercion Can't type the templates for Either */
        return $this->retries->reduce(
            ($this->fulfill)($request),
            fn(Either $result, $period) => $this->maybeRetry($result, $request, $period),
        );
    }

    public static function of(
        Transport $fulfill,
        Halt $halt,
    ): self {
        return new self(
            $fulfill,
            $halt,
            new Millisecond((int) (\exp(0) * 100)),
            new Millisecond((int) (\exp(1) * 100)),
            new Millisecond((int) (\exp(2) * 100)),
            new Millisecond((int) (\exp(3) * 100)),
            new Millisecond((int) (\exp(4) * 100)),
        );
    }

    /**
     * @param Either<Errors, Success> $result
     *
     * @return Either<Errors, Success> $result
     */
    private function maybeRetry(
        Either $result,
        Request $request,
        Period $period,
    ): Either {
        return $result->otherwise(fn($error) => match (true) {
            $error instanceof ServerError => $this->retry($request, $period),
            $error instanceof ConnectionFailed => $this->retry($request, $period),
            default => $this->return($error),
        });
    }

    /**
     * @return Either<Errors, Success>
     */
    private function return(Redirection|ClientError $error): Either
    {
        /** @var Either<Errors, Success> */
        return Either::left($error);
    }

    /**
     * @return Either<Errors, Success>
     */
    private function retry(Request $request, Period $period): Either
    {
        ($this->halt)($period);

        return ($this->fulfill)($request);
    }

    private function shouldRetry(Response $response, Sequence $retries): bool
    {
        if (!$response->statusCode()->serverError()) {
            return false;
        }

        if ($retries->empty()) {
            return false;
        }

        return true;
    }
}
