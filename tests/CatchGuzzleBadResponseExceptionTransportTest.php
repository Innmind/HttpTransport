<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    CatchGuzzleBadResponseExceptionTransport,
    Transport
};
use Innmind\Http\{
    Message\Request,
    Message\Response,
    Translator\Response\Psr7Translator,
    Factory\HeaderFactory
};
use Innmind\Immutable\Map;
use GuzzleHttp\{
    Psr7\Response as PsrResponse,
    Exception\ClientException,
    Exception\ServerException
};
use Psr\Http\Message\RequestInterface as PsrRequestInterface;
use PHPUnit\Framework\TestCase;

class CatchGuzzleBadResponseExceptionTransportTest extends TestCase
{
    private $transport;
    private $inner;

    public function setUp()
    {
        $this->transport = new CatchGuzzleBadResponseExceptionTransport(
            $this->inner = $this->createMock(Transport::class),
            new Psr7Translator(
                $this->createMock(HeaderFactory::class)
            )
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

        $response = $this->transport->fulfill($request);

        $this->assertSame($expected, $response);
    }

    public function testFulfillClientError()
    {
        $exception = new ClientException(
            'foo',
            $this->createMock(PsrRequestInterface::class),
            new PsrResponse(404)
        );
        $request = $this->createMock(Request::class);
        $this
            ->inner
            ->expects($this->once())
            ->method('fulfill')
            ->with($request)
            ->will(
                $this->throwException($exception)
            );

        $response = $this->transport->fulfill($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->statusCode()->value());
    }

    public function testFulfillServerError()
    {
        $exception = new ServerException(
            'foo',
            $this->createMock(PsrRequestInterface::class),
            new PsrResponse(503)
        );
        $request = $this->createMock(Request::class);
        $this
            ->inner
            ->expects($this->once())
            ->method('fulfill')
            ->with($request)
            ->will(
                $this->throwException($exception)
            );

        $response = $this->transport->fulfill($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(503, $response->statusCode()->value());
    }

    /**
     * @expectedException GuzzleHttp\Exception\ClientException
     */
    public function testThrowWhenClientExceptionWithoutResponse()
    {
        $exception = new ClientException(
            'foo',
            $this->createMock(PsrRequestInterface::class)
        );
        $request = $this->createMock(Request::class);
        $this
            ->inner
            ->expects($this->once())
            ->method('fulfill')
            ->with($request)
            ->will(
                $this->throwException($exception)
            );

        $this->transport->fulfill($request);
    }

    /**
     * @expectedException GuzzleHttp\Exception\ServerException
     */
    public function testThrowWhenServerExceptionWithoutResponse()
    {
        $exception = new ServerException(
            'foo',
            $this->createMock(PsrRequestInterface::class)
        );
        $request = $this->createMock(Request::class);
        $this
            ->inner
            ->expects($this->once())
            ->method('fulfill')
            ->with($request)
            ->will(
                $this->throwException($exception)
            );

        $this->transport->fulfill($request);
    }
}
