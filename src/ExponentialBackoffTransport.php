<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Message\{
    Request,
    Response,
    StatusCode,
};
use Innmind\TimeContinuum\{
    Clock,
    Period,
    Earth\Period\Millisecond,
};
use Innmind\TimeWarp\Halt;
use Innmind\Immutable\Sequence;

final class ExponentialBackoffTransport implements Transport
{
    private Transport $fulfill;
    private Halt $halt;
    private Clock $clock;
    /** @var Sequence<Period> */
    private Sequence $retries;

    public function __construct(
        Transport $fulfill,
        Halt $halt,
        Clock $clock,
        Period $retry,
        Period ...$retries
    ) {
        $this->fulfill = $fulfill;
        $this->halt = $halt;
        $this->clock = $clock;
        /** @var Sequence<Period> */
        $this->retries = Sequence::of(Period::class, $retry, ...$retries);
    }

    public static function of(
        Transport $fulfill,
        Halt $halt,
        Clock $clock
    ): self {
        return new self(
            $fulfill,
            $halt,
            $clock,
            new Millisecond((int) (\exp(0) * 100)),
            new Millisecond((int) (\exp(1) * 100)),
            new Millisecond((int) (\exp(2) * 100)),
            new Millisecond((int) (\exp(3) * 100)),
            new Millisecond((int) (\exp(4) * 100)),
        );
    }

    public function __invoke(Request $request): Response
    {
        $retries = $this->retries;

        while (true) {
            $response = ($this->fulfill)($request);

            if (!$this->shouldRetry($response, $retries)) {
                break;
            }

            ($this->halt)($this->clock, $retries->first());
            $retries = $retries->drop(1);
        }

        return $response;
    }

    private function shouldRetry(Response $response, Sequence $retries): bool
    {
        if (!$response->statusCode()->isServerError()) {
            return false;
        }

        if ($retries->empty()) {
            return false;
        }

        return true;
    }
}
