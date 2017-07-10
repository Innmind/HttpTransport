<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    LoggerTransport,
    TransportInterface
};
use Innmind\Http\{
    Message\RequestInterface,
    Message\ResponseInterface,
    Message\StatusCode,
    Message\Method,
    Headers,
    Header\HeaderInterface,
    Header\HeaderValueInterface,
    Header\Header,
    Header\HeaderValue
};
use Innmind\Url\Url;
use Innmind\Filesystem\Stream\StringStream;
use Innmind\Immutable\{
    Map,
    Set
};
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
            $this->inner = $this->createMock(TransportInterface::class),
            $this->logger = $this->createMock(LoggerInterface::class),
            'emergency'
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
                    (new Map('string', HeaderInterface::class))
                        ->put(
                            'foo',
                            new Header(
                                'foo',
                                (new Set(HeaderValueInterface::class))
                                    ->add(new HeaderValue('bar'))
                                    ->add(new HeaderValue('baz'))
                            )
                        )
                        ->put(
                            'foobar',
                            new Header(
                                'foobar',
                                (new Set(HeaderValueInterface::class))
                                    ->add(new HeaderValue('whatever'))
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
                $expected = $this->createMock(ResponseInterface::class)
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
                    (new Map('string', HeaderInterface::class))
                        ->put(
                            'x-debug',
                            new Header(
                                'x-debug',
                                (new Set(HeaderValueInterface::class))
                                    ->add(new HeaderValue('yay'))
                                    ->add(new HeaderValue('nay'))
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
