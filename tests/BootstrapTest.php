<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    DefaultTransport,
    LoggerTransport,
    ThrowOnErrorTransport,
    ExponentialBackoffTransport,
    CircuitBreakerTransport,
};
use function Innmind\HttpTransport\bootstrap;
use Innmind\TimeWarp\Halt;
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    PeriodInterface,
};
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
        $backoff = $transports['exponential_backoff'];
        $breaker = $transports['circuit_breaker'];

        $this->assertInstanceOf(DefaultTransport::class, $default());
        $this->assertInstanceOf(DefaultTransport::class, $default(
            $this->createMock(ClientInterface::class)
        ));
        $this->assertInternalType('callable', $log);
        $this->assertInstanceOf(LoggerTransport::class, $log($default()));
        $this->assertInternalType('callable', $throw);
        $this->assertInstanceOf(ThrowOnErrorTransport::class, $throw($default()));
        $this->assertInternalType('callable', $backoff);
        $this->assertInstanceOf(
            ExponentialBackoffTransport::class,
            $backoff(
                $default(),
                $this->createMock(Halt::class),
                $this->createMock(TimeContinuumInterface::class)
            )
        );
        $this->assertInternalType('callable', $breaker);
        $this->assertInstanceOf(
            CircuitBreakerTransport::class,
            $breaker(
                $default(),
                $this->createMock(TimeContinuumInterface::class),
                $this->createMock(PeriodInterface::class)
            )
        );
    }
}
