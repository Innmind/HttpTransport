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
    Request,
    Response,
    Response\StatusCode,
    Method,
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
        $request = Request::of(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::ok,
            $request->protocolVersion(),
        );
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
        $request = Request::of(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::movedPermanently,
            $request->protocolVersion(),
        );
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
        $request = Request::of(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::notFound,
            $request->protocolVersion(),
        );
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
        $request = Request::of(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::internalServerError,
            $request->protocolVersion(),
        );
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
        $request = Request::of(
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
        $request1 = Request::of(
            Url::of('http://error.example.com/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $request2 = Request::of(
            Url::of('http://example.com/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response1 = Response::of(
            StatusCode::internalServerError,
            $request1->protocolVersion(),
        );
        $response2 = Response::of(
            StatusCode::ok,
            $request2->protocolVersion(),
        );
        $expected1 = Either::left(new ServerError($request1, $response1));
        $expected2 = Either::left(new Success($request2, $response2));
        $inner
            ->expects($matcher = $this->exactly(2))
            ->method('__invoke')
            ->willReturnCallback(function($request) use ($matcher, $request1, $request2, $expected1, $expected2) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertSame($request1, $request),
                    2 => $this->assertSame($request2, $request),
                };

                return match ($matcher->numberOfInvocations()) {
                    1 => $expected1,
                    2 => $expected2,
                };
            });

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
        $request = Request::of(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response1 = Response::of(
            StatusCode::internalServerError,
            $request->protocolVersion(),
        );
        $response2 = Response::of(
            StatusCode::ok,
            $request->protocolVersion(),
        );
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
