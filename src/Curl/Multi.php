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

    private function __construct()
    {
        $this->scheduled = Sequence::of();
        /** @var \WeakMap<Scheduled, Either<Errors, Success>> */
        $this->finished = new \WeakMap;
    }

    public static function new(): self
    {
        return new self;
    }

    public function add(Scheduled $scheduled): void
    {
        $this->scheduled = ($this->scheduled)(\WeakReference::create($scheduled));
    }

    public function exec(): void
    {
        $toStart = $this
            ->scheduled
            ->map(static fn($ref) => $ref->get())
            ->keep(Instance::of(Scheduled::class));
        $this->scheduled = $this->scheduled->clear();

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
