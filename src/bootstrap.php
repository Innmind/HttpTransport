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
        'default' => static function(ClientInterface $client = null): Transport {
            return new DefaultTransport(
                $client ?? new Client,
                new Psr7Translator(Factories::default())
            );
        },
        'logger' => static function(LoggerInterface $logger): callable {
            return static function(Transport $transport) use ($logger): Transport {
                return new LoggerTransport(
                    $transport,
                    $logger
                );
            };
        },
        'throw_on_error' => static function(Transport $transport): Transport {
            return new ThrowOnErrorTransport($transport);
        },
    ];
}
