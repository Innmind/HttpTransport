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
    Message\StatusCode,
    Message\Method,
    ProtocolVersion,
};
use Innmind\Url\Url;
use Innmind\TimeContinuum\{
    Clock,
    Period,
    PointInTime,
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
                $this->createMock(Clock::class),
                $this->createMock(Period::class)
            )
        );
    }

    public function testDoesntOpenCircuitOnSuccessfulResponse()
    {
        $fulfill = new CircuitBreakerTransport(
            $inner = $this->createMock(Transport::class),
            $this->createMock(Clock::class),
            $this->createMock(Period::class)
        );
        $request = new Request\Request(
            Url::of('http://example.com'),
            Method::get(),
            new ProtocolVersion(1, 1),
        );
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

    public function testDoesntOpenCircuitOnRedirectionResponse()
    {
        $fulfill = new CircuitBreakerTransport(
            $inner = $this->createMock(Transport::class),
            $this->createMock(Clock::class),
            $this->createMock(Period::class)
        );
        $request = new Request\Request(
            Url::of('http://example.com'),
            Method::get(),
            new ProtocolVersion(1, 1),
        );
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

    public function testDoesntOpenCircuitOnClientErrorResponse()
    {
        $fulfill = new CircuitBreakerTransport(
            $inner = $this->createMock(Transport::class),
            $this->createMock(Clock::class),
            $this->createMock(Period::class)
        );
        $request = new Request\Request(
            Url::of('http://example.com'),
            Method::get(),
            new ProtocolVersion(1, 1),
        );
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

    public function testOpenCircuit()
    {
        $fulfill = new CircuitBreakerTransport(
            $inner = $this->createMock(Transport::class),
            $clock = $this->createMock(Clock::class),
            $delay = $this->createMock(Period::class)
        );
        $request = new Request\Request(
            Url::of('http://example.com'),
            Method::get(),
            new ProtocolVersion(1, 1),
        );
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
            ->expects($this->exactly(2))
            ->method('now')
            ->will($this->onConsecutiveCalls(
                $openingTime = $this->createMock(PointInTime::class),
                $secondCallTime = $this->createMock(PointInTime::class)
            ));
        $openingTime
            ->expects($this->once())
            ->method('goForward')
            ->with($delay)
            ->willReturn($reclosingTime = $this->createMock(PointInTime::class));
        $reclosingTime
            ->expects($this->once())
            ->method('aheadOf')
            ->with($secondCallTime)
            ->willReturn(true);

        $this->assertSame($response, $fulfill($request));
        $defaultResponse = $fulfill($request);
        $this->assertNotSame($response, $defaultResponse);
        $this->assertSame(503, $defaultResponse->statusCode()->value());
    }

    public function testOpenCircuitOnlyForTheDomainThatFailed()
    {
        $fulfill = new CircuitBreakerTransport(
            $inner = $this->createMock(Transport::class),
            $this->createMock(Clock::class),
            $this->createMock(Period::class)
        );
        $request1 = $this->createMock(Request::class);
        $request2 = $this->createMock(Request::class);
        $request1
            ->expects($this->any())
            ->method('url')
            ->willReturn(Url::of('http://error.example.com/'));
        $request2
            ->expects($this->any())
            ->method('url')
            ->willReturn(Url::of('http://example.com/'));
        $inner
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->withConsecutive([$request1], [$request2])
            ->will($this->onConsecutiveCalls(
                $response1 = $this->createMock(Response::class),
                $response2 = $this->createMock(Response::class)
            ));
        $response1
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(500));
        $response2
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(200));

        $this->assertSame($response1, $fulfill($request1));
        $this->assertSame($response2, $fulfill($request2));
    }

    public function testRecloseTheCircuitAfterTheSpecifiedDelay()
    {
        $fulfill = new CircuitBreakerTransport(
            $inner = $this->createMock(Transport::class),
            $clock = $this->createMock(Clock::class),
            $delay = $this->createMock(Period::class)
        );
        $request = new Request\Request(
            Url::of('http://example.com'),
            Method::get(),
            new ProtocolVersion(1, 1),
        );
        $inner
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->with($request)
            ->will($this->onConsecutiveCalls(
                $response1 = $this->createMock(Response::class),
                $response2 = $this->createMock(Response::class)
            ));
        $response1
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(500));
        $response2
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(200));
        $clock
            ->expects($this->exactly(2))
            ->method('now')
            ->will($this->onConsecutiveCalls(
                $openingTime = $this->createMock(PointInTime::class),
                $secondCallTime = $this->createMock(PointInTime::class),
            ));
        $openingTime
            ->expects($this->once())
            ->method('goForward')
            ->with($delay)
            ->willReturn($reclosingTime = $this->createMock(PointInTime::class));
        $reclosingTime
            ->expects($this->once())
            ->method('aheadOf')
            ->with($secondCallTime)
            ->willReturn(false);

        $this->assertSame($response1, $fulfill($request));
        $this->assertSame($response2, $fulfill($request));
    }
}
