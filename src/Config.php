<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\HttpTransport\Config\Async;
use Innmind\IO\IO;
use Innmind\Url\Url;
use Innmind\Time\{
    Clock,
    Halt,
};
use Innmind\Immutable\Maybe;

/**
 * @psalm-immutable
 */
final class Config
{
    /**
     * @param Maybe<int<1, max>> $maxConcurrency
     * @param Maybe<Url> $proxy
     * @param Maybe<Async> $async,
     */
    private function __construct(
        private Maybe $maxConcurrency,
        private bool $verifySSL,
        private Maybe $proxy,
        private Maybe $async,
    ) {
    }

    /**
     * @psalm-pure
     */
    public static function new(): self
    {
        /** @var Maybe<int<1, max>> */
        $maxConcurrency = Maybe::nothing();
        /** @var Maybe<Url> */
        $proxy = Maybe::nothing();
        /** @var Maybe<Async> */
        $async = Maybe::nothing();

        return new self(
            $maxConcurrency,
            true,
            $proxy,
            $async,
        );
    }

    /**
     * @param int<1, max> $max
     */
    #[\NoDiscard]
    public function limitConcurrencyTo(int $max): self
    {
        return new self(
            Maybe::just($max),
            $this->verifySSL,
            $this->proxy,
            $this->async,
        );
    }

    /**
     * You should use this method only when trying to call a server you own that
     * uses a self signed certificate that will fail the verification.
     */
    #[\NoDiscard]
    public function disableSSLVerification(): self
    {
        return new self(
            $this->maxConcurrency,
            false,
            $this->proxy,
            $this->async,
        );
    }

    #[\NoDiscard]
    public function throughProxy(Url $proxy): self
    {
        return new self(
            $this->maxConcurrency,
            $this->verifySSL,
            Maybe::just($proxy),
            $this->async,
        );
    }

    #[\NoDiscard]
    public function asAsync(
        Clock $clock,
        Halt $halt,
        IO $io,
    ): self {
        return new self(
            $this->maxConcurrency,
            $this->verifySSL,
            $this->proxy,
            Maybe::just(new Async(
                $clock,
                $halt,
                $io,
            )),
        );
    }

    /**
     * @internal
     *
     * @return Maybe<int<1, max>>
     */
    public function maxConcurrency(): Maybe
    {
        return $this->maxConcurrency;
    }

    /**
     * @internal
     */
    public function verifySSL(): bool
    {
        return $this->verifySSL;
    }

    /**
     * @internal
     *
     * @return Maybe<Url>
     */
    public function proxy(): Maybe
    {
        return $this->proxy;
    }

    /**
     * @internal
     *
     * @return Maybe<Async>
     */
    public function async(): Maybe
    {
        return $this->async;
    }
}
