<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    DefaultTransport,
    CatchGuzzleBadResponseExceptionTransport,
    LoggerTransport,
    ThrowOnErrorTransport,
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
        $throw = $transports['throw_on_error'];

        $this->assertInstanceOf(DefaultTransport::class, $default());
        $this->assertInstanceOf(DefaultTransport::class, $default(
            $this->createMock(ClientInterface::class)
        ));
        $this->assertInternalType('callable', $log);
        $this->assertInstanceOf(LoggerTransport::class, $log($default()));
        $this->assertInternalType('callable', $throw);
        $this->assertInstanceOf(ThrowOnErrorTransport::class, $throw($default()));
    }
}
