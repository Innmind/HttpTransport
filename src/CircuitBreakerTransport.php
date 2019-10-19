<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Message\Request,
    Message\Response,
    Message\StatusCode\StatusCode,
    ProtocolVersion\ProtocolVersion,
    Headers\Headers,
    Header\Header,
    Header\Value\Value,
};
use Innmind\Url\UrlInterface;
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    PeriodInterface,
    PointInTimeInterface,
};
use Innmind\Immutable\Map;

final class CircuitBreakerTransport implements Transport
{
    private $fulfill;
    private $clock;
    private $delayBeforeRetry;
    private $closedCircuits;
    private $defaultResponse;

    public function __construct(
        Transport $fulfill,
        TimeContinuumInterface $clock,
        PeriodInterface $delayBeforeRetry
    ) {
        $this->fulfill = $fulfill;
        $this->clock = $clock;
        $this->delayBeforeRetry = $delayBeforeRetry;
        $this->closedCircuits = Map::of('string', PointInTimeInterface::class);
    }

    public function __invoke(Request $request): Response
    {
        if ($this->closed($request->url())) {
            return $this->defaultResponse();
        }

        $response = ($this->fulfill)($request);

        if (StatusCode::isServerError($response->statusCode())) {
            $this->close($request->url());
        }

        return $response;
    }

    private function close(UrlInterface $url): void
    {
        $this->closedCircuits = $this->closedCircuits->put(
            $this->hash($url),
            $this->clock->now()
        );
    }

    private function closed(UrlInterface $url): bool
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
                    new Value('true')
                )
            )
        );
    }

    private function hash(UrlInterface $url): string
    {
        return (string) $url->authority()->host();
    }
}
