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
};
use Innmind\Http\Message\{
    Request,
    Response,
    StatusCode,
};
use Innmind\TimeWarp\{
    Halt,
    PeriodToMilliseconds,
};
use Innmind\TimeContinuum\Period;
use Innmind\Immutable\Either;
use PHPUnit\Framework\TestCase;

class ExponentialBackoffTransportTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Transport::class,
            new ExponentialBackoffTransport(
                $this->createMock(Transport::class),
                $this->createMock(Halt::class),
                $this->createMock(Period::class),
            ),
        );
    }

    public function testDoesntRetryWhenSuccessfulResponseOnFirstCall()
    {
        $fulfill = new ExponentialBackoffTransport(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
            $this->createMock(Period::class),
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
        $fulfill = new ExponentialBackoffTransport(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
            $this->createMock(Period::class),
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
        $fulfill = new ExponentialBackoffTransport(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
            $this->createMock(Period::class),
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

    public function testRetryWhileThereIsStillAServerError()
    {
        $fulfill = new ExponentialBackoffTransport(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
            $period1 = $this->createMock(Period::class),
            $period2 = $this->createMock(Period::class),
            $period3 = $this->createMock(Period::class),
        );
        $request = $this->createMock(Request::class);
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::internalServerError);
        $inner
            ->expects($this->exactly(8))
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new ServerError($request, $response)));
        $halt
            ->expects($this->exactly(6))
            ->method('__invoke')
            ->withConsecutive(
                [$period1],
                [$period2],
                [$period3],
                [$period1],
                [$period2],
                [$period3],
            );

        $this->assertEquals($expected, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertEquals($expected, $fulfill($request));
    }

    public function testRetryWhileThereIsStillAConnectionFailure()
    {
        $fulfill = new ExponentialBackoffTransport(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
            $period1 = $this->createMock(Period::class),
            $period2 = $this->createMock(Period::class),
            $period3 = $this->createMock(Period::class),
        );
        $request = $this->createMock(Request::class);
        $inner
            ->expects($this->exactly(8))
            ->method('__invoke')
            ->with($request)
            ->willReturn($expected = Either::left(new ConnectionFailed($request, '')));
        $halt
            ->expects($this->exactly(6))
            ->method('__invoke')
            ->withConsecutive(
                [$period1],
                [$period2],
                [$period3],
                [$period1],
                [$period2],
                [$period3],
            );

        $this->assertEquals($expected, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertEquals($expected, $fulfill($request));
    }

    public function testStopRetryingWhenNoLongerReceivingAServerError()
    {
        $fulfill = new ExponentialBackoffTransport(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
            $period1 = $this->createMock(Period::class),
            $period2 = $this->createMock(Period::class),
            $period3 = $this->createMock(Period::class),
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
            ->with($period1);

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
                [
                    $this->callback(static function($period): bool {
                        return (new PeriodToMilliseconds)($period) === 100;
                    }),
                ],
                [
                    $this->callback(static function($period): bool {
                        return (new PeriodToMilliseconds)($period) === 271;
                    }),
                ],
                [
                    $this->callback(static function($period): bool {
                        return (new PeriodToMilliseconds)($period) === 738;
                    }),
                ],
                [
                    $this->callback(static function($period): bool {
                        return (new PeriodToMilliseconds)($period) === 2008;
                    }),
                ],
                [
                    $this->callback(static function($period): bool {
                        return (new PeriodToMilliseconds)($period) === 5459;
                    }),
                ],
            );

        $this->assertEquals($expected, $fulfill($request));
    }
}
