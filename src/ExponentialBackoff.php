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
    private Transport $fulfill;
    private Halt $halt;
    /** @var Sequence<Period> */
    private Sequence $retries;

    private function __construct(
        Transport $fulfill,
        Halt $halt,
        Period $retry,
        Period ...$retries,
    ) {
        $this->fulfill = $fulfill;
        $this->halt = $halt;
        $this->retries = Sequence::of($retry, ...$retries);
    }

    #[\Override]
    public function __invoke(Request $request): Either
    {
        /** @psalm-suppress MixedArgumentTypeCoercion Can't type the templates for Either */
        return $this->retries->reduce(
            ($this->fulfill)($request),
            fn(Either $result, $period) => $this->maybeRetry($result, $request, $period),
        );
    }

    public static function of(Transport $fulfill, Halt $halt): self
    {
        /** @psalm-suppress ArgumentTypeCoercion Periods are necessarily positive */
        return new self(
            $fulfill,
            $halt,
            Period::millisecond((int) (\exp(0) * 100.0)),
            Period::millisecond((int) (\exp(1) * 100.0)),
            Period::millisecond((int) (\exp(2) * 100.0)),
            Period::millisecond((int) (\exp(3) * 100.0)),
            Period::millisecond((int) (\exp(4) * 100.0)),
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
            $error instanceof ClientError &&
            $error->response()->statusCode() === StatusCode::tooManyRequests => $this->retry($request, $period),
            $error instanceof ServerError => $this->retry($request, $period),
            $error instanceof ConnectionFailed => $this->retry($request, $period),
            default => $this->return($error),
        });
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
     * @return Either<Errors, Success>
     */
    private function retry(Request $request, Period $period): Either
    {
        return ($this->halt)($period)
            ->either()
            ->leftMap(static fn($error) => new Failure($request, $error::class))
            ->flatMap(fn() => ($this->fulfill)($request));
    }
}
