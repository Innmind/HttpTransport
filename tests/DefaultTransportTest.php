<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    DefaultTransport,
    Transport,
    Exception\ConnectionFailed,
};
use Innmind\Url\Url;
use Innmind\Http\{
    Translator\Response\Psr7Translator,
    Factory\HeaderFactory,
    Message\Response,
    Message\Request\Request,
    Message\Method\Method,
    ProtocolVersion\ProtocolVersion,
    Headers\Headers,
    Header,
    Header\ContentType,
    Header\ContentTypeValue,
};
use Innmind\Filesystem\Stream\StringStream;
use Innmind\Immutable\Map;
use GuzzleHttp\{
    ClientInterface,
    Exception\ConnectException,
    Exception\BadResponseException,
};
use Psr\Http\Message\{
    ResponseInterface as Psr7ResponseInterface,
    RequestInterface as Psr7RequestInterface,
};
use PHPUnit\Framework\TestCase;

class DefaultTransportTest extends TestCase
{
    public function testFulfill()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new Psr7Translator(
                $this->createMock(HeaderFactory::class)
            )
        );
        $client
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'http://example.com/',
                []
            )
            ->willReturn(
                $response = $this->createMock(Psr7ResponseInterface::class)
            );
        $response
            ->method('getProtocolVersion')
            ->willReturn('1.1');
        $response
            ->method('getStatusCode')
            ->willReturn(200);
        $response
            ->method('getHeaders')
            ->willReturn([]);

        $response = ($fulfill)(
            new Request(
                Url::fromString('http://example.com'),
                new Method('GET'),
                new ProtocolVersion(1, 1),
                new Headers(new Map('string', Header::class)),
                new StringStream('')
            )
        );

        $this->assertInstanceOf(Transport::class, $fulfill);
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testThrowOnConnectException()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new Psr7Translator(
                $this->createMock(HeaderFactory::class)
            )
        );
        $client
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'http://example.com/',
                []
            )
            ->will(
                $this->throwException(
                    $this->createMock(ConnectException::class)
                )
            );

        try {
            ($fulfill)(
                $request = new Request(
                    Url::fromString('http://example.com'),
                    new Method('GET'),
                    new ProtocolVersion(1, 1),
                    new Headers(new Map('string', Header::class)),
                    new StringStream('')
                )
            );
            $this->fail('it should throw');
        } catch (ConnectionFailed $e) {
            $this->assertSame($request, $e->request());
        }
    }

    public function testFulfillWithMethod()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new Psr7Translator(
                $this->createMock(HeaderFactory::class)
            )
        );
        $client
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'http://example.com/',
                []
            )
            ->willReturn(
                $response = $this->createMock(Psr7ResponseInterface::class)
            );
        $response
            ->method('getProtocolVersion')
            ->willReturn('1.1');
        $response
            ->method('getStatusCode')
            ->willReturn(200);
        $response
            ->method('getHeaders')
            ->willReturn([]);

        $response = ($fulfill)(
            new Request(
                Url::fromString('http://example.com'),
                new Method('POST'),
                new ProtocolVersion(1, 1),
                new Headers(new Map('string', Header::class)),
                new StringStream('')
            )
        );

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testFulfillWithHeaders()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new Psr7Translator(
                $this->createMock(HeaderFactory::class)
            )
        );
        $client
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'http://example.com/',
                [
                    'headers' => ['Content-Type' => ['application/json']],
                ]
            )
            ->willReturn(
                $response = $this->createMock(Psr7ResponseInterface::class)
            );
        $response
            ->method('getProtocolVersion')
            ->willReturn('1.1');
        $response
            ->method('getStatusCode')
            ->willReturn(200);
        $response
            ->method('getHeaders')
            ->willReturn([]);

        $response = ($fulfill)(
            new Request(
                Url::fromString('http://example.com'),
                new Method('GET'),
                new ProtocolVersion(1, 1),
                new Headers(
                    (new Map('string', Header::class))
                        ->put(
                            'Content-Type',
                            new ContentType(
                                new ContentTypeValue(
                                    'application',
                                    'json'
                                )
                            )
                        )
                ),
                new StringStream('')
            )
        );

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testFulfillWithPayload()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new Psr7Translator(
                $this->createMock(HeaderFactory::class)
            )
        );
        $client
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'http://example.com/',
                [
                    'body' => 'content',
                ]
            )
            ->willReturn(
                $response = $this->createMock(Psr7ResponseInterface::class)
            );
        $response
            ->method('getProtocolVersion')
            ->willReturn('1.1');
        $response
            ->method('getStatusCode')
            ->willReturn(200);
        $response
            ->method('getHeaders')
            ->willReturn([]);

        $response = ($fulfill)(
            new Request(
                Url::fromString('http://example.com'),
                new Method('GET'),
                new ProtocolVersion(1, 1),
                new Headers(new Map('string', Header::class)),
                new StringStream('content')
            )
        );

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testFulfillCompletelyModifiedRequest()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new Psr7Translator(
                $this->createMock(HeaderFactory::class)
            )
        );
        $client
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'http://example.com/',
                [
                    'body' => 'content',
                    'headers' => ['Content-Type' => ['application/json']],
                ]
            )
            ->willReturn(
                $response = $this->createMock(Psr7ResponseInterface::class)
            );
        $response
            ->method('getProtocolVersion')
            ->willReturn('1.1');
        $response
            ->method('getStatusCode')
            ->willReturn(200);
        $response
            ->method('getHeaders')
            ->willReturn([]);

        $response = ($fulfill)(
            new Request(
                Url::fromString('http://example.com'),
                new Method('POST'),
                new ProtocolVersion(1, 1),
                new Headers(
                    (new Map('string', Header::class))
                        ->put(
                            'Content-Type',
                            new ContentType(
                                new ContentTypeValue(
                                    'application',
                                    'json'
                                )
                            )
                        )
                ),
                new StringStream('content')
            )
        );

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testCatchBadResponse()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new Psr7Translator(
                $this->createMock(HeaderFactory::class)
            )
        );
        $client
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'http://example.com/',
                []
            )
            ->will($this->throwException(new BadResponseException(
                'watev',
                $this->createMock(Psr7RequestInterface::class),
                $response = $this->createMock(Psr7ResponseInterface::class)
            )));
        $response
            ->method('getProtocolVersion')
            ->willReturn('1.1');
        $response
            ->method('getStatusCode')
            ->willReturn(200);
        $response
            ->method('getHeaders')
            ->willReturn([]);

        $response = ($fulfill)(
            new Request(
                Url::fromString('http://example.com'),
                new Method('GET'),
                new ProtocolVersion(1, 1)
            )
        );

        $this->assertInstanceOf(Transport::class, $fulfill);
        $this->assertInstanceOf(Response::class, $response);
    }
}
