<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    ThrowOnClientErrorTransport,
    Transport,
    Exception\ClientError
};
use Innmind\Http\Message\{
    Request,
    Response,
    StatusCode\StatusCode
};
use PHPUnit\Framework\TestCase;

class ThrowOnClientErrorTransportTest extends TestCase
{
    private $transport;
    private $inner;

    public function setUp()
    {
        $this->transport = new ThrowOnClientErrorTransport(
            $this->inner = $this->createMock(Transport::class)
        );
    }

    public function testInterface()
    {
        $this->assertInstanceOf(
            Transport::class,
            $this->transport
        );
    }

    public function testFulfill()
    {
        $request = $this->createMock(Request::class);
        $this
            ->inner
            ->expects($this->once())
            ->method('fulfill')
            ->with($request)
            ->willReturn(
                $expected = $this->createMock(Response::class)
            );
        $expected
            ->expects($this->once())
            ->method('statusCode')
            ->willReturn(new StatusCode(500));

        $response = $this->transport->fulfill($request);

        $this->assertSame($expected, $response);
    }

    public function testThrow()
    {
        $request = $this->createMock(Request::class);
        $this
            ->inner
            ->expects($this->once())
            ->method('fulfill')
            ->with($request)
            ->willReturn(
                $response = $this->createMock(Response::class)
            );
        $response
            ->expects($this->once())
            ->method('statusCode')
            ->willReturn(new StatusCode(404));

        try {
            $this->transport->fulfill($request);

            $this->fail('it should throw an exception');
        } catch (ClientError $e) {
            $this->assertSame($request, $e->request());
            $this->assertSame($response, $e->response());
        }
    }
}
