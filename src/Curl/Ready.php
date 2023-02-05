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
    Redirection,
    ServerError,
    Success,
};
use Innmind\Http\{
    Message\Request,
    Message\Response,
    Message\StatusCode,
    ProtocolVersion,
    Header,
    Headers,
    Factory\Header\TryFactory,
};
use Innmind\Filesystem\File\Content;
use Innmind\Stream\{
    Readable,
    Writable,
    Bidirectional,
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
    private TryFactory $headerFactory;
    private Request $request;
    private \CurlHandle $handle;
    private Writable $inFile;
    private Bidirectional $body;
    private string $status = '';
    /** @var list<string> */
    private array $headers = [];

    private function __construct(
        TryFactory $headerFactory,
        Request $request,
        \CurlHandle $handle,
        Writable $inFile,
        Bidirectional $body,
    ) {
        $this->headerFactory = $headerFactory;
        $this->request = $request;
        $this->handle = $handle;
        $this->inFile = $inFile;
        $this->body = $body;

        \curl_setopt(
            $handle,
            \CURLOPT_HEADERFUNCTION,
            function(\CurlHandle $_, string $header): int {
                if (Str::of($header)->trim()->toLower()->startsWith('http/')) {
                    $this->status = $header;
                } else {
                    /** @psalm-suppress MixedArrayAssignment Doesn't like the reference */
                    $this->headers[] = $header;
                }

                return Str::of($header)->toEncoding('ASCII')->length();
            },
        );
        \curl_setopt(
            $handle,
            \CURLOPT_WRITEFUNCTION,
            function(\CurlHandle $_, string $chunk): int {
                $chunk = Str::of($chunk)->toEncoding('ASCII');

                // return -1 when failed to write to make curl stop
                return $this
                    ->body
                    ->write($chunk)
                    ->match(
                        static fn() => $chunk->length(),
                        static fn() => -1,
                    );
            },
        );
    }

    public static function of(
        TryFactory $headerFactory,
        Request $request,
        \CurlHandle $handle,
        Writable $inFile,
        Bidirectional $body,
    ): self {
        return new self(
            $headerFactory,
            $request,
            $handle,
            $inFile,
            $body,
        );
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
            ->send($errorCode, $this->request, $this->handle, $this->inFile)
            ->flatMap(fn($response) => $this->dispatch($this->request, $response));
    }

    /**
     * @return Either<Failure|ConnectionFailed|MalformedResponse, Response>
     */
    private function send(
        int $errorCode,
        Request $request,
        \CurlHandle $handle,
        Writable $inFile,
    ): Either {
        try {
            return $this->exec($errorCode, $request, $handle, $inFile);
        } finally {
            \curl_close($handle);
        }
    }

    /**
     * @return Either<Failure|ConnectionFailed|MalformedResponse, Response>
     */
    private function exec(
        int $errorCode,
        Request $request,
        \CurlHandle $handle,
        Writable $inFile,
    ): Either {
        $error = $inFile->close()->match(
            static fn() => null,
            static fn($error) => $error,
        );

        if ($error) {
            /** @var Either<Failure|ConnectionFailed|MalformedResponse, Response> */
            return Either::left(new Failure($request, $error::class));
        }

        /**
         * @psalm-suppress MixedArgument Due to the reference on $status and $headers above
         * @var Either<Failure|ConnectionFailed|MalformedResponse, Response>
         */
        return match ($errorCode) {
            \CURLE_OK => $this->buildResponse(
                $request,
                $this->status,
                $this->headers,
                $this->body,
            ),
            \CURLE_COULDNT_RESOLVE_PROXY, \CURLE_COULDNT_RESOLVE_HOST, \CURLE_COULDNT_CONNECT, \CURLE_SSL_CONNECT_ERROR => Either::left(new ConnectionFailed(
                $request,
                \curl_strerror($errorCode) ?? '',
            )),
            default => Either::left(new Failure(
                $request,
                \curl_strerror($errorCode) ?? '',
            )),
        };
    }

    /**
     * @param list<string> $headers
     *
     * @return Either<MalformedResponse, Response>
     */
    private function buildResponse(
        Request $request,
        string $status,
        array $headers,
        Readable $body,
    ): Either {
        $info = Str::of($status)->trim()->capture('~^HTTP/(?<major>\d)(\.(?<minor>\d))? (?<status>\d{3})~');
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
        $headers = Sequence::of(...$headers)
            ->map(static fn($header) => Str::of($header))
            ->map(static fn($header) => $header->rightTrim("\r\n"))
            ->filter(static fn($header) => !$header->empty())
            ->map(static fn($header) => $header->capture('~^(?<name>[a-zA-Z0-9\-\_\.]+): (?<value>.*)$~'))
            ->map(fn($captured) => $this->createHeader($captured))
            ->match(
                static fn($first, $rest) => Maybe::all($first, ...$rest->toList())->map(
                    static fn(Header ...$headers) => Headers::of(...$headers),
                ),
                static fn() => Maybe::just(Headers::of()),
            );

        /** @var Either<MalformedResponse, Response> */
        return Maybe::all($statusCode, $protocolVersion, $headers)
            ->map(static fn(StatusCode $status, ProtocolVersion $protocol, Headers $headers) => new Response\Response(
                $status,
                $protocol,
                $headers,
                Content\OfStream::of($body),
            ))
            ->match(
                static fn($response) => Either::right($response),
                static fn() => Either::left(new MalformedResponse($request)),
            );
    }

    /**
     * @param Map<int|string, Str> $info
     *
     * @return Maybe<Header>
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
    private function dispatch(Request $request, Response $response): Either
    {
        /** @var Either<Information|Redirection|ClientError|ServerError, Success> */
        return match ($response->statusCode()->range()) {
            StatusCode\Range::informational => Either::left(new Information($request, $response)),
            StatusCode\Range::successful => Either::right(new Success($request, $response)),
            StatusCode\Range::redirection => Either::left(new Redirection($request, $response)),
            StatusCode\Range::clientError => Either::left(new ClientError($request, $response)),
            StatusCode\Range::serverError => Either::left(new ServerError($request, $response)),
        };
    }
}
