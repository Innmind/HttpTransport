<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    ThrowOnServerErrorTransport,
    TransportInterface,
    Exception\ServerErrorException
};
use Innmind\Http\Message\{
    RequestInterface,
    ResponseInterface,
    StatusCode
};

class ThrowOnServerErrorTransportTest extends \PHPUnit_Framework_TestCase
{
    private $transport;
    private $inner;

    public function setUp()
    {
        $this->transport = new ThrowOnServerErrorTransport(
            $this->inner = $this->createMock(TransportInterface::class)
        );
    }

    public function testInterface()
    {
        $this->assertInstanceOf(
            TransportInterface::class,
            $this->transport
        );
    }

    public function testFulfill()
    {
        $request = $this->createMock(RequestInterface::class);
        $this
            ->inner
            ->expects($this->once())
            ->method('fulfill')
            ->with($request)
            ->willReturn(
                $expected = $this->createMock(ResponseInterface::class)
            );
        $expected
            ->expects($this->once())
            ->method('statusCode')
            ->willReturn(new StatusCode(400));

        $response = $this->transport->fulfill($request);

        $this->assertSame($expected, $response);
    }

    public function testThrow()
    {
        $request = $this->createMock(RequestInterface::class);
        $this
            ->inner
            ->expects($this->once())
            ->method('fulfill')
            ->with($request)
            ->willReturn(
                $response = $this->createMock(ResponseInterface::class)
            );
        $response
            ->expects($this->once())
            ->method('statusCode')
            ->willReturn(new StatusCode(503));

        try {
            $this->transport->fulfill($request);

            $this->fail('it should throw an exception');
        } catch (ServerErrorException $e) {
            $this->assertSame($request, $e->request());
            $this->assertSame($response, $e->response());
        }
    }
}
