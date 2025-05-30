<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Request,
    Response,
    Response\StatusCode,
    ProtocolVersion,
    Headers,
    Header,
    Header\Value,
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
    /**
     * @param Map<string , PointInTime> $openedCircuits
     */
    private function __construct(
        private Transport $fulfill,
        private Clock $clock,
        private Period $delayBeforeRetry,
        private Map $openedCircuits,
    ) {
    }

    #[\Override]
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
        return new self(
            $fulfill,
            $clock,
            $delayBeforeRetry,
            Map::of(),
        );
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
        return Either::left(new ServerError($request, Response::of(
            $code = StatusCode::serviceUnavailable,
            ProtocolVersion::v20,
            Headers::of(
                Header::of(
                    'X-Circuit-Opened',
                    Value::of('true'),
                ),
            ),
        )));
    }

    private function hash(Url $url): string
    {
        return $url->authority()->host()->toString();
    }
}
