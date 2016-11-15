<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    ThrowOnClientErrorTransport,
    TransportInterface,
    Exception\ClientErrorException
};
use Innmind\Http\Message\{
    RequestInterface,
    ResponseInterface,
    StatusCode
};

class ThrowOnClientErrorTransportTest extends \PHPUnit_Framework_TestCase
{
    private $transport;
    private $inner;

    public function setUp()
    {
        $this->transport = new ThrowOnClientErrorTransport(
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
            ->willReturn(new StatusCode(500));

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
            ->willReturn(new StatusCode(404));

        try {
            $this->transport->fulfill($request);

            $this->fail('it should throw an exception');
        } catch (ClientErrorException $e) {
            $this->assertSame($request, $e->request());
            $this->assertSame($response, $e->response());
        }
    }
}
