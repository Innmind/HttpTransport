<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    CircuitBreaker,
    Curl,
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
};
use Innmind\Immutable\Either;
use Innmind\BlackBox\PHPUnit\Framework\TestCase;

class CircuitBreakerTest extends TestCase
{
    public function testInterface()
    {
        $this->assertInstanceOf(
            Transport::class,
            CircuitBreaker::of(
                Curl::of(Clock::live()),
                Clock::live(),
                Period::millisecond(1),
            ),
        );
    }

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

        $fulfill = CircuitBreaker::of(
            new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                ) {
                }

                public function __invoke(Request $_): Either
                {
                    return $this->expected;
                }
            },
            Clock::live(),
            Period::hour(1),
        );

        $this->assertEquals($expected, $fulfill($request));
        $this->assertEquals($expected, $fulfill($request));
    }

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

        $fulfill = CircuitBreaker::of(
            new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                ) {
                }

                public function __invoke(Request $_): Either
                {
                    return $this->expected;
                }
            },
            Clock::live(),
            Period::hour(1),
        );

        $this->assertEquals($expected, $fulfill($request));
        $this->assertEquals($expected, $fulfill($request));
    }

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

        $fulfill = CircuitBreaker::of(
            new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                ) {
                }

                public function __invoke(Request $_): Either
                {
                    return $this->expected;
                }
            },
            Clock::live(),
            Period::hour(1),
        );

        $this->assertEquals($expected, $fulfill($request));
        $this->assertEquals($expected, $fulfill($request));
    }

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

        $fulfill = CircuitBreaker::of(
            new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                ) {
                }

                public function __invoke(Request $_): Either
                {
                    return $this->expected;
                }
            },
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

    public function testOpenCircuitOnConnectionFailure()
    {
        $request = Request::of(
            Url::of('http://example.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $expected = Either::left(new ConnectionFailed($request, ''));

        $fulfill = CircuitBreaker::of(
            new class($expected) implements Transport {
                public function __construct(
                    private $expected,
                ) {
                }

                public function __invoke(Request $_): Either
                {
                    return $this->expected;
                }
            },
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

        $fulfill = CircuitBreaker::of(
            new class([$expected1, $expected2]) implements Transport {
                public function __construct(
                    private array $expected,
                ) {
                }

                public function __invoke(Request $_): Either
                {
                    return \array_shift($this->expected);
                }
            },
            Clock::live(),
            Period::hour(1),
        );

        $this->assertEquals($expected1, $fulfill($request1));
        $this->assertEquals($expected2, $fulfill($request2));
    }

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

        $fulfill = CircuitBreaker::of(
            new class([$expected1, $expected2]) implements Transport {
                public function __construct(
                    private array $expected,
                ) {
                }

                public function __invoke(Request $_): Either
                {
                    return \array_shift($this->expected);
                }
            },
            Clock::live(),
            Period::millisecond(1),
        );

        $this->assertEquals($expected1, $fulfill($request));
        \usleep(5_000);
        $this->assertEquals($expected2, $fulfill($request));
    }
}
