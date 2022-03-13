<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Message\Request,
    Message\Response,
    Message\StatusCode,
    ProtocolVersion,
    Headers,
    Header\Header,
    Header\Value\Value,
};
use Innmind\Url\Url;
use Innmind\TimeContinuum\{
    Clock,
    Period,
    PointInTime,
};
use Innmind\Immutable\{
    Map,
    Either,
};

/**
 * @psalm-import-type Errors from Transport
 */
final class CircuitBreaker implements Transport
{
    private Transport $fulfill;
    private Clock $clock;
    private Period $delayBeforeRetry;
    /** @var Map<string , PointInTime> */
    private Map $openedCircuits;

    private function __construct(
        Transport $fulfill,
        Clock $clock,
        Period $delayBeforeRetry,
    ) {
        $this->fulfill = $fulfill;
        $this->clock = $clock;
        $this->delayBeforeRetry = $delayBeforeRetry;
        /** @var Map<string , PointInTime> */
        $this->openedCircuits = Map::of();
    }

    public function __invoke(Request $request): Either
    {
        if ($this->opened($request->url())) {
            return $this->error($request);
        }

        return ($this->fulfill)($request)->leftMap(fn($error) => match (true) {
            $error instanceof ServerError => $this->open($request, $error),
            $error instanceof ConnectionFailed => $this->open($request, $error),
            default => $error,
        });
    }

    public static function of(
        Transport $fulfill,
        Clock $clock,
        Period $delayBeforeRetry,
    ): self {
        return new self($fulfill, $clock, $delayBeforeRetry);
    }

    private function open(
        Request $request,
        ServerError|ConnectionFailed $error,
    ): ServerError|ConnectionFailed {
        $this->openedCircuits = ($this->openedCircuits)(
            $this->hash($request->url()),
            $this->clock->now(),
        );

        return $error;
    }

    private function opened(Url $url): bool
    {
        return $this
            ->openedCircuits
            ->get($this->hash($url))
            ->map(fn($lastCall) => $lastCall->goForward($this->delayBeforeRetry))
            ->map(fn($recloseAt) => $recloseAt->aheadOf($this->clock->now()))
            ->match(
                static fn($opened) => $opened,
                static fn() => false,
            );
    }

    /**
     * @return Either<Errors, Success>
     */
    private function error(Request $request): Either
    {
        /** @var Either<Errors, Success> */
        return Either::left(new ServerError($request, new Response\Response(
            $code = StatusCode::serviceUnavailable,
            ProtocolVersion::v20,
            Headers::of(
                new Header(
                    'X-Circuit-Opened',
                    new Value('true'),
                ),
            ),
        )));
    }

    private function hash(Url $url): string
    {
        return $url->authority()->host()->toString();
    }
}
