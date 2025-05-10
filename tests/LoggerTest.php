<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    Logger,
    Curl,
    Transport,
    Success,
};
use Innmind\TimeContinuum\Clock;
use Innmind\Http\{
    Request,
    Method,
    ProtocolVersion,
    Headers,
    Header,
    Header\Value,
};
use Innmind\Url\Url;
use Psr\Log\NullLogger;
use Innmind\BlackBox\PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    private $fulfill;

    public function setUp(): void
    {
        $this->fulfill = Logger::psr(
            Curl::of(Clock::live()),
            new NullLogger,
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
            Method::get,
            ProtocolVersion::v11,
            Headers::of(
                Header::of(
                    'foo',
                    Value::of('bar'),
                    Value::of('baz'),
                ),
                Header::of(
                    'foobar',
                    Value::of('whatever'),
                ),
            ),
        );

        $success = ($this->fulfill)($request)->match(
            static fn($success) => $success,
            static fn() => null,
        );

        $this->assertInstanceOf(Success::class, $success);
    }
}
