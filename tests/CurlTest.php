<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    Curl,
    Transport,
    Success,
    Redirection,
    ClientError,
    Failure,
};
use Innmind\Http\{
    Message\Request\Request,
    Message\Method,
    ProtocolVersion,
    Header\Date,
    Header\Location,
};
use Innmind\Filesystem\File\Content;
use Innmind\TimeContinuum\Earth\Clock;
use Innmind\Url\{
    Url,
    Path,
};
use Innmind\Immutable\Str;
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
        $success = ($this->curl)(new Request(
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
        $redirection = ($this->curl)(new Request(
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
        $error = ($this->curl)(new Request(
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
            'Server: GitHub.com',
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
        $error = ($this->curl)($request = new Request(
            Url::of('http://localhost:8080/'),
            Method::get,
            ProtocolVersion::v11,
        ))->match(
            static fn() => null,
            static fn($error) => $error,
        );

        $this->assertInstanceOf(Failure::class, $error);
        $this->assertSame($request, $error->request());
        $this->assertSame('Curl failed to execute the request', $error->reason());
    }

    public function testResponseBody()
    {
        $success = ($this->curl)(new Request(
            Url::of('https://raw.githubusercontent.com/Innmind/Immutable/develop/LICENSE'),
            Method::get,
            ProtocolVersion::v11,
        ))->match(
            static fn($success) => $success,
            static fn() => null,
        );

        $this->assertInstanceOf(Success::class, $success);

        $extraSpace = ' ';
        $license = <<<LICENSE
        The MIT License (MIT)

        Copyright (c) 2015$extraSpace

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
        $success = ($this->curl)(new Request(
            Url::of('https://raw.githubusercontent.com/Innmind/Immutable/develop/LICENSE'),
            Method::head,
            ProtocolVersion::v11,
        ))->match(
            static fn($success) => $success,
            static fn() => null,
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
                $success = ($this->curl)(new Request(
                    Url::of('https://httpbin.org/post'),
                    Method::post,
                    ProtocolVersion::v11,
                    null,
                    Content\Lines::ofContent($body),
                ))->match(
                    static fn($success) => $success,
                    static fn() => null,
                );

                $this->assertInstanceOf(Success::class, $success);
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
        $memory = \memory_get_peak_usage();
        $success = ($this->curl)(new Request(
            Url::of('https://httpbin.org/post'),
            Method::post,
            ProtocolVersion::v11,
            null,
            Content\AtPath::of(Path::of(__DIR__.'/../data/screenshot.png')),
        ))->match(
            static fn($success) => $success,
            static fn() => null,
        );

        $this->assertInstanceOf(Success::class, $success);
        // The file is a bit more than 2Mo, so if everything was kept in memory
        // the peak memory would be above 4Mo so we check that it is less than
        // 3Mo. It can't be less than 2Mo because the streams used have a memory
        // buffer of 2Mo before writing to disk
        $this->assertLessThan(3_698_688, \memory_get_peak_usage() - $memory);
    }

    // Don't know how to test MalformedResponse, ConnectionFailed, Information and ServerError
}