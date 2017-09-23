<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    LoggerTransport,
    Transport
};
use Innmind\Http\{
    Message\Request,
    Message\Response,
    Message\StatusCode\StatusCode,
    Message\Method\Method,
    Headers\Headers,
    Header,
    Header\Value\Value
};
use Innmind\Url\Url;
use Innmind\Filesystem\Stream\StringStream;
use Innmind\Immutable\Map;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class LoggerTransportTest extends TestCase
{
    private $transport;
    private $inner;
    private $logger;

    public function setUp()
    {
        $this->transport = new LoggerTransport(
            $this->inner = $this->createMock(Transport::class),
            $this->logger = $this->createMock(LoggerInterface::class),
            'emergency'
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
        $request
            ->expects($this->once())
            ->method('method')
            ->willReturn(new Method('POST'));
        $request
            ->expects($this->once())
            ->method('url')
            ->willReturn(Url::fromString('http://example.com'));
        $request
            ->expects($this->once())
            ->method('body')
            ->willReturn(new StringStream('foo'));
        $request
            ->expects($this->once())
            ->method('headers')
            ->willReturn(
                new Headers(
                    (new Map('string', Header::class))
                        ->put(
                            'foo',
                            new Header\Header(
                                'foo',
                                new Value('bar'),
                                new Value('baz')
                            )
                        )
                        ->put(
                            'foobar',
                            new Header\Header(
                                'foobar',
                                new Value('whatever')
                            )
                        )
                )
            );
        $reference = null;
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
            ->willReturn(new StatusCode(200));
        $expected
            ->expects($this->once())
            ->method('headers')
            ->willReturn(
                new Headers(
                    (new Map('string', Header::class))
                        ->put(
                            'x-debug',
                            new Header\Header(
                                'x-debug',
                                new Value('yay'),
                                new Value('nay')
                            )
                        )
                )
            );
        $expected
            ->expects($this->once())
            ->method('body')
            ->willReturn(new StringStream('idk'));
        $this
            ->logger
            ->expects($this->at(0))
            ->method('log')
            ->with(
                'emergency',
                'Http request about to be sent',
                $this->callback(function(array $data) use (&$reference): bool {
                    $reference = $data['reference'];

                    return $data['method'] === 'POST' &&
                        $data['url'] === 'http://example.com/' &&
                        $data['headers'] === ['foo' => 'bar, baz', 'foobar' => 'whatever'] &&
                        $data['body'] === 'foo' &&
                        !empty($data['reference']);
                })
            );
        $this
            ->logger
            ->expects($this->at(1))
            ->method('log')
            ->with(
                'emergency',
                'Http request sent',
                $this->callback(function(array $data) use (&$reference): bool {
                    return $data['statusCode'] === 200 &&
                        $data['headers'] === ['x-debug' => 'yay, nay'] &&
                        $data['body'] === 'idk' &&
                        $data['reference'] === $reference;
                })
            );

        $response = $this->transport->fulfill($request);

        $this->assertSame($expected, $response);
    }
}
