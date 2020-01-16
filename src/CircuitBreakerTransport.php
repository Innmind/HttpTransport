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
    private Map $closedCircuits;
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
        $this->closedCircuits = Map::of('string', PointInTime::class);
    }

    public function __invoke(Request $request): Response
    {
        if ($this->closed($request->url())) {
            return $this->defaultResponse();
        }

        $response = ($this->fulfill)($request);

        if ($response->statusCode()->isServerError()) {
            $this->close($request->url());
        }

        return $response;
    }

    private function close(Url $url): void
    {
        $this->closedCircuits = ($this->closedCircuits)(
            $this->hash($url),
            $this->clock->now(),
        );
    }

    private function closed(Url $url): bool
    {
        if (!$this->closedCircuits->contains($this->hash($url))) {
            return false;
        }

        return $this
            ->closedCircuits
            ->get($this->hash($url))
            ->goForward($this->delayBeforeRetry)
            ->aheadOf($this->clock->now());
    }

    private function defaultResponse(): Response
    {
        return $this->defaultResponse ?? $this->defaultResponse = new Response\Response(
            $code = StatusCode::of('SERVICE_UNAVAILABLE'),
            $code->associatedReasonPhrase(),
            new ProtocolVersion(2, 0),
            Headers::of(
                new Header(
                    'X-Circuit-Closed',
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
