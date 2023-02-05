<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Curl;

use Innmind\HttpTransport\Failure;
use Innmind\Http\{
    Message\Request,
    Message\Method,
    ProtocolVersion,
    Header,
    Headers,
    Factory\Header\TryFactory,
};
use Innmind\Filesystem\File\Content;
use Innmind\Url\Authority\UserInformation\User;
use Innmind\Stream\{
    Capabilities,
    Writable,
    FailedToWriteToStream,
    DataPartiallyWritten,
    Exception\InvalidArgumentException,
};
use Innmind\Immutable\{
    Either,
    Str,
    Sequence,
};

/**
 * @internal
 */
final class Scheduled
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
     * @return Either<Failure, Ready>
     */
    public function __invoke(): Either
    {
        return $this
            ->options()
            ->flatMap(fn($handle) => $this->init($handle[0], $handle[1]))
            ->flatMap(fn($handle) => $this->ready($handle[0], $handle[1]));
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
    private function init(array $options, Writable $inFile): Either
    {
        $handle = \curl_init(
            $this
                ->request
                ->url()
                ->withAuthority(
                    $this->request->url()->authority()->withoutUserInformation(),
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

            return Either::left(new Failure($this->request, $reason));
        }

        $configured = true;

        /** @var mixed $value */
        foreach ($options as [$option, $value]) {
            if ($configured) {
                $configured = \curl_setopt($handle, $option, $value);
            }
        }

        if (!$configured) {
            return Either::left(new Failure($this->request, 'Failed to configure the curl handle'));
        }

        return Either::right([$handle, $inFile]);
    }

    /**
     * @return Either<Failure, array{0: list<array{0: int, 1: mixed}>, 1: Writable}>
     */
    private function options(): Either
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
            [\CURLOPT_HTTP_VERSION, match ($this->request->protocolVersion()) {
                ProtocolVersion::v10 => \CURL_HTTP_VERSION_1_0,
                ProtocolVersion::v11 => \CURL_HTTP_VERSION_1_1,
                ProtocolVersion::v20 => \CURL_HTTP_VERSION_2_0,
            }],
            [\CURLOPT_PROTOCOLS, \CURLPROTO_HTTP | \CURLPROTO_HTTPS],
            [\CURLOPT_TCP_KEEPALIVE, 1],
            // set CURLOPT_TIMEOUT ?
        ];

        $header = match ($this->request->method()) {
            Method::head => [\CURLOPT_NOBODY, true],
            Method::get => [\CURLOPT_HTTPGET, true],
            default => [\CURLOPT_CUSTOMREQUEST, $this->request->method()->toString()],
        };
        $options[] = $header;

        $options = \array_merge(
            $options,
            $this->headersOptions($this->request->headers()),
        );
        $user = $this->request->url()->authority()->userInformation()->user();
        $password = $this->request->url()->authority()->userInformation()->password();

        if (!$user->equals(User::none())) {
            $options[] = [\CURLOPT_USERPWD, $password->format($user)];
        }

        return $this
            ->bodyOptions()
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
    private function bodyOptions(): Either
    {
        /** @var list<array{0: int, 1: bool|int}> */
        $options = $this
            ->request
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
        $written = ($this->chunk)($this->request->body())
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
            ->leftMap(fn($error) => new Failure(
                $this->request,
                $error::class,
            ));
    }

    /**
     * @return Either<Failure, Ready>
     */
    private function ready(\CurlHandle $handle, Writable $inFile): Either
    {
        try {
            $body = $this
                ->capabilities
                ->temporary()
                ->new();
        } catch (InvalidArgumentException $e) {
            /** @var Either<Failure, Ready> */
            return Either::left(new Failure($this->request, 'Failed to write response body'));
        }

        return Either::right(Ready::of(
            $this->headerFactory,
            $this->request,
            $handle,
            $inFile,
            $body,
        ));
    }
}
