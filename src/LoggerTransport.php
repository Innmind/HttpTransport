<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Message\Request,
    Message\Response,
    Headers,
    Header,
    Header\Value,
};
use function Innmind\Immutable\join;
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
                'method' => $request->method()->toString(),
                'url' => $request->url()->toString(),
                'headers' => $this->normalize($request->headers()),
                'body' => $request->body()->toString(),
                'reference' => $reference = Uuid::uuid4()->toString(),
            ],
        );

        $response = ($this->fulfill)($request);
        $body = $response->body();

        $this->logger->debug(
            'Http request sent',
            [
                'statusCode' => $response->statusCode()->value(),
                'headers' => $this->normalize($response->headers()),
                'body' => $body->toString(),
                'reference' => $reference,
            ],
        );
        $body->rewind();

        return $response;
    }

    private function normalize(Headers $headers): array
    {
        return $headers->reduce(
            [],
            static function(array $headers, Header $header): array {
                $values = $header->values()->mapTo(
                    'string',
                    static fn(Value $value): string => $value->toString(),
                );
                $headers[$header->name()] = join(', ', $values)->toString();

                return $headers;
            }
        );
    }
}
