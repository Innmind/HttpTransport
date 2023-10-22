<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    ExponentialBackoff,
    Transport,
    ServerError,
    Success,
    ClientError,
    Redirection,
    ConnectionFailed,
    Information,
    MalformedResponse,
    Failure,
};
use Innmind\Http\{
    Request,
    Response,
    Method,
    ProtocolVersion,
    Response\StatusCode,
};
use Innmind\TimeWarp\Halt;
use Innmind\TimeContinuum\Earth\Period\Millisecond;
use Innmind\Url\Url;
use Innmind\Immutable\Either;
use PHPUnit\Framework\TestCase;

class ExponentialBackoffTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Transport::class,
            ExponentialBackoff::of(
                $this->createMock(Transport::class),
                $this->createMock(Halt::class),
            ),
        );
    }

    public function testDoesntRetryWhenInformationResponseOnFirstCall()
    {
        $fulfill = ExponentialBackoff::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::continue,
            $request->protocolVersion(),
        );
        $inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new Information($request, $response)));
        $halt
            ->expects($this->never())
            ->method('__invoke');

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testDoesntRetryWhenSuccessfulResponseOnFirstCall()
    {
        $fulfill = ExponentialBackoff::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::ok,
            $request->protocolVersion(),
        );
        $inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::right(new Success($request, $response)));
        $halt
            ->expects($this->never())
            ->method('__invoke');

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testDoesntRetryWhenRedirectionResponseOnFirstCall()
    {
        $fulfill = ExponentialBackoff::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::movedPermanently,
            $request->protocolVersion(),
        );
        $inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new Redirection($request, $response)));
        $halt
            ->expects($this->never())
            ->method('__invoke');

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testDoesntRetryWhenClientErrorResponseOnFirstCall()
    {
        $fulfill = ExponentialBackoff::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::notFound,
            $request->protocolVersion(),
        );
        $inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new ClientError($request, $response)));
        $halt
            ->expects($this->never())
            ->method('__invoke');

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testDoesntRetryWhenMalformedResponseOnFirstCall()
    {
        $fulfill = ExponentialBackoff::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new MalformedResponse($request)));
        $halt
            ->expects($this->never())
            ->method('__invoke');

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testDoesntRetryWhenFailureOnFirstCall()
    {
        $fulfill = ExponentialBackoff::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new Failure($request, 'whatever')));
        $halt
            ->expects($this->never())
            ->method('__invoke');

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testRetryWhileThereIsStillAServerError()
    {
        $fulfill = ExponentialBackoff::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::internalServerError,
            $request->protocolVersion(),
        );
        $inner
            ->expects($this->exactly(12))
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new ServerError($request, $response)));
        $halt
            ->expects($matcher = $this->exactly(10))
            ->method('__invoke')
            ->willReturnCallback(function($period) use ($matcher) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new Millisecond(100), $period),
                    2 => $this->assertEquals(new Millisecond(271), $period),
                    3 => $this->assertEquals(new Millisecond(738), $period),
                    4 => $this->assertEquals(new Millisecond(2008), $period),
                    5 => $this->assertEquals(new Millisecond(5459), $period),
                    6 => $this->assertEquals(new Millisecond(100), $period),
                    7 => $this->assertEquals(new Millisecond(271), $period),
                    8 => $this->assertEquals(new Millisecond(738), $period),
                    9 => $this->assertEquals(new Millisecond(2008), $period),
                    10 => $this->assertEquals(new Millisecond(5459), $period),
                };
            });

        $this->assertEquals($expected, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertEquals($expected, $fulfill($request));
    }

    public function testRetryWhileThereIsStillAConnectionFailure()
    {
        $fulfill = ExponentialBackoff::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $inner
            ->expects($this->exactly(12))
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new ConnectionFailed($request, '')));
        $halt
            ->expects($matcher = $this->exactly(10))
            ->method('__invoke')
            ->willReturnCallback(function($period) use ($matcher) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new Millisecond(100), $period),
                    2 => $this->assertEquals(new Millisecond(271), $period),
                    3 => $this->assertEquals(new Millisecond(738), $period),
                    4 => $this->assertEquals(new Millisecond(2008), $period),
                    5 => $this->assertEquals(new Millisecond(5459), $period),
                    6 => $this->assertEquals(new Millisecond(100), $period),
                    7 => $this->assertEquals(new Millisecond(271), $period),
                    8 => $this->assertEquals(new Millisecond(738), $period),
                    9 => $this->assertEquals(new Millisecond(2008), $period),
                    10 => $this->assertEquals(new Millisecond(5459), $period),
                };
            });

        $this->assertEquals($expected, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertEquals($expected, $fulfill($request));
    }

    public function testStopRetryingWhenNoLongerReceivingAServerError()
    {
        $fulfill = ExponentialBackoff::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = Request::of(
            Url::of('/'),
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
                Either::left(new ServerError($request, $response1)),
                $expected = Either::right(new Success($request, $response2)),
            ));
        $halt
            ->expects($this->once())
            ->method('__invoke')
            ->with(new Millisecond(100));

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testByDefaultRetriesFiveTimesByUsingAPowerOfE()
    {
        $fulfill = ExponentialBackoff::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::internalServerError,
            $request->protocolVersion(),
        );
        $inner
            ->expects($this->exactly(6))
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new ServerError($request, $response)));
        $halt
            ->expects($matcher = $this->exactly(5))
            ->method('__invoke')
            ->willReturnCallback(function($period) use ($matcher) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertEquals(new Millisecond(100), $period),
                    2 => $this->assertEquals(new Millisecond(271), $period),
                    3 => $this->assertEquals(new Millisecond(738), $period),
                    4 => $this->assertEquals(new Millisecond(2008), $period),
                    5 => $this->assertEquals(new Millisecond(5459), $period),
                };
            });

        $this->assertEquals($expected, $fulfill($request));
    }
}
