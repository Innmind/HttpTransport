<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    ExponentialBackoffTransport,
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
use Innmind\Http\Message\{
    Request,
    Response,
    StatusCode,
};
use Innmind\TimeWarp\Halt;
use Innmind\TimeContinuum\Earth\Period\Millisecond;
use Innmind\Immutable\Either;
use PHPUnit\Framework\TestCase;

class ExponentialBackoffTransportTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Transport::class,
            ExponentialBackoffTransport::of(
                $this->createMock(Transport::class),
                $this->createMock(Halt::class),
            ),
        );
    }

    public function testDoesntRetryWhenInformationResponseOnFirstCall()
    {
        $fulfill = ExponentialBackoffTransport::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = $this->createMock(Request::class);
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::continue);
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
        $fulfill = ExponentialBackoffTransport::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = $this->createMock(Request::class);
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::ok);
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
        $fulfill = ExponentialBackoffTransport::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = $this->createMock(Request::class);
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::movedPermanently);
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
        $fulfill = ExponentialBackoffTransport::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = $this->createMock(Request::class);
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::notFound);
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
        $fulfill = ExponentialBackoffTransport::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = $this->createMock(Request::class);
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
        $fulfill = ExponentialBackoffTransport::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = $this->createMock(Request::class);
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
        $fulfill = ExponentialBackoffTransport::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = $this->createMock(Request::class);
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::internalServerError);
        $inner
            ->expects($this->exactly(12))
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new ServerError($request, $response)));
        $halt
            ->expects($this->exactly(10))
            ->method('__invoke')
            ->withConsecutive(
                [new Millisecond(100)],
                [new Millisecond(271)],
                [new Millisecond(738)],
                [new Millisecond(2008)],
                [new Millisecond(5459)],
                [new Millisecond(100)],
                [new Millisecond(271)],
                [new Millisecond(738)],
                [new Millisecond(2008)],
                [new Millisecond(5459)],
            );

        $this->assertEquals($expected, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertEquals($expected, $fulfill($request));
    }

    public function testRetryWhileThereIsStillAConnectionFailure()
    {
        $fulfill = ExponentialBackoffTransport::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = $this->createMock(Request::class);
        $inner
            ->expects($this->exactly(12))
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new ConnectionFailed($request, '')));
        $halt
            ->expects($this->exactly(10))
            ->method('__invoke')
            ->withConsecutive(
                [new Millisecond(100)],
                [new Millisecond(271)],
                [new Millisecond(738)],
                [new Millisecond(2008)],
                [new Millisecond(5459)],
                [new Millisecond(100)],
                [new Millisecond(271)],
                [new Millisecond(738)],
                [new Millisecond(2008)],
                [new Millisecond(5459)],
            );

        $this->assertEquals($expected, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertEquals($expected, $fulfill($request));
    }

    public function testStopRetryingWhenNoLongerReceivingAServerError()
    {
        $fulfill = ExponentialBackoffTransport::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = $this->createMock(Request::class);
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
        $fulfill = ExponentialBackoffTransport::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = $this->createMock(Request::class);
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::internalServerError);
        $inner
            ->expects($this->exactly(6))
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new ServerError($request, $response)));
        $halt
            ->expects($this->exactly(5))
            ->method('__invoke')
            ->withConsecutive(
                [new Millisecond(100)],
                [new Millisecond(271)],
                [new Millisecond(738)],
                [new Millisecond(2008)],
                [new Millisecond(5459)],
            );

        $this->assertEquals($expected, $fulfill($request));
    }
}
