<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

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
use Innmind\TimeContinuum\Clock;
use Innmind\Filesystem\{
    File\Content,
    Adapter\Chunk,
};
use Innmind\Url\Authority\UserInformation\User;
use Innmind\Stream\{
    Readable,
    Writable,
};
use Innmind\Immutable\{
    Either,
    Maybe,
    Str,
    Map,
    Sequence,
};

final class Curl implements Transport
{
    private TryFactory $headerFactory;
    /** @var callable(Content): Sequence<Str> */
    private $chunk;

    /**
     * @param callable(Content): Sequence<Str> $chunk
     */
    private function __construct(Clock $clock, callable $chunk)
    {
        $this->headerFactory = new TryFactory(
            Factories::default($clock),
        );
        $this->chunk = $chunk;
    }

    public function __invoke(Request $request): Either
    {
        return $this
            ->options($request)
            ->flatMap(fn($handle) => $this->init($request, $handle[0], $handle[1]))
            ->flatMap(function($handle) use ($request) {
                try {
                    return $this->exec($request, $handle[0], $handle[1]);
                } finally {
                    \curl_close($handle[0]);
                }
            })
            ->flatMap(static fn($response) => match ($response->statusCode()->range()) {
                StatusCode\Range::informational => Either::left(new Information($request, $response)),
                StatusCode\Range::successful => Either::right(new Success($request, $response)),
                StatusCode\Range::redirection => Either::left(new Redirection($request, $response)),
                StatusCode\Range::clientError => Either::left(new ClientError($request, $response)),
                StatusCode\Range::serverError => Either::left(new ServerError($request, $response)),
            });
    }

    /**
     * @param callable(Content): Sequence<Str> $chunk
     */
    public static function of(Clock $clock, callable $chunk = new Chunk): self
    {
        return new self($clock, $chunk);
    }

    /**
     * @param list<array{0: int, 1: mixed}> $options
     *
     * @return Either<Failure, array{0: \CurlHandle, 1: Writable}>
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

        foreach ($options as [$option, $value]) {
            // todo verify the option is correctly set, otherwise close the
            // handle and return Failure
            \curl_setopt($handle, $option, $value);
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
            static fn($headers, $header) => match ($header->name()) {
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

        $inFile = Writable\Stream::of(\fopen('php://temp', 'r+'));

        return ($this->chunk)($request->body())
            ->map(static fn($chunk) => $chunk->toEncoding('ASCII'))
            ->reduce(
                Either::right($inFile),
                static fn($either, $chunk) => $either->flatMap(
                    static fn($inFile) => $inFile->write($chunk),
                ),
            )
            ->flatMap(static fn($inFile) => $inFile->rewind())
            ->map(static function($inFile) use ($options) {
                $options[] = [\CURLOPT_INFILE, $inFile->resource()];

                return [$options, $inFile];
            })
            ->leftMap(static fn($error) => new Failure(
                $request,
                $e::class,
            ));
    }

    /**
     * @return Either<Failure|ConnectionFailed|MalformedResponse, Response>
     */
    private function exec(Request $request, \CurlHandle $handle, Writable $inFile): Either
    {
        $status = '';
        $headers = [];
        // todo find a way to close these streams when the body is no longer used
        // but beware of process forks where the stream in used only on one side
        $body = \fopen('php://temp', 'r+');

        if (!\is_resource($body)) {
            return Either::left(new Failure($request, 'Failed to write response body'));
        }

        \curl_setopt($handle, \CURLOPT_HEADERFUNCTION, static function($_, string $header) use (&$status, &$headers): int {
            if (Str::of($header)->trim()->toLower()->startsWith('http/')) {
                $status = $header;
            } else {
                $headers[] = $header;
            }

            return Str::of($header)->toEncoding('ASCII')->length();
        });
        \curl_setopt($handle, \CURLOPT_WRITEFUNCTION, static function($_, string $chunk) use ($body): int {
            $written = \fwrite($body, $chunk);

            // return -1 when failed to write to make curl stop
            return \is_int($written) ? $written : -1;
        });

        if (\curl_exec($handle) === false) {
            return Either::left(new Failure($request, 'Curl failed to execute the request'));
        }

        $error = $inFile->close()->match(
            static fn() => null,
            static fn($error) => $error,
        );

        if ($error) {
            return Either::left(new Failure($request, $error::class));
        }

        return match (\curl_errno($handle)) {
            \CURLE_OK => $this->buildResponse(
                $request,
                $status,
                $headers,
                Readable\Stream::of($body),
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
     * @return Either<MalformedResponse, Response>
     */
    private function buildResponse(
        Request $request,
        string $status,
        array $headers,
        Readable $body,
    ): Either {
        $info = Str::of($status)->trim()->capture('~^HTTP/(?<major>\d)\.(?<minor>\d) (?<status>\d{3})~');
        $major = $info
            ->get('major')
            ->map(static fn($major) => $major->toString())
            ->map(static fn($major) => (int) $major);
        $minor = $info
            ->get('minor')
            ->map(static fn($minor) => $minor->toString())
            ->map(static fn($minor) => (int) $minor);
        $protocolVersion = Maybe::all($major, $minor)->flatMap(
            static fn(int $major, int $minor) => ProtocolVersion::maybe($major, $minor),
        );
        $statusCode = $info
            ->get('status')
            ->map(static fn($status) => $status->toString())
            ->flatMap(static fn($status) => StatusCode::maybe((int) $status));
        $headers = Sequence::of(...$headers)
            ->map(static fn($header) => Str::of($header))
            ->map(static fn($header) => $header->rightTrim("\r\n"))
            ->filter(static fn($header) => !$header->empty())
            ->map(static fn($header) => $header->capture('~^(?<name>[a-zA-Z\-\_]+): (?<value>.*)$~'))
            ->map(fn($captured) => $this->createHeader($captured))
            ->match(
                static fn($first, $rest) => Maybe::all($first, ...$rest->toList())->map(
                    static fn(Header ...$headers) => Headers::of(...$headers),
                ),
                static fn() => Maybe::just(Headers::of()),
            );

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
     * @param Map<string, Str> $info
     *
     * @return Maybe<Header>
     */
    private function createHeader(Map $info): Maybe
    {
        return Maybe::all($info->get('name'), $info->get('value'))->map(
            fn(Str $name, Str $value) => ($this->headerFactory)($name, $value),
        );
    }
}
