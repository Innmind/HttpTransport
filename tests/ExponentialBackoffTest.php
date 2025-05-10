<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    ExponentialBackoff,
    Curl,
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
use Innmind\TimeContinuum\{
    Clock,
    Period,
};
use Innmind\Url\Url;
use Innmind\Immutable\{
    Either,
    Attempt,
    SideEffect,
};
use PHPUnit\Framework\TestCase;

class ExponentialBackoffTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Transport::class,
            ExponentialBackoff::of(
                Curl::of(Clock::live()),
                Halt\Usleep::new(),
            ),
        );
    }

    public function testDoesntRetryWhenInformationResponseOnFirstCall()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::continue,
            $request->protocolVersion(),
        );
        $expected = Either::left(new Information($request, $response));

        $fulfill = ExponentialBackoff::of(
            new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                ) {
                }

                public function __invoke(Request $request): Either
                {
                    return $this->expected;
                }
            },
            new class implements Halt {
                public function __invoke(Period $period): Attempt
                {
                    return Attempt::error(new \Exception);
                }
            },
        );

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testDoesntRetryWhenSuccessfulResponseOnFirstCall()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::ok,
            $request->protocolVersion(),
        );
        $expected = Either::right(new Success($request, $response));

        $fulfill = ExponentialBackoff::of(
            new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                ) {
                }

                public function __invoke(Request $request): Either
                {
                    return $this->expected;
                }
            },
            new class implements Halt {
                public function __invoke(Period $period): Attempt
                {
                    return Attempt::error(new \Exception);
                }
            },
        );

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testDoesntRetryWhenRedirectionResponseOnFirstCall()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::movedPermanently,
            $request->protocolVersion(),
        );
        $expected = Either::left(new Redirection($request, $response));

        $fulfill = ExponentialBackoff::of(
            new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                ) {
                }

                public function __invoke(Request $request): Either
                {
                    return $this->expected;
                }
            },
            new class implements Halt {
                public function __invoke(Period $period): Attempt
                {
                    return Attempt::error(new \Exception);
                }
            },
        );

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testDoesntRetryWhenClientErrorResponseOnFirstCall()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::notFound,
            $request->protocolVersion(),
        );
        $expected = Either::left(new ClientError($request, $response));

        $fulfill = ExponentialBackoff::of(
            new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                ) {
                }

                public function __invoke(Request $request): Either
                {
                    return $this->expected;
                }
            },
            new class implements Halt {
                public function __invoke(Period $period): Attempt
                {
                    return Attempt::error(new \Exception);
                }
            },
        );

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testDoesntRetryWhenMalformedResponseOnFirstCall()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $expected = Either::left(new MalformedResponse($request));

        $fulfill = ExponentialBackoff::of(
            new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                ) {
                }

                public function __invoke(Request $request): Either
                {
                    return $this->expected;
                }
            },
            new class implements Halt {
                public function __invoke(Period $period): Attempt
                {
                    return Attempt::error(new \Exception);
                }
            },
        );

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testDoesntRetryWhenFailureOnFirstCall()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $expected = Either::left(new Failure($request, 'whatever'));

        $fulfill = ExponentialBackoff::of(
            new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                ) {
                }

                public function __invoke(Request $request): Either
                {
                    return $this->expected;
                }
            },
            new class implements Halt {
                public function __invoke(Period $period): Attempt
                {
                    return Attempt::error(new \Exception);
                }
            },
        );

        $this->assertEquals($expected, $fulfill($request));
    }

    public function testRetryWhileThereIsStillATooManyRequestsError()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::tooManyRequests,
            $request->protocolVersion(),
        );
        $expected = Either::left(new ClientError($request, $response));

        $fulfill = ExponentialBackoff::of(
            $inner = new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                    public int $calls = 0,
                ) {
                }

                public function __invoke(Request $request): Either
                {
                    ++$this->calls;

                    return $this->expected;
                }
            },
            new class($this) implements Halt {
                public function __construct(
                    private $test,
                    private int $calls = 0,
                ) {
                }

                public function __invoke(Period $period): Attempt
                {
                    ++$this->calls;

                    match ($this->calls) {
                        1, 6 => $this->test->assertEquals(Period::millisecond(100), $period),
                        2, 7 => $this->test->assertEquals(Period::millisecond(271), $period),
                        3, 8 => $this->test->assertEquals(Period::millisecond(738), $period),
                        4, 9 => $this->test->assertEquals(Period::millisecond(2008), $period),
                        5, 10 => $this->test->assertEquals(Period::millisecond(5459), $period),
                    };

                    return Attempt::result(SideEffect::identity());
                }
            },
        );

        $this->assertEquals($expected, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertEquals($expected, $fulfill($request));
        $this->assertSame(12, $inner->calls);
    }

    public function testRetryWhileThereIsStillAServerError()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::internalServerError,
            $request->protocolVersion(),
        );
        $expected = Either::left(new ServerError($request, $response));

        $fulfill = ExponentialBackoff::of(
            $inner = new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                    public int $calls = 0,
                ) {
                }

                public function __invoke(Request $request): Either
                {
                    ++$this->calls;

                    return $this->expected;
                }
            },
            new class($this) implements Halt {
                public function __construct(
                    private $test,
                    private int $calls = 0,
                ) {
                }

                public function __invoke(Period $period): Attempt
                {
                    ++$this->calls;

                    match ($this->calls) {
                        1, 6 => $this->test->assertEquals(Period::millisecond(100), $period),
                        2, 7 => $this->test->assertEquals(Period::millisecond(271), $period),
                        3, 8 => $this->test->assertEquals(Period::millisecond(738), $period),
                        4, 9 => $this->test->assertEquals(Period::millisecond(2008), $period),
                        5, 10 => $this->test->assertEquals(Period::millisecond(5459), $period),
                    };

                    return Attempt::result(SideEffect::identity());
                }
            },
        );

        $this->assertEquals($expected, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertEquals($expected, $fulfill($request));
        $this->assertSame(12, $inner->calls);
    }

    public function testRetryWhileThereIsStillAConnectionFailure()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $expected = Either::left(new ConnectionFailed($request, ''));

        $fulfill = ExponentialBackoff::of(
            $inner = new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                    public int $calls = 0,
                ) {
                }

                public function __invoke(Request $request): Either
                {
                    ++$this->calls;

                    return $this->expected;
                }
            },
            new class($this) implements Halt {
                public function __construct(
                    private $test,
                    private int $calls = 0,
                ) {
                }

                public function __invoke(Period $period): Attempt
                {
                    ++$this->calls;

                    match ($this->calls) {
                        1, 6 => $this->test->assertEquals(Period::millisecond(100), $period),
                        2, 7 => $this->test->assertEquals(Period::millisecond(271), $period),
                        3, 8 => $this->test->assertEquals(Period::millisecond(738), $period),
                        4, 9 => $this->test->assertEquals(Period::millisecond(2008), $period),
                        5, 10 => $this->test->assertEquals(Period::millisecond(5459), $period),
                    };

                    return Attempt::result(SideEffect::identity());
                }
            },
        );

        $this->assertEquals($expected, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertEquals($expected, $fulfill($request));
        $this->assertSame(12, $inner->calls);
    }

    public function testStopRetryingWhenNoLongerReceivingAServerError()
    {
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
        $error = Either::left(new ServerError($request, $response1));
        $expected = Either::right(new Success($request, $response2));

        $fulfill = ExponentialBackoff::of(
            $inner = new class([$error, $expected]) implements Transport {
                public function __construct(
                    private $expected,
                    public int $calls = 0,
                ) {
                }

                public function __invoke(Request $request): Either
                {
                    ++$this->calls;

                    return \array_shift($this->expected);
                }
            },
            new class($this) implements Halt {
                public function __construct(
                    private $test,
                    private int $calls = 0,
                ) {
                }

                public function __invoke(Period $period): Attempt
                {
                    ++$this->calls;

                    match ($this->calls) {
                        1 => $this->test->assertEquals(Period::millisecond(100), $period),
                    };

                    return Attempt::result(SideEffect::identity());
                }
            },
        );

        $this->assertEquals($expected, $fulfill($request));
        $this->assertSame(2, $inner->calls);
    }

    public function testByDefaultRetriesFiveTimesByUsingAPowerOfE()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::internalServerError,
            $request->protocolVersion(),
        );
        $expected = Either::left(new ServerError($request, $response));

        $fulfill = ExponentialBackoff::of(
            $inner = new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                    public int $calls = 0,
                ) {
                }

                public function __invoke(Request $request): Either
                {
                    ++$this->calls;

                    return $this->expected;
                }
            },
            new class($this) implements Halt {
                public function __construct(
                    private $test,
                    private int $calls = 0,
                ) {
                }

                public function __invoke(Period $period): Attempt
                {
                    ++$this->calls;

                    match ($this->calls) {
                        1 => $this->test->assertEquals(Period::millisecond(100), $period),
                        2 => $this->test->assertEquals(Period::millisecond(271), $period),
                        3 => $this->test->assertEquals(Period::millisecond(738), $period),
                        4 => $this->test->assertEquals(Period::millisecond(2008), $period),
                        5 => $this->test->assertEquals(Period::millisecond(5459), $period),
                    };

                    return Attempt::result(SideEffect::identity());
                }
            },
        );

        $this->assertEquals($expected, $fulfill($request));
        $this->assertSame(6, $inner->calls);
    }
}
