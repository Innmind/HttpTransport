<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    DefaultTransport,
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
        $default = $transports['default'];
        $log = $transports['logger']($this->createMock(LoggerInterface::class));
        $throwClient = $transports['throw_client'];
        $throwServer = $transports['throw_server'];

        $this->assertInstanceOf(DefaultTransport::class, $default());
        $this->assertInstanceOf(DefaultTransport::class, $default(
            $this->createMock(ClientInterface::class)
        ));
        $this->assertInternalType('callable', $log);
        $this->assertInstanceOf(LoggerTransport::class, $log($default()));
        $this->assertInternalType('callable', $throwClient);
        $this->assertInstanceOf(ThrowOnClientErrorTransport::class, $throwClient($default()));
        $this->assertInternalType('callable', $throwServer);
        $this->assertInstanceOf(ThrowOnServerErrorTransport::class, $throwServer($default()));
    }
}
