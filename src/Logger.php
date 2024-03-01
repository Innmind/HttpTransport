<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Request,
    Response,
    Headers,
    Header,
};
use Innmind\Immutable\{
    Either,
    Str,
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * @psalm-import-type Errors from Transport
 */
final class Logger implements Transport
{
    private Transport $fulfill;
    private LoggerInterface $logger;

    private function __construct(Transport $fulfill, LoggerInterface $logger)
    {
        $this->fulfill = $fulfill;
        $this->logger = $logger;
    }

    public function __invoke(Request $request): Either
    {
        $reference = $this->logRequest($request);

        return ($this->fulfill)($request)
            ->map(fn($success) => $this->logWrapper($success, $reference))
            ->leftMap(fn($error) => $this->logError($error, $reference));
    }

    public static function psr(Transport $fulfill, LoggerInterface $logger): self
    {
        return new self($fulfill, $logger);
    }

    private function logRequest(Request $request): string
    {
        $this->logger->debug(
            'Http request about to be sent',
            [
                'method' => $request->method()->toString(),
                'url' => $request->url()->toString(),
                'headers' => $this->normalize($request->headers()),
                'reference' => $reference = Uuid::uuid4()->toString(),
            ],
        );

        return $reference;
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
            $error instanceof Information => fn() => $this->logWrapper($error, $reference),
            $error instanceof Redirection => fn() => $this->logWrapper($error, $reference),
            $error instanceof ClientError => fn() => $this->logWrapper($error, $reference),
            $error instanceof ServerError => fn() => $this->logWrapper($error, $reference),
            default => static fn() => $error, // failed connections are not logged for now
        };

        return $log();
    }

    /**
     * @template W of ServerError|ClientError|Redirection|Success|Information
     *
     * @param W $wrapper
     *
     * @return W
     */
    private function logWrapper($wrapper, string $reference)
    {
        $this->logResponse($wrapper->response(), $reference);

        return $wrapper;
    }

    private function logResponse(Response $response, string $reference): Response
    {
        $this->logger->debug(
            'Http request sent',
            [
                'statusCode' => $response->statusCode()->toInt(),
                'headers' => $this->normalize($response->headers()),
                'reference' => $reference,
            ],
        );

        return $response;
    }

    private function normalize(Headers $headers): array
    {
        /** @var array<string, string> */
        $raw = [];

        return $headers->reduce(
            $raw,
            static function(array $headers, Header $header): array {
                $values = $header->values()->map(
                    static fn($value) => $value->toString(),
                );
                $headers[$header->name()] = Str::of(', ')->join($values)->toString();

                return $headers;
            },
        );
    }
}
