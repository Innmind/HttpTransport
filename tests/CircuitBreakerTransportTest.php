<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    CircuitBreakerTransport,
    Transport,
};
use Innmind\Http\{
    Message\Request,
    Message\Response,
    Message\StatusCode\StatusCode,
};
use Innmind\Url\Url;
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    PeriodInterface,
    PointInTimeInterface,
};
use PHPUnit\Framework\TestCase;

class CircuitBreakerTransportTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Transport::class,
            new CircuitBreakerTransport(
                $this->createMock(Transport::class),
                $this->createMock(TimeContinuumInterface::class),
                $this->createMock(PeriodInterface::class)
            )
        );
    }

    public function testDoesntCloseCircuitOnSuccessfulResponse()
    {
        $fulfill = new CircuitBreakerTransport(
            $inner = $this->createMock(Transport::class),
            $this->createMock(TimeContinuumInterface::class),
            $this->createMock(PeriodInterface::class)
        );
        $request = $this->createMock(Request::class);
        $inner
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->with($request)
            ->willReturn($response = $this->createMock(Response::class));
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(200));

        $this->assertSame($response, $fulfill($request));
        $this->assertSame($response, $fulfill($request));
    }

    public function testDoesntCloseCircuitOnRedirectionResponse()
    {
        $fulfill = new CircuitBreakerTransport(
            $inner = $this->createMock(Transport::class),
            $this->createMock(TimeContinuumInterface::class),
            $this->createMock(PeriodInterface::class)
        );
        $request = $this->createMock(Request::class);
        $inner
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->with($request)
            ->willReturn($response = $this->createMock(Response::class));
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(301));

        $this->assertSame($response, $fulfill($request));
        $this->assertSame($response, $fulfill($request));
    }

    public function testDoesntCloseCircuitOnClientErrorResponse()
    {
        $fulfill = new CircuitBreakerTransport(
            $inner = $this->createMock(Transport::class),
            $this->createMock(TimeContinuumInterface::class),
            $this->createMock(PeriodInterface::class)
        );
        $request = $this->createMock(Request::class);
        $inner
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->with($request)
            ->willReturn($response = $this->createMock(Response::class));
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(404));

        $this->assertSame($response, $fulfill($request));
        $this->assertSame($response, $fulfill($request));
    }

    public function testCloseCircuit()
    {
        $fulfill = new CircuitBreakerTransport(
            $inner = $this->createMock(Transport::class),
            $clock = $this->createMock(TimeContinuumInterface::class),
            $delay = $this->createMock(PeriodInterface::class)
        );
        $request = $this->createMock(Request::class);
        $inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn($response = $this->createMock(Response::class));
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(500));
        $clock
            ->expects($this->at(0))
            ->method('now')
            ->willReturn($closingTime = $this->createMock(PointInTimeInterface::class));
        $clock
            ->expects($this->at(1))
            ->method('now')
            ->willReturn($secondCallTime = $this->createMock(PointInTimeInterface::class));
        $closingTime
            ->expects($this->once())
            ->method('goForward')
            ->with($delay)
            ->willReturn($reopeningTime = $this->createMock(PointInTimeInterface::class));
        $reopeningTime
            ->expects($this->once())
            ->method('aheadOf')
            ->with($secondCallTime)
            ->willReturn(true);

        $this->assertSame($response, $fulfill($request));
        $defaultResponse = $fulfill($request);
        $this->assertNotSame($response, $defaultResponse);
        $this->assertSame(503, $defaultResponse->statusCode()->value());
    }

    public function testCloseCircuitOnlyForTheDomainThatFailed()
    {
        $fulfill = new CircuitBreakerTransport(
            $inner = $this->createMock(Transport::class),
            $this->createMock(TimeContinuumInterface::class),
            $this->createMock(PeriodInterface::class)
        );
        $request1 = $this->createMock(Request::class);
        $request2 = $this->createMock(Request::class);
        $request1
            ->expects($this->any())
            ->method('url')
            ->willReturn(Url::fromString('http://error.example.com/'));
        $request2
            ->expects($this->any())
            ->method('url')
            ->willReturn(Url::fromString('http://example.com/'));
        $inner
            ->expects($this->at(0))
            ->method('__invoke')
            ->with($request1)
            ->willReturn($response1 = $this->createMock(Response::class));
        $response1
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(500));
        $inner
            ->expects($this->at(1))
            ->method('__invoke')
            ->with($request2)
            ->willReturn($response2 = $this->createMock(Response::class));
        $response2
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(200));

        $this->assertSame($response1, $fulfill($request1));
        $this->assertSame($response2, $fulfill($request2));
    }

    public function testReopenTheCircuitAfterTheSpecifiedDelay()
    {
        $fulfill = new CircuitBreakerTransport(
            $inner = $this->createMock(Transport::class),
            $clock = $this->createMock(TimeContinuumInterface::class),
            $delay = $this->createMock(PeriodInterface::class)
        );
        $request = $this->createMock(Request::class);
        $inner
            ->expects($this->at(0))
            ->method('__invoke')
            ->with($request)
            ->willReturn($response1 = $this->createMock(Response::class));
        $response1
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(500));
        $inner
            ->expects($this->at(1))
            ->method('__invoke')
            ->with($request)
            ->willReturn($response2 = $this->createMock(Response::class));
        $response2
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(200));
        $clock
            ->expects($this->at(0))
            ->method('now')
            ->willReturn($closingTime = $this->createMock(PointInTimeInterface::class));
        $clock
            ->expects($this->at(1))
            ->method('now')
            ->willReturn($secondCallTime = $this->createMock(PointInTimeInterface::class));
        $closingTime
            ->expects($this->once())
            ->method('goForward')
            ->with($delay)
            ->willReturn($reopeningTime = $this->createMock(PointInTimeInterface::class));
        $reopeningTime
            ->expects($this->once())
            ->method('aheadOf')
            ->with($secondCallTime)
            ->willReturn(false);

        $this->assertSame($response1, $fulfill($request));
        $this->assertSame($response2, $fulfill($request));
    }
}
