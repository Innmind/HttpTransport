<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Message\Request,
    Message\Response,
    Headers
};
use Psr\Log\{
    LoggerInterface,
    LogLevel
};
use Ramsey\Uuid\Uuid;

final class LoggerTransport implements Transport
{
    private $transport;
    private $logger;
    private $level;

    public function __construct(
        Transport $transport,
        LoggerInterface $logger,
        string $level = LogLevel::DEBUG
    ) {
        $this->transport = $transport;
        $this->logger = $logger;
        $this->level = $level;
    }

    public function fulfill(Request $request): Response
    {
        $this->logger->log(
            $this->level,
            'Http request about to be sent',
            [
                'method' => (string) $request->method(),
                'url' => (string) $request->url(),
                'headers' => $this->normalize($request->headers()),
                'body' => (string) $request->body(),
                'reference' => $reference = (string) Uuid::uuid4(),
            ]
        );

        $response = $this->transport->fulfill($request);

        $this->logger->log(
            $this->level,
            'Http request sent',
            [
                'statusCode' => $response->statusCode()->value(),
                'headers' => $this->normalize($response->headers()),
                'body' => (string) $response->body(),
                'reference' => $reference,
            ]
        );

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
