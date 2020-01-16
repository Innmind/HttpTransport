<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\Exception\ConnectionFailed;
use Innmind\Http\{
    Message\Request,
    Message\Response,
    Translator\Response\Psr7Translator,
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
    private Psr7Translator $translator;

    public function __construct(
        ClientInterface $client,
        Psr7Translator $translator
    ) {
        $this->client = $client;
        $this->translator = $translator;
    }

    public function __invoke(Request $request): Response
    {
        $options = [];
        $headers = [];

        foreach ($request->headers() as $header) {
            $headers[$header->name()] = $header
                ->values()
                ->reduce(
                    [],
                    function(array $raw, Value $value): array {
                        $raw[] = (string) $value;

                        return $raw;
                    }
                );
        }

        if (count($headers) > 0) {
            $options['headers'] = $headers;
        }

        if ($request->body()->size()->toInt() > 0) {
            $options['body'] = (string) $request->body();
        }

        try {
            $response = $this->client->request(
                (string) $request->method(),
                (string) $request->url(),
                $options,
            );
        } catch (GuzzleConnectException $e) {
            throw new ConnectionFailed($request, $e);
        } catch (BadResponseException $e) {
            $response = $e->getResponse();
        }

        return $this->translator->translate($response);
    }
}
