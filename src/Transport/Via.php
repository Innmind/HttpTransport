<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Transport;

use Innmind\HttpTransport\Success;
use Innmind\Http\Request;
use Innmind\HttpTransport\Config;
use Innmind\Immutable\Either;

/**
 * @internal
 * @psalm-import-type Errors from Implementation
 */
final class Via implements Implementation
{
    /**
     * @param \Closure(Request): Either<Errors, Success> $fulfill
     */
    private function __construct(
        private \Closure $fulfill,
        private ?Config $config,
    ) {
    }

    #[\Override]
    public function __invoke(Request $request): Either
    {
        return ($this->fulfill)($request);
    }

    /**
     * @param callable(Request): Either<Errors, Success> $fulfill
     */
    public static function of(callable $fulfill): self
    {
        // todo support exposing a Config to the callable ?
        return new self(\Closure::fromCallable($fulfill), null);
    }

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    public function map(callable $map): self
    {
        /** @psalm-suppress ImpureFunctionCall */
        return new self(
            $this->fulfill,
            $map($this->config()),
        );
    }

    /**
     * @psalm-mutation-free
     */
    #[\Override]
    public function config(): Config
    {
        return $this->config ?? Config::new();
    }
}
