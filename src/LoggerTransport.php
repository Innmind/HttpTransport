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
use Innmind\Immutable\Either;
use function Innmind\Immutable\join;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * @psalm-import-type Errors from Transport
 */
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

    public function __invoke(Request $request): Either
    {
        $reference = $this->logRequest($request);

        return ($this->fulfill)($request)
            ->map(fn($success) => $this->logSuccess($success, $reference))
            ->leftMap(fn($error) => $this->logError($error, $reference));
    }

    private function logRequest(Request $request): string
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

        return $reference;
    }

    private function logSuccess(Success $success, string $reference): Success
    {
        $this->logWrapper($success, $reference);

        return $success;
    }

    /**
     * @param Errors $error
     *
     * @return Errors
     */
    private function logError($error, string $reference)
    {
        /** @var callable(): Errors */
        $log = match (true) {
            $error instanceof Redirection => fn() => $this->logWrapper($error, $reference),
            $error instanceof ClientError => fn() => $this->logWrapper($error, $reference),
            $error instanceof ServerError => fn() => $this->logWrapper($error, $reference),
            default => static fn() => $error, // failed connections are not logged for now
        };

        return $log();
    }

    private function logWrapper(
        Success|Redirection|ClientError|ServerError $wrapper,
        string $reference,
    ): Success|Redirection|ClientError|ServerError {
        $this->logResponse($wrapper->response(), $reference);

        return $wrapper;
    }

    private function logResponse(Response $response, string $reference): Response
    {
        $this->logger->debug(
            'Http request sent',
            [
                'statusCode' => $response->statusCode()->value(),
                'headers' => $this->normalize($response->headers()),
                'body' => $response->body()->toString(),
                'reference' => $reference,
            ],
        );

        return $response;
    }

    private function normalize(Headers $headers): array
    {
        return $headers->reduce(
            [],
            static function(array $headers, Header $header): array {
                $values = $header->values()->map(
                    static fn($value) => $value->toString(),
                );
                $headers[$header->name()] = join(', ', $values)->toString();

                return $headers;
            }
        );
    }
}
