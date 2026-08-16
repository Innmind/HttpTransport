<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
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
use Innmind\Time\{
    Halt,
    Period,
};
use Innmind\Url\Url;
use Innmind\Immutable\{
    Either,
    Attempt,
    SideEffect,
};
use Innmind\BlackBox\PHPUnit\Framework\{
    TestCase,
    Attributes\Group,
};

class ExponentialBackoffTest extends TestCase
{
    #[Group('local')]
    #[Group('ci')]
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

        $fulfill = Transport::exponentialBackoff(
            Transport::via(static fn() => $expected),
            Halt::via(static fn() => Attempt::error(new \Exception)),
        );

        $this->assertEquals($expected, $fulfill($request));
    }

    #[Group('local')]
    #[Group('ci')]
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

        $fulfill = Transport::exponentialBackoff(
            Transport::via(static fn() => $expected),
            Halt::via(static fn() => Attempt::error(new \Exception)),
        );

        $this->assertEquals($expected, $fulfill($request));
    }

    #[Group('local')]
    #[Group('ci')]
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

        $fulfill = Transport::exponentialBackoff(
            Transport::via(static fn() => $expected),
            Halt::via(static fn() => Attempt::error(new \Exception)),
        );

        $this->assertEquals($expected, $fulfill($request));
    }

    #[Group('local')]
    #[Group('ci')]
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

        $fulfill = Transport::exponentialBackoff(
            Transport::via(static fn() => $expected),
            Halt::via(static fn() => Attempt::error(new \Exception)),
        );

        $this->assertEquals($expected, $fulfill($request));
    }

    #[Group('local')]
    #[Group('ci')]
    public function testDoesntRetryWhenMalformedResponseOnFirstCall()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $expected = Either::left(new MalformedResponse($request));

        $fulfill = Transport::exponentialBackoff(
            Transport::via(static fn() => $expected),
            Halt::via(static fn() => Attempt::error(new \Exception)),
        );

        $this->assertEquals($expected, $fulfill($request));
    }

    #[Group('local')]
    #[Group('ci')]
    public function testDoesntRetryWhenFailureOnFirstCall()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $expected = Either::left(new Failure($request, 'whatever'));

        $fulfill = Transport::exponentialBackoff(
            Transport::via(static fn() => $expected),
            Halt::via(static fn() => Attempt::error(new \Exception)),
        );

        $this->assertEquals($expected, $fulfill($request));
    }

    #[Group('local')]
    #[Group('ci')]
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
        $calls = 0;

        $fulfill = Transport::exponentialBackoff(
            Transport::via(static function() use (&$calls, $expected) {
                ++$calls;

                return $expected;
            }),
            Halt::via(function($period) {
                static $calls = 0;
                ++$calls;

                match ($calls) {
                    1, 6 => $this->assertEquals(Period::millisecond(100), $period),
                    2, 7 => $this->assertEquals(Period::millisecond(271), $period),
                    3, 8 => $this->assertEquals(Period::millisecond(738), $period),
                    4, 9 => $this->assertEquals(Period::millisecond(2008), $period),
                    5, 10 => $this->assertEquals(Period::millisecond(5459), $period),
                };

                return Attempt::result(SideEffect::identity);
            }),
        );

        $this->assertEquals($expected, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertEquals($expected, $fulfill($request));
        $this->assertSame(12, $calls);
    }

    #[Group('local')]
    #[Group('ci')]
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
        $calls = 0;

        $fulfill = Transport::exponentialBackoff(
            Transport::via(static function() use (&$calls, $expected) {
                ++$calls;

                return $expected;
            }),
            Halt::via(function($period) {
                static $calls = 0;
                ++$calls;

                match ($calls) {
                    1, 6 => $this->assertEquals(Period::millisecond(100), $period),
                    2, 7 => $this->assertEquals(Period::millisecond(271), $period),
                    3, 8 => $this->assertEquals(Period::millisecond(738), $period),
                    4, 9 => $this->assertEquals(Period::millisecond(2008), $period),
                    5, 10 => $this->assertEquals(Period::millisecond(5459), $period),
                };

                return Attempt::result(SideEffect::identity);
            }),
        );

        $this->assertEquals($expected, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertEquals($expected, $fulfill($request));
        $this->assertSame(12, $calls);
    }

    #[Group('local')]
    #[Group('ci')]
    public function testRetryWhileThereIsStillAConnectionFailure()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );
        $expected = Either::left(new ConnectionFailed($request, ''));
        $calls = 0;

        $fulfill = Transport::exponentialBackoff(
            Transport::via(static function() use (&$calls, $expected) {
                ++$calls;

                return $expected;
            }),
            Halt::via(function($period) {
                static $calls = 0;
                ++$calls;

                match ($calls) {
                    1, 6 => $this->assertEquals(Period::millisecond(100), $period),
                    2, 7 => $this->assertEquals(Period::millisecond(271), $period),
                    3, 8 => $this->assertEquals(Period::millisecond(738), $period),
                    4, 9 => $this->assertEquals(Period::millisecond(2008), $period),
                    5, 10 => $this->assertEquals(Period::millisecond(5459), $period),
                };

                return Attempt::result(SideEffect::identity);
            }),
        );

        $this->assertEquals($expected, $fulfill($request));
        // to make sure halt periods are kept between requests
        $this->assertEquals($expected, $fulfill($request));
        $this->assertSame(12, $calls);
    }

    #[Group('local')]
    #[Group('ci')]
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
        $all = [$error, $expected];
        $calls = 0;

        $fulfill = Transport::exponentialBackoff(
            Transport::via(static function() use (&$calls, &$all) {
                ++$calls;

                return \array_shift($all);
            }),
            Halt::via(function($period) {
                static $calls = 0;
                ++$calls;

                match ($calls) {
                    1 => $this->assertEquals(Period::millisecond(100), $period),
                };

                return Attempt::result(SideEffect::identity);
            }),
        );

        $this->assertEquals($expected, $fulfill($request));
        $this->assertSame(2, $calls);
    }

    #[Group('local')]
    #[Group('ci')]
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
        $calls = 0;

        $fulfill = Transport::exponentialBackoff(
            Transport::via(static function() use (&$calls, $expected) {
                ++$calls;

                return $expected;
            }),
            Halt::via(function($period) {
                static $calls = 0;
                ++$calls;

                match ($calls) {
                    1=> $this->assertEquals(Period::millisecond(100), $period),
                    2=> $this->assertEquals(Period::millisecond(271), $period),
                    3=> $this->assertEquals(Period::millisecond(738), $period),
                    4=> $this->assertEquals(Period::millisecond(2008), $period),
                    5=> $this->assertEquals(Period::millisecond(5459), $period),
                };

                return Attempt::result(SideEffect::identity);
            }),
        );

        $this->assertEquals($expected, $fulfill($request));
        $this->assertSame(6, $calls);
    }
}
