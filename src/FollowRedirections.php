<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Message\Request,
    Message\Method,
    Message\StatusCode,
    Header\Location,
};
use Innmind\Immutable\{
    Either,
    Sequence,
};

/**
 * @psalm-import-type Errors from Transport
 */
final class FollowRedirections implements Transport
{
    private Transport $fulfill;
    /** @var Sequence<int> */
    private Sequence $hops;

    /**
     * @param positive-int $maxHops
     */
    private function __construct(Transport $fulfill, int $maxHops)
    {
        $this->fulfill = $fulfill;
        $this->hops = Sequence::of(...\range(1, $maxHops));
    }

    public function __invoke(Request $request): Either
    {
        /**
         * @psalm-suppress MixedArgument
         * @var Either<Errors, Success>
         */
        return $this->hops->reduce(
            ($this->fulfill)($request),
            fn(Either $success) => $success->otherwise(
                fn($error) => $this->maybeRedirect($error),
            ),
        );
    }

    public static function of(Transport $fulfill): self
    {
        return new self($fulfill, 5);
    }

    /**
     * @param Errors $error
     *
     * @return Either<Errors, Success>
     */
    private function maybeRedirect(object $error): Either
    {
        /** @var Either<Errors, Success> */
        return match (true) {
            $error instanceof Redirection => $this->handle($error),
            default => Either::left($error),
        };
    }

    /**
     * @return Either<Errors, Success>
     */
    private function handle(Redirection $redirection): Either
    {
        /** @var Either<Errors, Success> */
        return match ($redirection->response()->statusCode()) {
            StatusCode::movedPermanently => $this->redirect($redirection),
            StatusCode::found => $this->redirect($redirection),
            StatusCode::seeOther => $this->seeOther($redirection),
            StatusCode::temporaryRedirect => $this->redirect($redirection),
            StatusCode::permanentlyRedirect => $this->redirect($redirection),
            default => Either::left($redirection), // some redirections cannot be applied
        };
    }

    /**
     * @return Either<Errors, Success>
     */
    private function redirect(Redirection $redirection): Either
    {
        /** @var Either<Errors, Success> */
        return $redirection
            ->response()
            ->headers()
            ->find(Location::class)
            ->map(static fn($header) => $header->url())
            ->map(static fn($url) => new Request\Request(
                $url,
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
                fn($request) => ($this->fulfill)($request),
                static fn() => Either::left($redirection),
            );
    }

    /**
     * @return Either<Errors, Success>
     */
    private function seeOther(Redirection $redirection): Either
    {
        /** @var Either<Errors, Success> */
        return $redirection
            ->response()
            ->headers()
            ->find(Location::class)
            ->map(static fn($header) => $header->url())
            ->map(static fn($url) => new Request\Request(
                $url,
                Method::get,
                $redirection->request()->protocolVersion(),
                $redirection->request()->headers(),
            ))
            ->match(
                fn($request) => ($this->fulfill)($request),
                static fn() => Either::left($redirection),
            );
    }
}
