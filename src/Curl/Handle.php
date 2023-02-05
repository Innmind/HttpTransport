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
    Message\Method,
    Message\StatusCode,
    ProtocolVersion,
    Header,
    Headers,
    Factory\Header\TryFactory,
    Factory\Header\Factories,
};
use Innmind\Filesystem\{
    File\Content,
    Chunk,
};
use Innmind\Url\Authority\UserInformation\User;
use Innmind\Stream\{
    Capabilities,
    Streams,
    Readable,
    Writable,
    FailedToWriteToStream,
    DataPartiallyWritten,
    Exception\InvalidArgumentException,
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
final class Handle
{
    private TryFactory $headerFactory;
    private Capabilities $capabilities;
    /** @var callable(Content): Sequence<Str> */
    private $chunk;
    private Request $request;

    /**
     * @param callable(Content): Sequence<Str> $chunk
     */
    private function __construct(
        TryFactory $headerFactory,
        Capabilities $capabilities,
        callable $chunk,
        Request $request,
    ) {
        $this->headerFactory = $headerFactory;
        $this->capabilities = $capabilities;
        $this->chunk = $chunk;
        $this->request = $request;
    }

    /**
     * @param callable(\CurlHandle): bool $exec
     *
     * @return Either<Errors, Success>
     */
    public function __invoke(callable $exec): Either
    {
        return $this
            ->options($this->request)
            ->flatMap(fn($handle) => $this->init($this->request, $handle[0], $handle[1]))
            ->flatMap(fn($handle) => $this->send($exec, $this->request, $handle[0], $handle[1]))
            ->flatMap(fn($response) => $this->dispatch($this->request, $response));
    }

    /**
     * @param callable(Content): Sequence<Str> $chunk
     */
    public static function of(
        TryFactory $headerFactory,
        Capabilities $capabilities,
        callable $chunk,
        Request $request,
    ): self {
        return new self($headerFactory, $capabilities, $chunk, $request);
    }

    /**
     * @param list<array{0: int, 1: mixed}> $options
     *
     * @return Either<Failure, list{\CurlHandle, Writable}>
     */
    private function init(
        Request $request,
        array $options,
        Writable $inFile,
    ): Either {
        $handle = \curl_init(
            $request
                ->url()
                ->withAuthority(
                    $request->url()->authority()->withoutUserInformation(),
                )
                ->toString(),
        );

        if ($handle === false) {
            $reason = $inFile
                ->close()
                ->match(
                    static fn() => 'Failed to start a new curl handle',
                    static fn($error) => $error::class,
                );

            return Either::left(new Failure($request, $reason));
        }

        $configured = true;

        /** @var mixed $value */
        foreach ($options as [$option, $value]) {
            if ($configured) {
                $configured = \curl_setopt($handle, $option, $value);
            }
        }

        if (!$configured) {
            return Either::left(new Failure($request, 'Failed to configure the curl handle'));
        }

        return Either::right([$handle, $inFile]);
    }

    /**
     * @return Either<Failure, array{0: list<array{0: int, 1: mixed}>, 1: Writable}>
     */
    private function options(Request $request): Either
    {
        /** @var list<array{0: int, 1: mixed}> */
        $options = [
            // never keep state hidden from the caller
            [\CURLOPT_COOKIESESSION, true],
            [\CURLOPT_DISALLOW_USERNAME_IN_URL, true],
            [\CURLOPT_FAILONERROR, false],
            // following redirections must be an explicit behaviours added by
            // the caller
            // @see FollowRedirects class
            [\CURLOPT_FOLLOWLOCATION, false],
            [\CURLOPT_HEADER, false],
            [\CURLOPT_RETURNTRANSFER, false],
            [\CURLOPT_HTTP_VERSION, match ($request->protocolVersion()) {
                ProtocolVersion::v10 => \CURL_HTTP_VERSION_1_0,
                ProtocolVersion::v11 => \CURL_HTTP_VERSION_1_1,
                ProtocolVersion::v20 => \CURL_HTTP_VERSION_2_0,
            }],
            [\CURLOPT_PROTOCOLS, \CURLPROTO_HTTP | \CURLPROTO_HTTPS],
            [\CURLOPT_TCP_KEEPALIVE, 1],
            // set CURLOPT_TIMEOUT ?
        ];

        $header = match ($request->method()) {
            Method::head => [\CURLOPT_NOBODY, true],
            Method::get => [\CURLOPT_HTTPGET, true],
            default => [\CURLOPT_CUSTOMREQUEST, $request->method()->toString()],
        };
        $options[] = $header;

        $options = \array_merge(
            $options,
            $this->headersOptions($request->headers()),
        );
        $user = $request->url()->authority()->userInformation()->user();
        $password = $request->url()->authority()->userInformation()->password();

        if (!$user->equals(User::none())) {
            $options[] = [\CURLOPT_USERPWD, $password->format($user)];
        }

        return $this
            ->bodyOptions($request)
            ->map(static fn($info) => [
                \array_merge(
                    $options,
                    $info[0],
                ),
                $info[1],
            ]);
    }

    /**
     * @return list<array{0: int, 1: mixed}>
     */
    private function headersOptions(Headers $headers): array
    {
        $options = $headers->get('cookie')->match(
            fn($header) => $this->cookieOption($header),
            static fn() => [],
        );
        $options = $headers->get('accept-encoding')->match(
            fn($header) => \array_merge(
                $options,
                $this->acceptEncodingOption($header),
            ),
            static fn() => $options,
        );
        $options = $headers->get('referer')->match(
            fn($header) => \array_merge(
                $options,
                $this->refererOption($header),
            ),
            static fn() => $options,
        );

        /** @var list<string> */
        $rawHeaders = $headers->reduce(
            [],
            static fn(array $headers, $header) => match ($header->name()) {
                'Cookie' => $headers, // configured above
                'Accept-Encoding' => $headers, // configured above
                'Referer' => $headers, // configured above
                default => \array_merge($headers, [$header->toString()]),
            },
        );

        $options[] = [\CURLOPT_HTTPHEADER, $rawHeaders];

        return $options;
    }

    /**
     * @return list<array{0: int, 1: mixed}>
     */
    private function cookieOption(Header $cookie): array
    {
        return [[\CURLOPT_COOKIE, $this->values($cookie)]];
    }

    /**
     * @return list<array{0: int, 1: mixed}>
     */
    private function acceptEncodingOption(Header $encoding): array
    {
        return [[\CURLOPT_ENCODING, $this->values($encoding)]];
    }

    /**
     * @return list<array{0: int, 1: mixed}>
     */
    private function refererOption(Header $referer): array
    {
        return [[\CURLOPT_REFERER, $this->values($referer)]];
    }

    private function values(Header $header): string
    {
        return Str::of(', ')
            ->join(
                $header->values()->map(static fn($value) => $value->toString()),
            )
            ->toString();
    }

    /**
     * @return Either<Failure, array{0: list<array{0: int, 1: mixed}>, 1: Writable}>
     */
    private function bodyOptions(Request $request): Either
    {
        /** @var list<array{0: int, 1: bool|int}> */
        $options = $request
            ->body()
            ->size()
            ->filter(static fn($size) => $size->toInt() > 0)
            ->match(
                static fn($size) => [
                    [\CURLOPT_UPLOAD, true],
                    [\CURLOPT_INFILESIZE, $size->toInt()],
                ],
                static fn() => [],
            );

        $inFile = $this
            ->capabilities
            ->temporary()
            ->new();
        /** @var Either<FailedToWriteToStream|DataPartiallyWritten, Writable\Stream> */
        $carry = Either::right($inFile);

        /** @psalm-suppress MixedArgumentTypeCoercion Due to the reduce */
        $written = ($this->chunk)($request->body())
            ->map(static fn($chunk) => $chunk->toEncoding('ASCII'))
            ->reduce(
                $carry,
                static fn(Either $either, $chunk): Either => $either->flatMap(
                    static fn(Writable $inFile) => $inFile->write($chunk),
                ),
            );

        /** @var Either<Failure, array{0: list<array{0: int, 1: mixed}>, 1: Writable}> */
        return $written
            ->flatMap(static fn($inFile) => $inFile->rewind())
            ->map(static function($inFile) use ($options) {
                /** @psalm-suppress UndefinedInterfaceMethod */
                $options[] = [\CURLOPT_INFILE, $inFile->resource()];

                return [$options, $inFile];
            })
            ->leftMap(static fn($error) => new Failure(
                $request,
                $error::class,
            ));
    }

    /**
     * @param callable(\CurlHandle): bool $exec
     *
     * @return Either<Failure|ConnectionFailed|MalformedResponse, Response>
     */
    private function send(
        callable $exec,
        Request $request,
        \CurlHandle $handle,
        Writable $inFile,
    ): Either {
        try {
            return $this->exec($exec, $request, $handle, $inFile);
        } finally {
            \curl_close($handle);
        }
    }

    /**
     * @param callable(\CurlHandle): bool $exec
     *
     * @return Either<Failure|ConnectionFailed|MalformedResponse, Response>
     */
    private function exec(
        callable $exec,
        Request $request,
        \CurlHandle $handle,
        Writable $inFile,
    ): Either {
        $status = '';
        /** @var list<string> */
        $headers = [];

        try {
            $body = $this
                ->capabilities
                ->temporary()
                ->new();
        } catch (InvalidArgumentException $e) {
            /** @var Either<Failure|ConnectionFailed|MalformedResponse, Response> */
            return Either::left(new Failure($request, 'Failed to write response body'));
        }

        \curl_setopt(
            $handle,
            \CURLOPT_HEADERFUNCTION,
            static function(\CurlHandle $_, string $header) use (&$status, &$headers): int {
                if (Str::of($header)->trim()->toLower()->startsWith('http/')) {
                    $status = $header;
                } else {
                    /** @psalm-suppress MixedArrayAssignment Doesn't like the reference */
                    $headers[] = $header;
                }

                return Str::of($header)->toEncoding('ASCII')->length();
            },
        );
        \curl_setopt(
            $handle,
            \CURLOPT_WRITEFUNCTION,
            static function(\CurlHandle $_, string $chunk) use ($body): int {
                $chunk = Str::of($chunk)->toEncoding('ASCII');

                // return -1 when failed to write to make curl stop
                return $body
                    ->write($chunk)
                    ->match(
                        static fn() => $chunk->length(),
                        static fn() => -1,
                    );
            },
        );

        if ($exec($handle) === false) {
            /** @var Either<Failure|ConnectionFailed|MalformedResponse, Response> */
            return Either::left(new Failure($request, 'Curl failed to execute the request'));
        }

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
        return match (\curl_errno($handle)) {
            \CURLE_OK => $this->buildResponse(
                $request,
                $status,
                $headers,
                $body,
            ),
            \CURLE_COULDNT_RESOLVE_PROXY, \CURLE_COULDNT_RESOLVE_HOST, \CURLE_COULDNT_CONNECT, \CURLE_SSL_CONNECT_ERROR => Either::left(new ConnectionFailed(
                $request,
                \curl_error($handle),
            )),
            default => Either::left(new Failure(
                $request,
                \curl_error($handle),
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
