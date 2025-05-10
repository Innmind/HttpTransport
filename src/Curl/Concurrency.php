<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Curl;

use Innmind\HttpTransport\{
    Transport,
    Failure,
    Success,
};
use Innmind\TimeContinuum\Period;
use Innmind\Immutable\{
    Sequence,
    Map,
    Either,
    Predicate\Instance,
};

/**
 * @internal
 * @psalm-import-type Errors from Transport
 */
final class Concurrency
{
    /** @var Sequence<\WeakReference<Scheduled>> */
    private Sequence $scheduled;
    /** @var \WeakMap<Scheduled, Either<Errors, Success>> */
    private \WeakMap $finished;
    /** @var ?positive-int */
    private ?int $maxConcurrency;

    /**
     * @psalm-mutation-free
     *
     * @param ?positive-int $maxConcurrency
     */
    private function __construct(?int $maxConcurrency = null)
    {
        $this->scheduled = Sequence::of();
        /** @var \WeakMap<Scheduled, Either<Errors, Success>> */
        $this->finished = new \WeakMap;
        $this->maxConcurrency = $maxConcurrency;
    }

    /**
     * @psalm-mutation-free
     *
     * @param ?positive-int $maxConcurrency
     */
    public static function new(?int $maxConcurrency = null): self
    {
        return new self($maxConcurrency);
    }

    public function add(Scheduled $scheduled): void
    {
        $this->scheduled = ($this->scheduled)(\WeakReference::create($scheduled));
    }

    /**
     * @param callable(): void $heartbeat
     */
    public function run(Period $timeout, callable $heartbeat): void
    {
        // remove dead references
        $stillScheduled = $this
            ->scheduled
            ->map(static fn($ref) => $ref->get())
            ->keep(Instance::of(Scheduled::class));

        // we loop over all the scheduled request to run them all by batches of
        // {maxConcurrency} so no matter the order in which the responses are
        // unwrapped we're sure all the responses are ready to be accessed
        // this prevents coalescing to a Failure in self::response() when the
        // user unwraps the last request first when maxConcurrency is lower than
        // the number of scheduled requests
        //
        // unwrapping all requests like this is the optimal approach because if
        // instead we only unwrapped up to the response the user is unwrapping
        // it would circumvent the concurrency because it would unwrap only one
        // response at a time unless the user unwraps the response out of order
        do {
            [$stillScheduled, $toStart] = match ($this->maxConcurrency) {
                null => [$stillScheduled->clear(), $stillScheduled],
                default => [
                    $stillScheduled->drop($this->maxConcurrency),
                    $stillScheduled->take($this->maxConcurrency),
                ],
            };

            if (!$toStart->empty()) {
                $this->batch($timeout, $heartbeat, $toStart);
            }
        } while (!$stillScheduled->empty());

        $this->scheduled = $this->scheduled->clear();
    }

    /**
     * @return Either<Errors, Success>
     */
    public function response(Scheduled $scheduled): Either
    {
        /** @var Either<Errors, Success> */
        return $this->finished[$scheduled] ?? Either::left(new Failure(
            $scheduled->request(),
            'Curl failed to execute the request',
        ));
    }

    /**
     * @param callable(): void $heartbeat
     * @param Sequence<Scheduled> $toStart
     */
    private function batch(
        Period $timeout,
        callable $heartbeat,
        Sequence $toStart,
    ): void {
        $multiHandle = \curl_multi_init();

        $started = $toStart->map(static fn($scheduled) => [
            $scheduled,
            $scheduled->start(),
        ]);
        $_ = $started->foreach(static function($pair) use ($multiHandle): void {
            [$scheduled, $either] = $pair;

            $_ = $either->match(
                static fn($ready) => \curl_multi_add_handle($multiHandle, $ready->handle()),
                static fn() => null, // failed to start, so there is nothing to do here
            );
        });

        do {
            $status = \curl_multi_exec($multiHandle, $stillActive);

            if ($stillActive) {
                $heartbeat();
                // Wait a short time for more activity
                \curl_multi_select($multiHandle, $timeout->seconds());
            }
        } while ($stillActive && $status === \CURLM_OK);

        /** @var Map<\CurlHandle, int> */
        $infos = Map::of();

        do {
            /** @var false|array{result: int, handle: \CurlHandle} */
            $info = \curl_multi_info_read($multiHandle);

            if (\is_array($info)) {
                $infos = ($infos)($info['handle'], $info['result']);
            }
        } while (\is_array($info));

        $finished = $started->map(static function($pair) use ($infos) {
            [$scheduled, $either] = $pair;

            return [
                $scheduled,
                $either->flatMap(
                    static fn($ready) => $infos
                        ->get($ready->handle())
                        ->either()
                        ->leftMap(static fn() => new Failure(
                            $ready->request(),
                            'Curl failed to execute the request',
                        ))
                        ->flatMap(static fn($errorCode) => $ready->read($errorCode)),
                ),
            ];
        });
        $_ = $finished->foreach(function($pair): void {
            [$scheduled, $result] = $pair;

            $this->finished[$scheduled] = $result;
        });
        $_ = $infos->foreach(static fn($handle) => \curl_multi_remove_handle(
            $multiHandle,
            $handle,
        ));
        \curl_multi_close($multiHandle);
    }
}
