<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    DefaultTransport,
    Transport,
    ClientError,
    Success,
    ConnectionFailed,
};
use Innmind\Url\Url;
use Innmind\Http\{
    Translator\Response\FromPsr7,
    Factory\HeaderFactory,
    Message\Response,
    Message\Request\Request,
    Message\Method,
    ProtocolVersion,
    Headers,
    Header,
    Header\ContentType,
    Header\ContentTypeValue,
};
use Innmind\Filesystem\File\Content\Lines;
use GuzzleHttp\{
    ClientInterface,
    Exception\ConnectException,
    Exception\BadResponseException,
};
use Psr\Http\Message\{
    ResponseInterface as Psr7ResponseInterface,
    RequestInterface as Psr7RequestInterface,
    StreamInterface,
};
use PHPUnit\Framework\TestCase;

class DefaultTransportTest extends TestCase
{
    public function testFulfill()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new FromPsr7(
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
        $response
            ->method('getBody')
            ->willReturn($this->createMock(StreamInterface::class));

        $response = ($fulfill)(
            new Request(
                Url::of('http://example.com'),
                Method::of('GET'),
                new ProtocolVersion(1, 1),
                new Headers,
                Lines::ofContent(''),
            )
        );

        $this->assertInstanceOf(Transport::class, $fulfill);
        $this->assertInstanceOf(Success::class, $response->match(
            static fn() => null,
            static fn($success) => $success,
        ));
    }

    public function testReturnErrorOnConnectException()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new FromPsr7(
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

        $error = ($fulfill)(
            $request = new Request(
                Url::of('http://example.com'),
                Method::of('GET'),
                new ProtocolVersion(1, 1),
                new Headers,
                Lines::ofContent('')
            )
        )->match(
            static fn($error) => $error,
            static fn() => null,
        );

        $this->assertInstanceOf(ConnectionFailed::class, $error);
        $this->assertSame($request, $error->request());
    }

    public function testFulfillWithMethod()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new FromPsr7(
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
        $response
            ->method('getBody')
            ->willReturn($this->createMock(StreamInterface::class));

        $response = ($fulfill)(
            new Request(
                Url::of('http://example.com'),
                Method::of('POST'),
                new ProtocolVersion(1, 1),
                new Headers,
                Lines::ofContent(''),
            )
        );

        $this->assertInstanceOf(Success::class, $response->match(
            static fn() => null,
            static fn($success) => $success,
        ));
    }

    public function testFulfillWithHeaders()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new FromPsr7(
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
        $response
            ->method('getBody')
            ->willReturn($this->createMock(StreamInterface::class));

        $response = ($fulfill)(
            new Request(
                Url::of('http://example.com'),
                Method::of('GET'),
                new ProtocolVersion(1, 1),
                new Headers(
                    new ContentType(
                        new ContentTypeValue(
                            'application',
                            'json',
                        ),
                    ),
                ),
                Lines::ofContent(''),
            )
        );

        $this->assertInstanceOf(Success::class, $response->match(
            static fn() => null,
            static fn($success) => $success,
        ));
    }

    public function testFulfillWithPayload()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new FromPsr7(
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
        $response
            ->method('getBody')
            ->willReturn($this->createMock(StreamInterface::class));

        $response = ($fulfill)(
            new Request(
                Url::of('http://example.com'),
                Method::of('GET'),
                new ProtocolVersion(1, 1),
                new Headers,
                Lines::ofContent('content'),
            )
        );

        $this->assertInstanceOf(Success::class, $response->match(
            static fn() => null,
            static fn($success) => $success,
        ));
    }

    public function testFulfillCompletelyModifiedRequest()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new FromPsr7(
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
        $response
            ->method('getBody')
            ->willReturn($this->createMock(StreamInterface::class));

        $response = ($fulfill)(
            new Request(
                Url::of('http://example.com'),
                Method::of('POST'),
                new ProtocolVersion(1, 1),
                new Headers(
                    new ContentType(
                        new ContentTypeValue(
                            'application',
                            'json',
                        ),
                    ),
                ),
                Lines::ofContent('content'),
            )
        );

        $this->assertInstanceOf(Success::class, $response->match(
            static fn() => null,
            static fn($success) => $success,
        ));
    }

    public function testCatchBadResponse()
    {
        $fulfill = new DefaultTransport(
            $client = $this->createMock(ClientInterface::class),
            new FromPsr7(
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
            ->willReturn(400);
        $response
            ->method('getHeaders')
            ->willReturn([]);
        $response
            ->method('getBody')
            ->willReturn($this->createMock(StreamInterface::class));

        $response = ($fulfill)(
            new Request(
                Url::of('http://example.com'),
                Method::of('GET'),
                new ProtocolVersion(1, 1),
            )
        );

        $this->assertInstanceOf(ClientError::class, $response->match(
            static fn($error) => $error,
            static fn() => null,
        ));
    }
}
