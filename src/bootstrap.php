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
    return [
        'guzzle' => static function(ClientInterface $client = null): Transport {
            return new GuzzleTransport(
                $client ?? new Client,
                new Psr7Translator(Factories::default())
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
