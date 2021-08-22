<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    ExponentialBackoffTransport,
    Transport,
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
            )
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
        $inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn($response = $this->createMock(Response::class));
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(200));
        $halt
            ->expects($this->never())
            ->method('__invoke');

        $this->assertSame($response, $fulfill($request));
    }

    public function testDoesntRetryWhenRedirectionResponseOnFirstCall()
    {
        $fulfill = new ExponentialBackoffTransport(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
            $this->createMock(Period::class),
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
            ->willReturn(new StatusCode(301));
        $halt
            ->expects($this->never())
            ->method('__invoke');

        $this->assertSame($response, $fulfill($request));
    }

    public function testDoesntRetryWhenClientErrorResponseOnFirstCall()
    {
        $fulfill = new ExponentialBackoffTransport(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
            $this->createMock(Period::class),
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
            ->willReturn(new StatusCode(404));
        $halt
            ->expects($this->never())
            ->method('__invoke');

        $this->assertSame($response, $fulfill($request));
    }

    public function testRetryWhileThereIsStill()
    {
        $fulfill = new ExponentialBackoffTransport(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
            $period1 = $this->createMock(Period::class),
            $period2 = $this->createMock(Period::class),
            $period3 = $this->createMock(Period::class)
        );
        $request = $this->createMock(Request::class);
        $inner
            ->expects($this->exactly(8))
            ->method('__invoke')
            ->with($request)
            ->willReturn($response = $this->createMock(Response::class));
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(500));
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

        $this->assertSame($response, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertSame($response, $fulfill($request));
    }

    public function testStopRetryingWhenNoLongerReceivingAServerError()
    {
        $fulfill = new ExponentialBackoffTransport(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
            $period1 = $this->createMock(Period::class),
            $period2 = $this->createMock(Period::class),
            $period3 = $this->createMock(Period::class)
        );
        $request = $this->createMock(Request::class);
        $inner
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->with($request)
            ->will($this->onConsecutiveCalls(
                $response1 = $this->createMock(Response::class),
                $response2 = $this->createMock(Response::class),
            ));
        $response1
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(500));
        $response2
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(200));
        $halt
            ->expects($this->once())
            ->method('__invoke')
            ->with($period1);

        $this->assertSame($response2, $fulfill($request));
    }

    public function testByDefaultRetriesFiveTimesByUsingAPowerOfE()
    {
        $fulfill = ExponentialBackoffTransport::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
        );
        $request = $this->createMock(Request::class);
        $inner
            ->expects($this->exactly(6))
            ->method('__invoke')
            ->with($request)
            ->willReturn($response = $this->createMock(Response::class));
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(500));
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
                ]
            );

        $this->assertSame($response, $fulfill($request));
    }
}
