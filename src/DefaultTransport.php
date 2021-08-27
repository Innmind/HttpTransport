<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Message\Request,
    Message\Response,
    Translator\Response\FromPsr7,
    Header,
    Header\Value,
};
use Innmind\Immutable\Either;
use GuzzleHttp\{
    ClientInterface,
    Exception\ConnectException as GuzzleConnectException,
    Exception\BadResponseException,
};

/**
 * @psalm-import-type Errors from Transport
 */
final class DefaultTransport implements Transport
{
    private ClientInterface $client;
    private FromPsr7 $translate;

    public function __construct(ClientInterface $client, FromPsr7 $translate)
    {
        $this->client = $client;
        $this->translate = $translate;
    }

    public function __invoke(Request $request): Either
    {
        $options = [];

        $headers = $request->headers()->reduce(
            [],
            static function(array $headers, Header $header): array {
                $headers[$header->name()] = $header
                    ->values()
                    ->map(static fn($value) => $value->toString())
                    ->toList();

                return $headers;
            }
        );

        if (\count($headers) > 0) {
            $options['headers'] = $headers;
        }

        $body = $request->body()->toString();

        if ($body !== '') {
            $options['body'] = $body;
        }

        try {
            $response = $this->client->request(
                $request->method()->toString(),
                $request->url()->toString(),
                $options,
            );
        } catch (GuzzleConnectException $e) {
            /** @var Either<Errors, Success> */
            return Either::left(new ConnectionFailed($request, $e->getMessage()));
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
        }

        $response = ($this->translate)($response);

        /** @var Either<Errors, Success> */
        return match (true) {
            $response->statusCode()->serverError() => Either::left(new ServerError($request, $response)),
            $response->statusCode()->clientError() => Either::left(new ClientError($request, $response)),
            $response->statusCode()->redirection() => Either::left(new Redirection($request, $response)),
            $response->statusCode()->successful() => Either::right(new Success($request, $response)),
        };
    }
}
