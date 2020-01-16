<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    ThrowOnErrorTransport,
    Transport,
    Exception\ClientError,
    Exception\ServerError,
};
use Innmind\Http\Message\{
    Request,
    Response,
    StatusCode,
};
use PHPUnit\Framework\TestCase;

class ThrowOnErrorTransportTest extends TestCase
{
    private $fulfill;
    private $inner;

    public function setUp(): void
    {
        $this->fulfill = new ThrowOnErrorTransport(
            $this->inner = $this->createMock(Transport::class)
        );
    }

    public function testInterface()
    {
        $this->assertInstanceOf(
            Transport::class,
            $this->fulfill
        );
    }

    public function testFulfill()
    {
        $request = $this->createMock(Request::class);
        $this
            ->inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn(
                $expected = $this->createMock(Response::class)
            );
        $expected
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(300));

        $response = ($this->fulfill)($request);

        $this->assertSame($expected, $response);
    }

    public function testThrowOnClientError()
    {
        $request = $this->createMock(Request::class);
        $this
            ->inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn(
                $response = $this->createMock(Response::class)
            );
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(404));

        try {
            ($this->fulfill)($request);

            $this->fail('it should throw an exception');
        } catch (ClientError $e) {
            $this->assertSame($request, $e->request());
            $this->assertSame($response, $e->response());
        }
    }

    public function testThrowOnServerError()
    {
        $request = $this->createMock(Request::class);
        $this
            ->inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn(
                $response = $this->createMock(Response::class)
            );
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(new StatusCode(500));

        try {
            ($this->fulfill)($request);

            $this->fail('it should throw an exception');
        } catch (ServerError $e) {
            $this->assertSame($request, $e->request());
            $this->assertSame($response, $e->response());
        }
    }
}
