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
use Innmind\Immutable\Sequence;

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

    public function __invoke(Request $request): Response
    {
        $retries = $this->retries;

        while (true) {
            $response = ($this->fulfill)($request);

            if (!$this->shouldRetry($response, $retries)) {
                break;
            }

            $_ = $retries->first()->match(
                fn($period) => ($this->halt)($period),
                static fn() => null,
            );
            $retries = $retries->drop(1);
        }

        return $response;
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
