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
use Innmind\Immutable\Map;

final class CircuitBreakerTransport implements Transport
{
    private Transport $fulfill;
    private Clock $clock;
    private Period $delayBeforeRetry;
    /** @var Map<string , PointInTime> */
    private Map $openedCircuits;
    private ?Response $defaultResponse = null;

    public function __construct(
        Transport $fulfill,
        Clock $clock,
        Period $delayBeforeRetry
    ) {
        $this->fulfill = $fulfill;
        $this->clock = $clock;
        $this->delayBeforeRetry = $delayBeforeRetry;
        /** @var Map<string , PointInTime> */
        $this->openedCircuits = Map::of();
    }

    public function __invoke(Request $request): Response
    {
        if ($this->opened($request->url())) {
            return $this->defaultResponse();
        }

        $response = ($this->fulfill)($request);

        if ($response->statusCode()->serverError()) {
            $this->open($request->url());
        }

        return $response;
    }

    private function open(Url $url): void
    {
        $this->openedCircuits = ($this->openedCircuits)(
            $this->hash($url),
            $this->clock->now(),
        );
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

    private function defaultResponse(): Response
    {
        return $this->defaultResponse ?? $this->defaultResponse = new Response\Response(
            $code = StatusCode::of('SERVICE_UNAVAILABLE'),
            $code->associatedReasonPhrase(),
            new ProtocolVersion(2, 0),
            Headers::of(
                new Header(
                    'X-Circuit-Opened',
                    new Value('true'),
                ),
            ),
        );
    }

    private function hash(Url $url): string
    {
        return $url->authority()->host()->toString();
    }
}
