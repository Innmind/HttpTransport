<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Curl;

use Innmind\HttpTransport\{
    Failure,
    Header\Timeout,
};
use Innmind\Http\{
    Request,
    Method,
    ProtocolVersion,
    Header,
    Headers,
    Factory\Header\Factory,
};
use Innmind\Url\Authority\UserInformation\User;
use Innmind\IO\{
    IO,
    Files\Temporary,
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
    private Factory $headerFactory;
    private IO $io;
    private Request $request;
    private bool $disableSSLVerification;

    private function __construct(
        Factory $headerFactory,
        IO $io,
        Request $request,
        bool $disableSSLVerification,
    ) {
        $this->headerFactory = $headerFactory;
        $this->io = $io;
        $this->request = $request;
        $this->disableSSLVerification = $disableSSLVerification;
    }

    public static function of(
        Factory $headerFactory,
        IO $io,
        Request $request,
        bool $disableSSLVerification,
    ): self {
        return new self(
            $headerFactory,
            $io,
            $request,
            $disableSSLVerification,
        );
    }

    /**
     * @return Either<Failure, Ready>
     */
    public function start(): Either
    {
        return $this
            ->options()
            ->flatMap(fn($handle) => $this->init($handle[0], $handle[1]))
            ->flatMap(fn($handle) => $this->ready($handle[0], $handle[1]));
    }

    public function request(): Request
    {
        return $this->request;
    }

    /**
     * @param list<array{0: int, 1: mixed}> $options
     *
     * @return Either<Failure, list{\CurlHandle, Temporary}>
     */
    private function init(array $options, Temporary $inFile): Either
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
     * @return Either<Failure, array{0: list<array{0: int, 1: mixed}>, 1: Temporary}>
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
        ];

        if ($this->disableSSLVerification) {
            $options[] = [\CURLOPT_SSL_VERIFYPEER, false];
        }

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
        $options = $headers->find(Timeout::class)->match(
            static fn($header) => \array_merge(
                $options,
                [[\CURLOPT_TIMEOUT, $header->seconds()]],
            ),
            static fn() => $options,
        );

        $headers = $headers->filter(
            static fn($header) => $header->name() !== Timeout::of(1)->normalize()->name(),
        );
        /** @var list<string> */
        $rawHeaders = [];
        $rawHeaders = $headers->reduce(
            $rawHeaders,
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
     * @return Either<Failure, array{0: list<array{0: int, 1: mixed}>, 1: Temporary}>
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

        return $this
            ->io
            ->files()
            ->temporary(
                $this
                    ->request
                    ->body()
                    ->chunks(),
            )
            ->flatMap(static fn($tmp) => $tmp->internal()->rewind()->map(
                static function() use ($tmp, $options) {
                    $options[] = [\CURLOPT_INFILE, $tmp->internal()->resource()];

                    return [$options, $tmp];
                },
            ))
            ->either()
            ->leftMap(fn($error) => new Failure(
                $this->request,
                $error::class,
            ));
    }

    /**
     * @return Either<Failure, Ready>
     */
    private function ready(\CurlHandle $handle, Temporary $inFile): Either
    {
        return $this
            ->io
            ->files()
            ->temporary(Sequence::of())
            ->map(fn($body) => Ready::of(
                $this->io,
                $this->headerFactory,
                $this->request,
                $handle,
                $inFile,
                $body,
            ))
            ->either()
            ->leftMap(fn() => new Failure(
                $this->request,
                'Failed to write response body',
            ));
    }
}
