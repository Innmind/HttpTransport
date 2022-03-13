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
use Innmind\Stream\Readable;
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
        $handle = $this->init($request);
        $handle = $this->configure($handle, $request);

        try {
            return $this
                ->exec($handle, $request)
                ->flatMap(static fn($response) => match ($response->statusCode()->range()) {
                    StatusCode\Range::informational => Either::left(new Information($request, $response)),
                    StatusCode\Range::successful => Either::right(new Success($request, $response)),
                    StatusCode\Range::redirection => Either::left(new Redirection($request, $response)),
                    StatusCode\Range::clientError => Either::left(new ClientError($request, $response)),
                    StatusCode\Range::serverError => Either::left(new ServerError($request, $response)),
                });
        } finally {
            \curl_close($handle);
        }
    }

    /**
     * @param callable(Content): Sequence<Str> $chunk
     */
    public static function of(Clock $clock, callable $chunk = new Chunk): self
    {
        return new self($clock, $chunk);
    }

    private function init(Request $request): \CurlHandle
    {
        return \curl_init(
            $request
                ->url()
                ->withAuthority(
                    $request->url()->authority()->withoutUserInformation(),
                )
                ->toString(),
        );
    }

    private function configure(\CurlHandle $handle, Request $request): \CurlHandle
    {
        // never keep state hidden from the caller
        \curl_setopt($handle, \CURLOPT_COOKIESESSION, true);
        \curl_setopt($handle, \CURLOPT_DISALLOW_USERNAME_IN_URL, true);
        \curl_setopt($handle, \CURLOPT_FAILONERROR, false);
        // following redirections must be an explicit behaviours added by the caller
        // @see FollowRedirects class
        \curl_setopt($handle, \CURLOPT_FOLLOWLOCATION, false);
        \curl_setopt($handle, \CURLOPT_HEADER, false);
        \curl_setopt($handle, \CURLOPT_RETURNTRANSFER, false);
        \curl_setopt($handle, \CURLOPT_HTTP_VERSION, match ($request->protocolVersion()) {
            ProtocolVersion::v10 => \CURL_HTTP_VERSION_1_0,
            ProtocolVersion::v11 => \CURL_HTTP_VERSION_1_1,
            ProtocolVersion::v20 => \CURL_HTTP_VERSION_2_0,
        });
        \curl_setopt($handle, \CURLOPT_PROTOCOLS, \CURLPROTO_HTTP | \CURLPROTO_HTTPS);
        \curl_setopt($handle, \CURLOPT_TCP_KEEPALIVE, 1);
        // set CURLOPT_TIMEOUT ?
        match ($request->method()) {
            Method::head => \curl_setopt($handle, \CURLOPT_NOBODY, true),
            Method::get => \curl_setopt($handle, \CURLOPT_HTTPGET, true),
            default => \curl_setopt($handle, \CURLOPT_CUSTOMREQUEST, $request->method()->toString()),
        };
        $handle = $this->specifyHeaders($handle, $request->headers());
        $user = $request->url()->authority()->userInformation()->user();
        $password = $request->url()->authority()->userInformation()->password();

        if (!$user->equals(User::none())) {
            \curl_setopt($handle, \CURLOPT_USERPWD, $password->format($user));
        }

        $handle = $this->specifyBody($handle, $request->body());

        return $handle;
    }

    private function specifyHeaders(\CurlHandle $handle, Headers $headers): \CurlHandle
    {
        $handle = $headers->get('cookie')->match(
            fn($header) => $this->specifyCookie($handle, $header),
            static fn() => $handle,
        );
        $handle = $headers->get('accept-encoding')->match(
            fn($header) => $this->specifyAcceptEncoding($handle, $header),
            static fn() => $handle,
        );
        $handle = $headers->get('referer')->match(
            fn($header) => $this->specifyReferer($handle, $header),
            static fn() => $handle,
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

        \curl_setopt($handle, \CURLOPT_HTTPHEADER, $rawHeaders);

        return $handle;
    }

    private function specifyCookie(\CurlHandle $handle, Header $cookie): \CurlHandle
    {
        \curl_setopt($handle, \CURLOPT_COOKIE, $this->values($cookie));

        return $handle;
    }

    private function specifyAcceptEncoding(\CurlHandle $handle, Header $encoding): \CurlHandle
    {
        \curl_setopt($handle, \CURLOPT_ENCODING, $this->values($encoding));

        return $handle;
    }

    private function specifyReferer(\CurlHandle $handle, Header $referer): \CurlHandle
    {
        \curl_setopt($handle, \CURLOPT_REFERER, $this->values($referer));

        return $handle;
    }

    private function values(Header $header): string
    {
        return Str::of(', ')
            ->join(
                $header->values()->map(static fn($value) => $value->toString()),
            )
            ->toString();
    }

    private function specifyBody(\CurlHandle $handle, Content $content): \CurlHandle
    {
        /** @var list<array{0: int, 1: bool|int}> */
        $options = $content
            ->size()
            ->filter(static fn($size) => $size->toInt() > 0)
            ->match(
                static fn($size) => [
                    [\CURLOPT_UPLOAD, true],
                    [\CURLOPT_INFILESIZE, $size->toInt()],
                ],
                static fn() => [],
            );

        foreach ($options as [$option, $value]) {
            \curl_setopt($handle, $option, $value);
        }

        $chunks = ($this->chunk)($content)
            ->map(static fn($chunk) => $chunk->toEncoding('ASCII'))
            ->filter(static fn($chunk) => !$chunk->empty());
        [$chunk, $chunks] = $chunks->match(
            static fn($chunk, $chunks) => [$chunk, $chunks],
            static fn() => [Str::of(''), Sequence::of()],
        );

        // todo rewrite everything with a php://temp stream as the ::match()
        // strategy keeps everything in memory
        \curl_setopt(
            $handle,
            \CURLOPT_READFUNCTION,
            static function($_, $__, int $length) use (&$chunk, &$chunks, $content) {
                if ($chunk->empty() && $chunks->empty()) {
                    // no more data to write, the empty string will instruct
                    // curl to stop
                    return '';
                }

                $toWrite = $chunk->take($length);
                $chunk = $chunk->drop($length);

                if ($chunk->empty()) {
                    [$chunk, $chunks] = $chunks->match(
                        static fn($chunk, $chunks) => [$chunk, $chunks],
                        static fn() => [Str::of(''), Sequence::of()],
                    );
                }

                return $toWrite->toString();
            },
        );

        return $handle;
    }

    /**
     * @return Either<Failure|ConnectionFailed|MalformedResponse, Response>
     */
    private function exec(\CurlHandle $handle, Request $request): Either
    {
        $status = '';
        $headers = [];
        // todo find a way to close these streams when the body is no longer used
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
