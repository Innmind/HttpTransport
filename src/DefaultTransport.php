<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\Exception\ConnectionFailed;
use Innmind\Http\{
    Message\Request,
    Message\Response,
    Translator\Response\Psr7Translator,
    Header,
    Header\Value,
};
use function Innmind\Immutable\unwrap;
use GuzzleHttp\{
    ClientInterface,
    Exception\ConnectException as GuzzleConnectException,
    Exception\BadResponseException,
};

final class DefaultTransport implements Transport
{
    private ClientInterface $client;
    private Psr7Translator $translate;

    public function __construct(
        ClientInterface $client,
        Psr7Translator $translate
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
                $values = $header->values()->mapTo(
                    'string',
                    static fn(Value $value): string => $value->toString(),
                );
                $headers[$header->name()] = unwrap($values);

                return $headers;
            }
        );

        if (\count($headers) > 0) {
            $options['headers'] = $headers;
        }

        if ($request->body()->size()->toInt() > 0) {
            $options['body'] = $request->body()->toString();
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
