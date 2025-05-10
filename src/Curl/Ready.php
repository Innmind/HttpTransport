<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Curl;

use Innmind\HttpTransport\{
    Transport,
    ClientError,
    ConnectionFailed,
    Failure,
    Information,
    MalformedResponse,
    MalformedResponse\Raw,
    Redirection,
    ServerError,
    Success,
};
use Innmind\Http\{
    Request,
    Response,
    Response\StatusCode,
    ProtocolVersion,
    Header,
    Headers,
    Factory\Header\Factory,
};
use Innmind\Filesystem\File\Content;
use Innmind\IO\{
    IO,
    Files\Temporary,
};
use Innmind\Immutable\{
    Either,
    Maybe,
    Str,
    Map,
    Sequence,
};

/**
 * @internal
 * @psalm-import-type Errors from Transport
 */
final class Ready
{
    private IO $io;
    private Factory $headerFactory;
    private Request $request;
    private \CurlHandle $handle;
    private Temporary $inFile;
    private Temporary $body;
    private Str $status;
    /** @var Sequence<string> */
    private Sequence $headers;

    private function __construct(
        IO $io,
        Factory $headerFactory,
        Request $request,
        \CurlHandle $handle,
        Temporary $inFile,
        Temporary $body,
    ) {
        $this->io = $io;
        $this->headerFactory = $headerFactory;
        $this->request = $request;
        $this->handle = $handle;
        $this->inFile = $inFile;
        $this->body = $body;
        $this->status = Str::of('');
        $this->headers = Sequence::of();

        \curl_setopt(
            $handle,
            \CURLOPT_HEADERFUNCTION,
            function(\CurlHandle $_, string $header): int {
                if (Str::of($header)->trim()->toLower()->startsWith('http/')) {
                    $this->status = Str::of($header);
                } else {
                    /** @psalm-suppress MixedArrayAssignment Doesn't like the reference */
                    $this->headers = ($this->headers)($header);
                }

                return Str::of($header)->toEncoding(Str\Encoding::ascii)->length();
            },
        );
        \curl_setopt(
            $handle,
            \CURLOPT_WRITEFUNCTION,
            function(\CurlHandle $_, string $chunk): int {
                $chunk = Str::of($chunk)->toEncoding(Str\Encoding::ascii);

                // return -1 when failed to write to make curl stop
                return $this
                    ->body
                    ->push()
                    ->chunk($chunk)
                    ->match(
                        static fn() => $chunk->length(),
                        static fn() => -1,
                    );
            },
        );
    }

    public static function of(
        IO $io,
        Factory $headerFactory,
        Request $request,
        \CurlHandle $handle,
        Temporary $inFile,
        Temporary $body,
    ): self {
        return new self(
            $io,
            $headerFactory,
            $request,
            $handle,
            $inFile,
            $body,
        );
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function handle(): \CurlHandle
    {
        return $this->handle;
    }

    /**
     * @return Either<Errors, Success>
     */
    public function read(int $errorCode): Either
    {
        return $this
            ->finalize($errorCode)
            ->flatMap($this->dispatch(...));
    }

    /**
     * @return Either<Failure|ConnectionFailed|MalformedResponse, Response>
     */
    private function finalize(int $errorCode): Either
    {
        try {
            return $this->decode($errorCode);
        } finally {
            // We need to override the callbacks defined in the constructor of
            // this object to remove the reference to this object inside the
            // callback, otherwise this object will not be destroyed until the
            // end of the PHP program
            \curl_setopt(
                $this->handle,
                \CURLOPT_HEADERFUNCTION,
                static fn(\CurlHandle $_, string $header): int => Str::of($header)
                    ->toEncoding(Str\Encoding::ascii)
                    ->length(),
            );
            \curl_setopt(
                $this->handle,
                \CURLOPT_WRITEFUNCTION,
                static fn(\CurlHandle $_, string $chunk): int => Str::of($chunk)
                    ->toEncoding(Str\Encoding::ascii)
                    ->length(),
            );
            \curl_close($this->handle);
        }
    }

    /**
     * @return Either<Failure|ConnectionFailed|MalformedResponse, Response>
     */
    private function decode(int $errorCode): Either
    {
        return $this
            ->inFile
            ->close()
            ->either()
            ->leftMap(fn($error) => new Failure(
                $this->request,
                $error::class,
            ))
            ->flatMap(function() use ($errorCode) {
                /** @var Either<Failure|ConnectionFailed|MalformedResponse, Response> */
                return match ($errorCode) {
                    \CURLE_OK => $this->buildResponse(),
                    \CURLE_COULDNT_RESOLVE_PROXY,
                    \CURLE_COULDNT_RESOLVE_HOST,
                    \CURLE_COULDNT_CONNECT,
                    \CURLE_SSL_CONNECT_ERROR => Either::left(new ConnectionFailed(
                        $this->request,
                        \curl_strerror($errorCode) ?? '',
                    )),
                    default => Either::left(new Failure(
                        $this->request,
                        \curl_strerror($errorCode) ?? '',
                    )),
                };
            });
    }

    /**
     * @return Either<MalformedResponse, Response>
     */
    private function buildResponse(): Either
    {
        $info = $this->status->trim()->capture('~^HTTP/(?<major>\d)(\.(?<minor>\d))? (?<status>\d{3})~');
        $major = $info
            ->get('major')
            ->map(static fn($major) => $major->toString())
            ->filter(\is_numeric(...))
            ->map(static fn($major) => (int) $major);
        $minor = $info
            ->get('minor')
            ->map(static fn($minor) => $minor->toString())
            ->filter(\is_numeric(...))
            ->map(static fn($minor) => (int) $minor)
            ->otherwise(static fn() => Maybe::just(0));
        $protocolVersion = Maybe::all($major, $minor)->flatMap(
            static fn(int $major, int $minor) => ProtocolVersion::maybe($major, $minor),
        );
        $statusCode = $info
            ->get('status')
            ->map(static fn($status) => $status->toString())
            ->flatMap(static fn($status) => StatusCode::maybe((int) $status));
        /**
         * @psalm-suppress NamedArgumentNotAllowed
         * Technically as header name can contain any octet between 0 and 127
         * except control ones, the regexp below is a bit more restrictive than
         * that by only accepting letters, numbers, '-', '_' and '.'
         * @see https://www.rfc-editor.org/rfc/rfc2616#section-4.2
         */
        $headers = $this
            ->headers
            ->map(static fn($header) => Str::of($header))
            ->map(static fn($header) => $header->rightTrim("\r\n"))
            ->filter(static fn($header) => !$header->empty())
            ->map(static fn($header) => $header->capture('~^(?<name>[a-zA-Z0-9\-\_\.]+): (?<value>.*)$~'))
            ->map(fn($captured) => $this->createHeader($captured))
            ->sink(Headers::of())
            ->maybe(static fn($headers, $header) => $header->map($headers));

        /** @var Either<MalformedResponse, Response> */
        return Maybe::all($statusCode, $protocolVersion, $headers)
            ->map(fn(StatusCode $status, ProtocolVersion $protocol, Headers $headers) => Response::of(
                $status,
                $protocol,
                $headers,
                Content::io($this->body->read()),
            ))
            ->either()
            ->leftMap(fn() => new MalformedResponse($this->request, Raw::of(
                $this->status,
                $this->headers->map(Str::of(...)),
                Content::io($this->body->read()),
            )));
    }

    /**
     * @param Map<int|string, Str> $info
     *
     * @return Maybe<Header|Header\Custom>
     */
    private function createHeader(Map $info): Maybe
    {
        return Maybe::all($info->get('name'), $info->get('value'))->map(
            fn(Str $name, Str $value) => ($this->headerFactory)($name, $value),
        );
    }

    /**
     * @return Either<Information|Redirection|ClientError|ServerError, Success>
     */
    private function dispatch(Response $response): Either
    {
        /** @var Either<Information|Redirection|ClientError|ServerError, Success> */
        return match ($response->statusCode()->range()) {
            StatusCode\Range::informational => Either::left(new Information($this->request, $response)),
            StatusCode\Range::successful => Either::right(new Success($this->request, $response)),
            StatusCode\Range::redirection => Either::left(new Redirection($this->request, $response)),
            StatusCode\Range::clientError => Either::left(new ClientError($this->request, $response)),
            StatusCode\Range::serverError => Either::left(new ServerError($this->request, $response)),
        };
    }
}
