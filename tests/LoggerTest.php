<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    Logger,
    Transport,
    Success,
};
use Innmind\Http\{
    Request,
    Response,
    Response\StatusCode,
    Method,
    ProtocolVersion,
    Headers,
    Header,
    Header\Value\Value,
};
use Innmind\Url\Url;
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
        $request = Request::of(
            Url::of('http://example.com'),
            Method::post,
            ProtocolVersion::v11,
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
        $response = Response::of(
            StatusCode::ok,
            $request->protocolVersion(),
            Headers::of(
                new Header\Header(
                    'x-debug',
                    new Value('yay'),
                    new Value('nay'),
                ),
            ),
        );
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
            ->expects($matcher = $this->exactly(2))
            ->method('debug')
            ->willReturnCallback(function($message, $context) use ($matcher) {
                match ($matcher->numberOfInvocations()) {
                    1 => $this->assertSame('Http request about to be sent', $message),
                    2 => $this->assertSame('Http request sent', $message),
                };

                if ($matcher->numberOfInvocations() === 1) {
                    $this->assertSame('POST', $context['method']);
                    $this->assertSame('http://example.com/', $context['url']);
                    $this->assertSame(['foo' => 'bar, baz', 'foobar' => 'whatever'], $context['headers']);
                    $this->assertTrue(!empty($context['reference']));
                } else {
                    $this->assertSame(200, $context['statusCode']);
                    $this->assertSame(['x-debug' => 'yay, nay'], $context['headers']);
                }
            });

        $response = ($this->fulfill)($request);

        $this->assertEquals($expected, $response);
    }
}
