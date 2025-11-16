<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Url\Url;
use Innmind\Immutable\Maybe;

/**
 * @psalm-immutable
 */
final class Config
{
    /**
     * @param Maybe<int<1, max>> $maxConcurrency
     * @param Maybe<Url> $proxy
     */
    private function __construct(
        private Maybe $maxConcurrency,
        private bool $verifySSL,
        private Maybe $proxy,
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

        return new self(
            $maxConcurrency,
            true,
            $proxy,
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
        );
    }

    #[\NoDiscard]
    public function disableSSLVerification(): self
    {
        return new self(
            $this->maxConcurrency,
            false,
            $this->proxy,
        );
    }

    #[\NoDiscard]
    public function throughProxy(Url $proxy): self
    {
        return new self(
            $this->maxConcurrency,
            $this->verifySSL,
            Maybe::just($proxy),
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
}
