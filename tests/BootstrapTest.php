<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    GuzzleTransport,
    CatchGuzzleBadResponseExceptionTransport,
    LoggerTransport,
    ThrowOnClientErrorTransport,
    ThrowOnServerErrorTransport,
};
use function Innmind\HttpTransport\bootstrap;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class BootstrapTest extends TestCase
{
    public function testBootstrap()
    {
        $transports = bootstrap();
        $guzzle = $transports['guzzle'];
        $catchExceptions = $transports['catch_guzzle_exceptions'];
        $log = $transports['logger']($this->createMock(LoggerInterface::class));
        $throwClient = $transports['throw_client'];
        $throwServer = $transports['throw_server'];

        $this->assertInstanceOf(GuzzleTransport::class, $guzzle());
        $this->assertInstanceOf(GuzzleTransport::class, $guzzle(
            $this->createMock(ClientInterface::class)
        ));
        $this->assertInstanceOf(
            CatchGuzzleBadResponseExceptionTransport::class,
            $catchExceptions($guzzle())
        );
        $this->assertInternalType('callable', $log);
        $this->assertInstanceOf(LoggerTransport::class, $log($guzzle()));
        $this->assertInternalType('callable', $throwClient);
        $this->assertInstanceOf(ThrowOnClientErrorTransport::class, $throwClient($guzzle()));
        $this->assertInternalType('callable', $throwServer);
        $this->assertInstanceOf(ThrowOnServerErrorTransport::class, $throwServer($guzzle()));
    }
}
