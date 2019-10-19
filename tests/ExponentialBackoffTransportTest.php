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
    StatusCode\StatusCode,
};
use Innmind\TimeWarp\{
    Halt,
    PeriodToMilliseconds,
};
use Innmind\TimeContinuum\{
    TimeContinuumInterface,
    PeriodInterface,
};
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
                $this->createMock(TimeContinuumInterface::class),
                $this->createMock(PeriodInterface::class)
            )
        );
    }

    public function testDoesntRetryWhenSuccessfulResponseOnFirstCall()
    {
        $fulfill = new ExponentialBackoffTransport(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
            $this->createMock(TimeContinuumInterface::class),
            $this->createMock(PeriodInterface::class)
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
            $this->createMock(TimeContinuumInterface::class),
            $this->createMock(PeriodInterface::class)
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
            $this->createMock(TimeContinuumInterface::class),
            $this->createMock(PeriodInterface::class)
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
            $clock = $this->createMock(TimeContinuumInterface::class),
            $period1 = $this->createMock(PeriodInterface::class),
            $period2 = $this->createMock(PeriodInterface::class),
            $period3 = $this->createMock(PeriodInterface::class)
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
                [$clock, $period1],
                [$clock, $period2],
                [$clock, $period3],
                [$clock, $period1],
                [$clock, $period2],
                [$clock, $period3]
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
            $clock = $this->createMock(TimeContinuumInterface::class),
            $period1 = $this->createMock(PeriodInterface::class),
            $period2 = $this->createMock(PeriodInterface::class),
            $period3 = $this->createMock(PeriodInterface::class)
        );
        $request = $this->createMock(Request::class);
        $inner
            ->expects($this->at(0))
            ->method('__invoke')
            ->with($request)
            ->willReturn($response = $this->createMock(Response::class));
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(500));
        $inner
            ->expects($this->at(1))
            ->method('__invoke')
            ->with($request)
            ->willReturn($response = $this->createMock(Response::class));
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(200));
        $halt
            ->expects($this->once())
            ->method('__invoke')
            ->with($clock, $period1);

        $this->assertSame($response, $fulfill($request));
    }

    public function testByDefaultRetriesFiveTimesByUsingAPowerOfE()
    {
        $fulfill = ExponentialBackoffTransport::of(
            $inner = $this->createMock(Transport::class),
            $halt = $this->createMock(Halt::class),
            $clock = $this->createMock(TimeContinuumInterface::class)
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
                    $clock,
                    $this->callback(static function($period): bool {
                        return (new PeriodToMilliseconds)($period) === 100;
                    }),
                ],
                [
                    $clock,
                    $this->callback(static function($period): bool {
                        return (new PeriodToMilliseconds)($period) === 271;
                    }),
                ],
                [
                    $clock,
                    $this->callback(static function($period): bool {
                        return (new PeriodToMilliseconds)($period) === 738;
                    }),
                ],
                [
                    $clock,
                    $this->callback(static function($period): bool {
                        return (new PeriodToMilliseconds)($period) === 2008;
                    }),
                ],
                [
                    $clock,
                    $this->callback(static function($period): bool {
                        return (new PeriodToMilliseconds)($period) === 5459;
                    }),
                ]
            );

        $this->assertSame($response, $fulfill($request));
    }
}
