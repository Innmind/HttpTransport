<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Translator\Response\Psr7Translator,
    Factory\Header\Factories,
};
use GuzzleHttp\{
    ClientInterface,
    Client,
};
use Psr\Log\LoggerInterface;

function bootstrap(): array
{
    $translator = new Psr7Translator(Factories::default());

    return [
        'guzzle' => static function(ClientInterface $client = null) use ($translator): Transport {
            return new GuzzleTransport(
                $client ?? new Client,
                $translator
            );
        },
        'catch_guzzle_exceptions' => static function(Transport $transport) use ($translator): Transport {
            return new CatchGuzzleBadResponseExceptionTransport(
                $transport,
                $translator
            );
        },
        'logger' => static function(LoggerInterface $logger, string $level = null): callable {
            return static function(Transport $transport) use ($logger, $level): Transport {
                return new LoggerTransport(
                    $transport,
                    $logger,
                    $level
                );
            };
        },
        'throw_client' => static function(Transport $transport): Transport {
            return new ThrowOnClientErrorTransport($transport);
        },
        'throw_server' => static function(Transport $transport): Transport {
            return new ThrowOnServerErrorTransport($transport);
        },
    ];
}
