<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Message\Request,
    Message\Response,
    Headers,
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

final class LoggerTransport implements Transport
{
    private Transport $fulfill;
    private LoggerInterface $logger;

    public function __construct(
        Transport $fulfill,
        LoggerInterface $logger
    ) {
        $this->fulfill = $fulfill;
        $this->logger = $logger;
    }

    public function __invoke(Request $request): Response
    {
        $this->logger->debug(
            'Http request about to be sent',
            [
                'method' => (string) $request->method(),
                'url' => (string) $request->url(),
                'headers' => $this->normalize($request->headers()),
                'body' => (string) $request->body(),
                'reference' => $reference = (string) Uuid::uuid4(),
            ],
        );

        $response = ($this->fulfill)($request);
        $body = $response->body();

        $this->logger->debug(
            'Http request sent',
            [
                'statusCode' => $response->statusCode()->value(),
                'headers' => $this->normalize($response->headers()),
                'body' => (string) $body,
                'reference' => $reference,
            ],
        );
        $body->rewind();

        return $response;
    }

    private function normalize(Headers $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $header) {
            $normalized[$name] = (string) $header->values()->join(', ');
        }

        return $normalized;
    }
}
