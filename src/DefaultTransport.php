<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\Exception\ConnectionFailed;
use Innmind\Http\{
    Message\Request,
    Message\Response,
    Translator\Response\FromPsr7,
    Header,
    Header\Value,
};
use GuzzleHttp\{
    ClientInterface,
    Exception\ConnectException as GuzzleConnectException,
    Exception\BadResponseException,
};

final class DefaultTransport implements Transport
{
    private ClientInterface $client;
    private FromPsr7 $translate;

    public function __construct(
        ClientInterface $client,
        FromPsr7 $translate
    ) {
        $this->client = $client;
        $this->translate = $translate;
    }

    public function __invoke(Request $request): Response
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
            throw new ConnectionFailed($request, $e);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
        }

        return ($this->translate)($response);
    }
}
