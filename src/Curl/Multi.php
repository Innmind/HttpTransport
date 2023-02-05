<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport\Curl;

use Innmind\HttpTransport\{
    Transport,
    Failure,
    Success,
};
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
final class Multi
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
    private function __construct(int $maxConcurrency = null)
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
    public static function new(int $maxConcurrency = null): self
    {
        return new self($maxConcurrency);
    }

    public function add(Scheduled $scheduled): void
    {
        $this->scheduled = ($this->scheduled)(\WeakReference::create($scheduled));
    }

    public function exec(): void
    {
        // remove dead references
        $stillScheduled = $this
            ->scheduled
            ->map(static fn($ref) => $ref->get())
            ->keep(Instance::of(Scheduled::class));
        // there is no loop here because the behaviour is that the first request
        // that is unwrapped will trigger the first batch, the second request
        // unwrap will trigger the second batch and so on
        // for example if you have 10 concurrent request with a max concurrency
        // of 5 all requests will be done when the second request is unwrapped
        [$this->scheduled, $toStart] = match ($this->maxConcurrency) {
            null => [$this->scheduled->clear(), $stillScheduled],
            default => [
                $stillScheduled
                    ->drop($this->maxConcurrency)
                    ->map(static fn($scheduled) => \WeakReference::create($scheduled)),
                $stillScheduled->take($this->maxConcurrency),
            ],
        };

        if ($toStart->empty()) {
            return;
        }

        $this->batch($toStart);
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
     * @param Sequence<Scheduled> $toStart
     */
    private function batch(Sequence $toStart): void
    {
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
                // Wait a short time for more activity
                \curl_multi_select($multiHandle);
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
    }
}
