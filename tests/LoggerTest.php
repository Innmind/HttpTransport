<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    Logger,
    Transport,
    Success,
};
use Innmind\Http\{
    Message\Request,
    Message\Response,
    Message\StatusCode,
    Message\Method,
    Headers,
    Header,
    Header\Value\Value,
};
use Innmind\Url\Url;
use Innmind\Filesystem\File\Content\Lines;
use Innmind\Immutable\Either;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    private $fulfill;
    private $inner;
    private $logger;

    public function setUp(): void
    {
        $this->fulfill = Logger::psr(
            $this->inner = $this->createMock(Transport::class),
            $this->logger = $this->createMock(LoggerInterface::class),
        );
    }

    public function testInterface()
    {
        $this->assertInstanceOf(
            Transport::class,
            $this->fulfill,
        );
    }

    public function testFulfill()
    {
        $request = $this->createMock(Request::class);
        $request
            ->expects($this->once())
            ->method('method')
            ->willReturn(Method::of('POST'));
        $request
            ->expects($this->once())
            ->method('url')
            ->willReturn(Url::of('http://example.com'));
        $request
            ->expects($this->once())
            ->method('body')
            ->willReturn(Lines::ofContent('foo'));
        $request
            ->expects($this->once())
            ->method('headers')
            ->willReturn(
                Headers::of(
                    new Header\Header(
                        'foo',
                        new Value('bar'),
                        new Value('baz'),
                    ),
                    new Header\Header(
                        'foobar',
                        new Value('whatever'),
                    ),
                ),
            );
        $reference = null;
        $response = $this->createMock(Response::class);
        $response
            ->expects($this->any())
            ->method('statusCode')
            ->willReturn(StatusCode::ok);
        $response
            ->expects($this->once())
            ->method('headers')
            ->willReturn(
                Headers::of(
                    new Header\Header(
                        'x-debug',
                        new Value('yay'),
                        new Value('nay'),
                    ),
                ),
            );
        $response
            ->expects($this->once())
            ->method('body')
            ->willReturn($body = Lines::ofContent('idk'));
        $this
            ->inner
            ->expects($this->once())
            ->method('__invoke')
            ->with($request)
            ->willReturn(
                $expected = Either::right(new Success($request, $response)),
            );
        $this
            ->logger
            ->expects($this->exactly(2))
            ->method('debug')
            ->withConsecutive(
                [
                    'Http request about to be sent',
                    $this->callback(static function(array $data) use (&$reference): bool {
                        $reference = $data['reference'];

                        return $data['method'] === 'POST' &&
                            $data['url'] === 'http://example.com/' &&
                            $data['headers'] === ['foo' => 'bar, baz', 'foobar' => 'whatever'] &&
                            $data['body'] === 'foo' &&
                            !empty($data['reference']);
                    }),
                ],
                [
                    'Http request sent',
                    $this->callback(static function(array $data) use (&$reference): bool {
                        return $data['statusCode'] === 200 &&
                            $data['headers'] === ['x-debug' => 'yay, nay'] &&
                            $data['body'] === 'idk' &&
                            $data['reference'] === $reference;
                    }),
                ],
            );

        $response = ($this->fulfill)($request);

        $this->assertEquals($expected, $response);
        $this->assertSame('idk', $body->toString());
    }
}
