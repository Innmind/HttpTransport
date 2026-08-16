<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
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
use Innmind\Time\{
    Clock,
    Period,
};
use Innmind\Immutable\Either;
use Innmind\BlackBox\PHPUnit\Framework\{
    TestCase,
    Attributes\Group,
};

class CircuitBreakerTest extends TestCase
{
    #[Group('local')]
    #[Group('ci')]
    public function testDoesntOpenCircuitOnSuccessfulResponse()
    {
        $request = Request::of(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::ok,
            $request->protocolVersion(),
        );
        $expected = Either::right(new Success($request, $response));

        $fulfill = Transport::circuitBreaker(
            Transport::via(static fn() => $expected),
            Clock::live(),
            Period::hour(1),
        );

        $this->assertEquals($expected, $fulfill($request));
        $this->assertEquals($expected, $fulfill($request));
    }

    #[Group('local')]
    #[Group('ci')]
    public function testDoesntOpenCircuitOnRedirectionResponse()
    {
        $request = Request::of(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::movedPermanently,
            $request->protocolVersion(),
        );
        $expected = Either::left(new Redirection($request, $response));

        $fulfill = Transport::circuitBreaker(
            Transport::via(static fn() => $expected),
            Clock::live(),
            Period::hour(1),
        );

        $this->assertEquals($expected, $fulfill($request));
        $this->assertEquals($expected, $fulfill($request));
    }

    #[Group('local')]
    #[Group('ci')]
    public function testDoesntOpenCircuitOnClientErrorResponse()
    {
        $request = Request::of(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::notFound,
            $request->protocolVersion(),
        );
        $expected = Either::left(new ClientError($request, $response));

        $fulfill = Transport::circuitBreaker(
            Transport::via(static fn() => $expected),
            Clock::live(),
            Period::hour(1),
        );

        $this->assertEquals($expected, $fulfill($request));
        $this->assertEquals($expected, $fulfill($request));
    }

    #[Group('local')]
    #[Group('ci')]
    public function testOpenCircuitOnServerError()
    {
        $request = Request::of(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $response = Response::of(
            StatusCode::internalServerError,
            $request->protocolVersion(),
        );
        $expected = Either::left(new ServerError($request, $response));

        $fulfill = Transport::circuitBreaker(
            Transport::via(static fn() => $expected),
            Clock::live(),
            Period::hour(1),
        );

        $this->assertEquals($expected, $fulfill($request));
        $defaultResponse = $fulfill($request);
        $this->assertNotEquals($expected, $defaultResponse);
        $this->assertSame(503, $defaultResponse->match(
            static fn() => null,
            static fn($error) => $error->response()->statusCode()->toInt(),
        ));
    }

    #[Group('local')]
    #[Group('ci')]
    public function testOpenCircuitOnConnectionFailure()
    {
        $request = Request::of(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $expected = Either::left(new ConnectionFailed($request, ''));

        $fulfill = Transport::circuitBreaker(
            Transport::via(static fn() => $expected),
            Clock::live(),
            Period::hour(1),
        );

        $this->assertEquals($expected, $fulfill($request));
        $defaultResponse = $fulfill($request);
        $this->assertNotEquals($expected, $defaultResponse);
        $this->assertSame(503, $defaultResponse->match(
            static fn() => null,
            static fn($error) => $error->response()->statusCode()->toInt(),
        ));
    }

    #[Group('local')]
    #[Group('ci')]
    public function testOpenCircuitOnlyForTheDomainThatFailed()
    {
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
        $expected2 = Either::right(new Success($request2, $response2));
        $expected = [$expected1, $expected2];

        $fulfill = Transport::circuitBreaker(
            Transport::via(static function() use (&$expected) {
                return \array_shift($expected);
            }),
            Clock::live(),
            Period::hour(1),
        );

        $this->assertEquals($expected1, $fulfill($request1));
        $this->assertEquals($expected2, $fulfill($request2));
    }

    #[Group('local')]
    #[Group('ci')]
    public function testRecloseTheCircuitAfterTheSpecifiedDelay()
    {
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
        $expected1 = Either::left(new ServerError($request, $response1));
        $expected2 = Either::right(new Success($request, $response2));
        $expected = [$expected1, $expected2];

        $fulfill = Transport::circuitBreaker(
            Transport::via(static function() use (&$expected) {
                return \array_shift($expected);
            }),
            Clock::live(),
            Period::millisecond(1),
        );

        $this->assertEquals($expected1, $fulfill($request));
        \usleep(5_000);
        $this->assertEquals($expected2, $fulfill($request));
    }
}
