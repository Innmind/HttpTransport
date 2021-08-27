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
    Clock,
    Period,
};
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class BootstrapTest extends TestCase
{
    public function testBootstrap()
    {
        $transports = bootstrap($this->createMock(Clock::class));
        $default = $transports['default'];
        $log = $transports['logger']($this->createMock(LoggerInterface::class));
        $backoff = $transports['exponential_backoff'];
        $breaker = $transports['circuit_breaker'];

        $this->assertInstanceOf(DefaultTransport::class, $default());
        $this->assertInstanceOf(DefaultTransport::class, $default(
            $this->createMock(ClientInterface::class)
        ));
        $this->assertIsCallable($log);
        $this->assertInstanceOf(LoggerTransport::class, $log($default()));
        $this->assertIsCallable($backoff);
        $this->assertInstanceOf(
            ExponentialBackoffTransport::class,
            $backoff(
                $default(),
                $this->createMock(Halt::class),
                $this->createMock(Clock::class)
            )
        );
        $this->assertIsCallable($breaker);
        $this->assertInstanceOf(
            CircuitBreakerTransport::class,
            $breaker(
                $default(),
                $this->createMock(Clock::class),
                $this->createMock(Period::class)
            )
        );
    }
}
