<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    CircuitBreaker,
    Transport,
    Success,
    ServerError,
    ClientError,
    Redirection,
    ConnectionFailed,
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
use Innmind\Immutable\Either;
use PHPUnit\Framework\TestCase;

class CircuitBreakerTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Transport::class,
            CircuitBreaker::of(
                $this->createMock(Transport::class),
                $this->createMock(Clock::class),
                $this->createMock(Period::class),
            ),
        );
    }

    public function testDoesntOpenCircuitOnSuccessfulResponse()
    {
        $fulfill = CircuitBreaker::of(
            $inner = $this->createMock(Transport::class),
            $this->createMock(Clock::class),
            $this->createMock(Period::class),
        );
        $request = new Request\Request(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::ok);
        $inner
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::right(new Success($request, $response)));

        $this->assertEquals($expected, $fulfill($request));
        $this->assertEquals($expected, $fulfill($request));
    }

    public function testDoesntOpenCircuitOnRedirectionResponse()
    {
        $fulfill = CircuitBreaker::of(
            $inner = $this->createMock(Transport::class),
            $this->createMock(Clock::class),
            $this->createMock(Period::class),
        );
        $request = new Request\Request(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::movedPermanently);
        $inner
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new Redirection($request, $response)));

        $this->assertEquals($expected, $fulfill($request));
        $this->assertEquals($expected, $fulfill($request));
    }

    public function testDoesntOpenCircuitOnClientErrorResponse()
    {
        $fulfill = CircuitBreaker::of(
            $inner = $this->createMock(Transport::class),
            $this->createMock(Clock::class),
            $this->createMock(Period::class),
        );
        $request = new Request\Request(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::notFound);
        $inner
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new ClientError($request, $response)));

        $this->assertEquals($expected, $fulfill($request));
        $this->assertEquals($expected, $fulfill($request));
    }

    public function testOpenCircuitOnServerError()
    {
        $fulfill = CircuitBreaker::of(
            $inner = $this->createMock(Transport::class),
            $clock = $this->createMock(Clock::class),
            $delay = $this->createMock(Period::class),
        );
        $request = new Request\Request(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::internalServerError);
        $inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new ServerError($request, $response)));
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
            ->willReturn(true);

        $this->assertEquals($expected, $fulfill($request));
        $defaultResponse = $fulfill($request);
        $this->assertNotEquals($expected, $defaultResponse);
        $this->assertSame(503, $defaultResponse->match(
            static fn() => null,
            static fn($error) => $error->response()->statusCode()->toInt(),
        ));
    }

    public function testOpenCircuitOnConnectionFailure()
    {
        $fulfill = CircuitBreaker::of(
            $inner = $this->createMock(Transport::class),
            $clock = $this->createMock(Clock::class),
            $delay = $this->createMock(Period::class),
        );
        $request = new Request\Request(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new ConnectionFailed($request, '')));
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
            ->willReturn(true);

        $this->assertEquals($expected, $fulfill($request));
        $defaultResponse = $fulfill($request);
        $this->assertNotEquals($expected, $defaultResponse);
        $this->assertSame(503, $defaultResponse->match(
            static fn() => null,
            static fn($error) => $error->response()->statusCode()->toInt(),
        ));
    }

    public function testOpenCircuitOnlyForTheDomainThatFailed()
    {
        $fulfill = CircuitBreaker::of(
            $inner = $this->createMock(Transport::class),
            $this->createMock(Clock::class),
            $this->createMock(Period::class),
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
        $response1 = $this->createMock(Response::class);
        $response2 = $this->createMock(Response::class);
        $response1
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::internalServerError);
        $response2
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::ok);
        $inner
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->withConsecutive([$request1], [$request2])
            ->will($this->onConsecutiveCalls(
                $expected1 = Either::left(new ServerError($request1, $response1)),
                $expected2 = Either::left(new Success($request2, $response2)),
            ));

        $this->assertEquals($expected1, $fulfill($request1));
        $this->assertEquals($expected2, $fulfill($request2));
    }

    public function testRecloseTheCircuitAfterTheSpecifiedDelay()
    {
        $fulfill = CircuitBreaker::of(
            $inner = $this->createMock(Transport::class),
            $clock = $this->createMock(Clock::class),
            $delay = $this->createMock(Period::class),
        );
        $request = new Request\Request(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response1 = $this->createMock(Response::class);
        $response2 = $this->createMock(Response::class);
        $response1
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::internalServerError);
        $response2
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::ok);
        $inner
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->with($request)
            ->will($this->onConsecutiveCalls(
                $expected1 = Either::left(new ServerError($request, $response1)),
                $expected2 = Either::right(new Success($request, $response2)),
            ));
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

        $this->assertEquals($expected1, $fulfill($request));
        $this->assertEquals($expected2, $fulfill($request));
    }
}