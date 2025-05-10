<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Request,
    Method,
    Response\StatusCode,
    Header\Location,
};
use Innmind\Url\{
    Url,
    Authority,
};
use Innmind\Immutable\Either;

/**
 * @psalm-import-type Errors from Transport
 */
final class FollowRedirections implements Transport
{
    /**
     * @param int<1, max> $hops
     */
    private function __construct(
        private Transport $fulfill,
        private int $hops,
    ) {
    }

    #[\Override]
    public function __invoke(Request $request): Either
    {
        return $this->fulfill($request, $this->hops);
    }

    public static function of(Transport $fulfill): self
    {
        return new self($fulfill, 5);
    }

    /**
     * @param int<0, max> $hops
     *
     * @return Either<Errors, Success>
     */
    private function fulfill(Request $request, int $hops): Either
    {
        return ($this->fulfill)($request)->otherwise(
            fn($error) => match ($hops) {
                0 => Either::left($error),
                default => $this->maybeRedirect($error, $hops - 1),
            },
        );
    }

    /**
     * @param Errors $error
     * @param int<0, max> $hops
     *
     * @return Either<Errors, Success>
     */
    private function maybeRedirect(object $error, int $hops): Either
    {
        /** @var Either<Errors, Success> */
        return match (true) {
            $error instanceof Redirection => $this->handle($error, $hops),
            default => Either::left($error),
        };
    }

    /**
     * @param int<0, max> $hops
     *
     * @return Either<Errors, Success>
     */
    private function handle(Redirection $redirection, int $hops): Either
    {
        /** @var Either<Errors, Success> */
        return match ($redirection->response()->statusCode()) {
            StatusCode::movedPermanently => $this->redirect($redirection, $hops),
            StatusCode::found => $this->redirect($redirection, $hops),
            StatusCode::seeOther => $this->seeOther($redirection, $hops),
            StatusCode::temporaryRedirect => $this->redirect($redirection, $hops),
            StatusCode::permanentlyRedirect => $this->redirect($redirection, $hops),
            default => Either::left($redirection), // some redirections cannot be applied
        };
    }

    /**
     * @param int<0, max> $hops
     *
     * @return Either<Errors, Success>
     */
    private function redirect(Redirection $redirection, int $hops): Either
    {
        /** @var Either<Errors, Success> */
        return $redirection
            ->response()
            ->headers()
            ->find(Location::class)
            ->map(static fn($header) => $header->url())
            ->map(static fn($url) => Request::of(
                self::resolveUrl($redirection->request()->url(), $url),
                $redirection->request()->method(),
                $redirection->request()->protocolVersion(),
                $redirection->request()->headers(),
                $redirection->request()->body(),
            ))
            ->filter(static fn($request) => \in_array(
                $request->method(),
                [Method::get, Method::head], // @see https://datatracker.ietf.org/doc/html/rfc2616/#section-10.3.2
                true,
            ))
            ->match(
                fn($request) => $this->fulfill($request, $hops),
                static fn() => Either::left($redirection),
            );
    }

    /**
     * @param int<0, max> $hops
     *
     * @return Either<Errors, Success>
     */
    private function seeOther(Redirection $redirection, int $hops): Either
    {
        /** @var Either<Errors, Success> */
        return $redirection
            ->response()
            ->headers()
            ->find(Location::class)
            ->map(static fn($header) => $header->url())
            ->map(static fn($url) => Request::of(
                self::resolveUrl($redirection->request()->url(), $url),
                Method::get,
                $redirection->request()->protocolVersion(),
                $redirection->request()->headers(),
            ))
            ->match(
                fn($request) => $this->fulfill($request, $hops),
                static fn() => Either::left($redirection),
            );
    }

    private static function resolveUrl(Url $request, Url $location): Url
    {
        if ($location->authority()->equals(Authority::none())) {
            $location = $location
                ->withScheme($request->scheme())
                ->withAuthority($request->authority());
        }

        return $location->withPath(
            $request->path()->resolve($location->path()),
        );
    }
}
