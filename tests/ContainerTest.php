<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    GuzzleTransport,
    LoggerTransport,
    ThrowOnServerErrorTransport
};
use Innmind\Compose\{
    ContainerBuilder\ContainerBuilder,
    Loader\Yaml
};
use Innmind\Url\Path;
use Innmind\Immutable\Map;
use GuzzleHttp\Client;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase
{
    public function testServices()
    {
        $container = (new ContainerBuilder(new Yaml))(
            new Path('container.yml'),
            (new Map('string', 'mixed'))
                ->put('client', new Client)
                ->put('logger', new NullLogger)
        );

        $this->assertInstanceOf(GuzzleTransport::class, $container->get('guzzle'));
        $this->assertInstanceOf(LoggerTransport::class, $container->get('conservative'));
        $this->assertInstanceOf(ThrowOnServerErrorTransport::class, $container->get('thrower'));
    }
}