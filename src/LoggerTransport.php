<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\Message\{
    RequestInterface,
    ResponseInterface
};
use Psr\Log\{
    LoggerInterface,
    LogLevel
};
use Ramsey\Uuid\Uuid;

final class LoggerTransport implements TransportInterface
{
    private $transport;
    private $logger;
    private $level;

    public function __construct(
        TransportInterface $transport,
        LoggerInterface $logger,
        string $level = LogLevel::DEBUG
    ) {
        $this->transport = $transport;
        $this->logger = $logger;
        $this->level = $level;
    }

    public function fulfill(RequestInterface $request): ResponseInterface
    {
        $headers = [];

        foreach ($request->headers() as $name => $header) {
            $headers[$name] = (string) $header->values()->join(', ');
        }

        $this->logger->log(
            $this->level,
            'Http request about to be sent',
            [
                'url' => (string) $request->url(),
                'headers' => $headers,
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
                'reference' => $reference,
            ]
        );

        return $response;
    }
}
