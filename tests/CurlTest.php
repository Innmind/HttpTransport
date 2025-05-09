<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    Curl,
    Transport,
    Success,
    Redirection,
    ClientError,
    ConnectionFailed,
    ServerError,
    Failure,
    Header\Timeout,
};
use Innmind\Http\{
    Request,
    Response,
    Method,
    ProtocolVersion,
    Headers,
    Header\Date,
    Header\Location,
};
use Innmind\Filesystem\File\Content;
use Innmind\TimeContinuum\Earth\{
    Clock,
    ElapsedPeriod,
};
use Innmind\IO\IO;
use Innmind\Stream\Streams;
use Innmind\Url\{
    Url,
    Path,
};
use Innmind\Immutable\{
    Str,
    Maybe,
    Sequence,
};
use PHPUnit\Framework\TestCase;
use Innmind\BlackBox\{
    PHPUnit\BlackBox,
    Set,
};

class CurlTest extends TestCase
{
    use BlackBox;

    private $curl;

    public function setUp(): void
    {
        $this->curl = Curl::of(new Clock);
    }

    public function testInterface()
    {
        $this->assertInstanceOf(
            Transport::class,
            $this->curl,
        );
    }

    public function testOkResponse()
    {
        $success = ($this->curl)(Request::of(
            Url::of('https://github.com'),
            Method::get,
            ProtocolVersion::v11,
        ))->match(
            static fn($success) => $success,
            static fn() => null,
        );

        $this->assertInstanceOf(Success::class, $success);
        $this->assertSame(200, $success->response()->statusCode()->toInt());
        $this->assertTrue(
            $success
                ->response()
                ->headers()
                ->find(Date::class)
                ->match(
                    static fn() => true,
                    static fn() => false,
                ),
        );
    }

    public function testRedirection()
    {
        $redirection = ($this->curl)(Request::of(
            Url::of('http://github.com'),
            Method::get,
            ProtocolVersion::v11,
        ))->match(
            static fn() => null,
            static fn($redirection) => $redirection,
        );

        $this->assertInstanceOf(Redirection::class, $redirection);
        $this->assertSame(301, $redirection->response()->statusCode()->toInt());
        $this->assertSame(
            'Location: https://github.com/',
            $redirection
                ->response()
                ->headers()
                ->find(Location::class)
                ->match(
                    static fn($header) => $header->toString(),
                    static fn() => null,
                ),
        );
    }

    public function testClientError()
    {
        $error = ($this->curl)(Request::of(
            Url::of('https://github.com/innmind/unknown'),
            Method::get,
            ProtocolVersion::v11,
        ))->match(
            static fn() => null,
            static fn($error) => $error,
        );

        $this->assertInstanceOf(ClientError::class, $error);
        $this->assertSame(404, $error->response()->statusCode()->toInt());
        $this->assertSame(
            'Server: github.com',
            $error
                ->response()
                ->headers()
                ->get('server')
                ->match(
                    static fn($header) => $header->toString(),
                    static fn() => null,
                ),
        );
    }

    public function testFailure()
    {
        $error = ($this->curl)($request = Request::of(
            Url::of('http://localhost:8080/'),
            Method::get,
            ProtocolVersion::v11,
        ))->match(
            static fn() => null,
            static fn($error) => $error,
        );

        $this->assertInstanceOf(ConnectionFailed::class, $error);
        $this->assertSame($request, $error->request());
        $this->assertContains(
            $error->reason(),
            [
                'Could not connect to server',
                "Couldn't connect to server",
            ],
        );
    }

    public function testResponseBody()
    {
        $success = ($this->curl)(Request::of(
            Url::of('https://raw.githubusercontent.com/Innmind/Immutable/develop/LICENSE'),
            Method::get,
            ProtocolVersion::v11,
        ))->match(
            static fn($success) => $success,
            static fn($error) => $error,
        );

        $this->assertInstanceOf(Success::class, $success);

        $license = <<<LICENSE
        The MIT License (MIT)

        Copyright (c) 2015-present

        Permission is hereby granted, free of charge, to any person obtaining a copy
        of this software and associated documentation files (the "Software"), to deal
        in the Software without restriction, including without limitation the rights
        to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
        copies of the Software, and to permit persons to whom the Software is
        furnished to do so, subject to the following conditions:

        The above copyright notice and this permission notice shall be included in all
        copies or substantial portions of the Software.

        THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
        IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
        FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
        AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
        LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
        OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
        SOFTWARE.


        LICENSE;

        $this->assertSame(
            $license,
            $success->response()->body()->toString(),
        );
        // verify twice to make sure there is no problem iterating over the
        // response multiple times
        $this->assertSame(
            $license,
            $success->response()->body()->toString(),
        );
    }

    public function testHead()
    {
        $success = ($this->curl)(Request::of(
            Url::of('https://raw.githubusercontent.com/Innmind/Immutable/develop/LICENSE'),
            Method::head,
            ProtocolVersion::v11,
        ))->match(
            static fn($success) => $success,
            static fn($error) => $error,
        );

        $this->assertInstanceOf(Success::class, $success);

        $this->assertSame(
            '',
            $success->response()->body()->toString(),
        );
    }

    public function testPost()
    {
        $this
            ->forAll(Set\Unicode::strings())
            ->disableShrinking()
            ->then(function($body) {
                $success = ($this->curl)(Request::of(
                    Url::of('https://httpbin.org/post'),
                    Method::post,
                    ProtocolVersion::v11,
                    null,
                    Content::ofString($body),
                ))->match(
                    static fn($success) => $success,
                    static fn($error) => $error,
                );

                // we allow server errors as we don't control the stability of
                // the server
                $this->assertThat(
                    $success,
                    $this->logicalOr(
                        $this->isInstanceOf(Success::class),
                        $this->isInstanceOf(ServerError::class),
                    ),
                );

                if ($success instanceof ServerError) {
                    return;
                }

                $response = \json_decode(
                    $success->response()->body()->toString(),
                    true,
                );
                $data = $response['data'];

                if (Str::of($data)->startsWith('data:application/octet-stream;base64,')) {
                    $data = \base64_decode(\substr($data, 37), true);
                }

                $this->assertSame($body, $data);
            });
    }

    public function testPostLargeContent()
    {
        $capabilities = Streams::fromAmbientAuthority();
        $io = IO::of(static fn(?ElapsedPeriod $timeout) => match ($timeout) {
            null => $capabilities->watch()->waitForever(),
            default => $capabilities->watch()->timeoutAfter($timeout),
        });

        $memory = \memory_get_peak_usage();
        $success = ($this->curl)(Request::of(
            Url::of('https://httpbin.org/post'),
            Method::post,
            ProtocolVersion::v11,
            null,
            Content::atPath(
                $capabilities->readable(),
                $io->readable(),
                Path::of(__DIR__.'/../data/screenshot.png'),
            ),
        ))->match(
            static fn($success) => $success,
            static fn($error) => $error,
        );

        // we allow server errors as we don't control the stability of the server
        $this->assertThat(
            $success,
            $this->logicalOr(
                $this->isInstanceOf(Success::class),
                $this->isInstanceOf(ServerError::class),
            ),
        );
        // The file is a bit more than 2Mo, so if everything was kept in memory
        // the peak memory would be above 4Mo so we check that it is less than
        // 3Mo. It can't be less than 2Mo because the streams used have a memory
        // buffer of 2Mo before writing to disk
        $this->assertLessThan(3_698_688, \memory_get_peak_usage() - $memory);
    }

    public function testMinorVersionOfProtocolMayNotBePresent()
    {
        // Packagist respond with HTTP/2 instead of HTTP/2.0
        $success = ($this->curl)(Request::of(
            Url::of('https://packagist.org/search.json?q=innmind/'),
            Method::get,
            ProtocolVersion::v20,
        ))->match(
            static fn($success) => $success->response(),
            static fn($error) => $error,
        );

        $this->assertInstanceOf(Response::class, $success);
        $this->assertSame(ProtocolVersion::v20, $success->protocolVersion());
    }

    public function testConcurrency()
    {
        $request = Request::of(
            Url::of('https://github.com'),
            Method::get,
            ProtocolVersion::v11,
        );

        $start = \microtime(true);
        $_ = ($this->curl)($request)->match(
            static fn() => null,
            static fn() => null,
        );
        $forOneRequest = \microtime(true) - $start;

        $start = \microtime(true);
        $responses = Maybe::all(
            ($this->curl)($request)->maybe(),
            ($this->curl)($request)->maybe(),
        )
            ->map(Sequence::of(...))
            ->match(
                static fn($responses) => $responses,
                static fn() => Sequence::of(),
            )
            ->map(\get_class(...))
            ->toList();
        $this->assertSame([Success::class, Success::class], $responses);
        $this->assertLessThan(2 * $forOneRequest, \microtime(true) - $start);
    }

    public function testMaxConcurrency()
    {
        $curl = $this->curl->maxConcurrency(1);
        $request = Request::of(
            Url::of('https://github.com'),
            Method::get,
            ProtocolVersion::v11,
        );

        $start = \microtime(true);
        $_ = $curl($request)->match(
            static fn() => null,
            static fn() => null,
        );
        $forOneRequest = \microtime(true) - $start;

        $start = \microtime(true);
        $responses = Maybe::all(
            $curl($request)->maybe(),
            $curl($request)->maybe(),
            $curl($request)->maybe(),
        )
            ->map(Sequence::of(...))
            ->match(
                static fn($responses) => $responses,
                static fn() => Sequence::of(),
            )
            ->map(\get_class(...))
            ->toList();
        $this->assertSame([Success::class, Success::class, Success::class], $responses);
        // even though there are 3 request we check it takes more than 2 times
        // because depending on speed the 2 request could be faster than the
        // initial one
        $this->assertGreaterThanOrEqual(2 * $forOneRequest, \microtime(true) - $start);
    }

    public function testHeartbeat()
    {
        $heartbeat = 0;
        $curl = $this->curl->heartbeat(
            new ElapsedPeriod(1000),
            static function() use (&$heartbeat) {
                ++$heartbeat;
            },
        );

        $_ = $curl(Request::of(
            Url::of('https://en.wikipedia.org/wiki/Culture_of_the_United_Kingdom'),
            Method::get,
            ProtocolVersion::v11,
        ))->match(
            static fn() => null,
            static fn() => null,
        );

        $this->assertGreaterThan(1, $heartbeat);
    }

    public function testOutOfOrderUnwrapWithMaxConcurrency()
    {
        $curl = $this->curl->maxConcurrency(2);
        $request = Request::of(
            Url::of('https://github.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $responses = Sequence::of(...\range(0, 3))
            ->map(static fn() => $request)
            ->map($curl)
            ->reverse()
            ->map(static fn($either) => $either->match(
                static fn($success) => $success,
                static fn($error) => $error,
            ))
            ->map(static fn($data) => $data::class)
            ->toList();

        $this->assertSame(
            [Success::class, Success::class, Success::class, Success::class],
            $responses,
        );
    }

    public function testSubsequentRequestsAreCalledCorrectlyInsideFlatMaps()
    {
        $curl = $this->curl->maxConcurrency(2);
        $request = Request::of(
            Url::of('https://github.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $responses = Sequence::of(...\range(0, 1))
            ->map(static fn() => $request)
            ->map($curl)
            ->map(static fn($either) => $either->flatMap(
                static fn() => $curl($request),
            ))
            ->map(static fn($either) => $either->match(
                static fn($success) => $success,
                static fn($error) => $error,
            ))
            ->map(static fn($data) => $data::class)
            ->toList();

        $this->assertSame(
            [Success::class, Success::class],
            $responses,
        );
    }

    public function testReleaseResources()
    {
        $initialCount = \count(\get_resources('stream'));

        $request = Request::of(
            Url::of('https://github.com'),
            Method::get,
            ProtocolVersion::v11,
        );
        $responses = Sequence::of(...\range(0, 10))
            ->map(static fn() => $request)
            ->map($this->curl)
            ->map(static fn($either) => $either->match(
                static fn($success) => $success,
                static fn($error) => $error,
            ))
            ->toList();

        $this->assertNotSame($initialCount, \count(\get_resources('stream')));

        unset($responses);

        $this->assertSame($initialCount, \count(\get_resources('stream')));
    }

    public function testTimeout()
    {
        $request = Request::of(
            Url::of('https://httpbin.org/delay/2'),
            Method::get,
            ProtocolVersion::v11,
        );

        $result = ($this->curl)($request)->match(
            static fn($success) => $success,
            static fn() => null,
        );

        $this->assertInstanceOf(Success::class, $result);

        $request = Request::of(
            Url::of('https://httpbin.org/delay/2'),
            Method::get,
            ProtocolVersion::v11,
            Headers::of(
                Timeout::of(1),
            ),
        );

        $result = ($this->curl)($request)->match(
            static fn() => null,
            static fn($error) => $error,
        );

        $this->assertInstanceOf(Failure::class, $result);
        $this->assertSame('Timeout was reached', $result->reason());
    }

    // Don't know how to test MalformedResponse, ConnectionFailed, Information and ServerError
}
