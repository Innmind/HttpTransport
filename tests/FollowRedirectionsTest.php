<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    FollowRedirections,
    Curl,
    Transport,
    Information,
    Success,
    Redirection,
    ClientError,
    ServerError,
    MalformedResponse,
    ConnectionFailed,
    Failure,
};
use Innmind\TimeContinuum\Clock;
use Innmind\Http\{
    Request,
    Response,
    Response\StatusCode,
    Method,
    ProtocolVersion,
    Headers,
    Header\Location,
};
use Innmind\Filesystem\File\Content;
use Innmind\Url\{
    Url,
    Authority,
};
use Innmind\Immutable\Either;
use PHPUnit\Framework\TestCase;
use Innmind\BlackBox\{
    PHPUnit\BlackBox,
    Set,
};
use Fixtures\Innmind\Url\Url as FUrl;

class FollowRedirectionsTest extends TestCase
{
    use BlackBox;

    public function testInterface()
    {
        $this->assertInstanceOf(
            Transport::class,
            FollowRedirections::of(Curl::of(Clock::live())),
        );
    }

    public function testDoesntModifyNonRedirectionResults()
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );

        $this
            ->forAll(Set\Elements::of(
                Either::right(new Success(
                    $request,
                    Response::of(
                        StatusCode::ok,
                        ProtocolVersion::v11,
                    ),
                )),
                Either::left(new Information(
                    $request,
                    Response::of(
                        StatusCode::continue,
                        ProtocolVersion::v11,
                    ),
                )),
                Either::left(new ClientError(
                    $request,
                    Response::of(
                        StatusCode::badRequest,
                        ProtocolVersion::v11,
                    ),
                )),
                Either::left(new ServerError(
                    $request,
                    Response::of(
                        StatusCode::internalServerError,
                        ProtocolVersion::v11,
                    ),
                )),
                Either::left(new MalformedResponse(
                    $request,
                )),
                Either::left(new ConnectionFailed(
                    $request,
                    '',
                )),
                Either::left(new Failure(
                    $request,
                    '',
                )),
            ))
            ->then(function($result) use ($request) {
                $inner = new class($result) implements Transport {
                    public function __construct(
                        private $result,
                    ) {
                    }

                    public function __invoke(Request $request): Either
                    {
                        return $this->result;
                    }
                };
                $fulfill = FollowRedirections::of($inner);

                $this->assertEquals($result, $fulfill($request));
            });
    }

    public function testRedirectMaximum5Times()
    {
        $this
            ->forAll(
                FUrl::any(),
                FUrl::any(),
                Set\Elements::of(Method::get, Method::head), // unsafe methods are not redirected
                Set\Elements::of(
                    StatusCode::movedPermanently,
                    StatusCode::found,
                    StatusCode::seeOther,
                    StatusCode::temporaryRedirect,
                    StatusCode::permanentlyRedirect,
                ),
                Set\Elements::of(
                    ProtocolVersion::v10,
                    ProtocolVersion::v11,
                    ProtocolVersion::v20,
                ),
            )
            ->then(function($firstUrl, $newUrl, $method, $statusCode, $protocol) {
                $start = Request::of(
                    $firstUrl,
                    $method,
                    $protocol,
                );
                $expected = Either::left(new Redirection(
                    $start,
                    Response::of(
                        $statusCode,
                        $protocol,
                        Headers::of(
                            Location::of($newUrl),
                        ),
                    ),
                ));
                $inner = new class($this, $firstUrl, $expected) implements Transport {
                    public function __construct(
                        private $test,
                        private $firstUrl,
                        private $expected,
                        public int $calls = 0,
                    ) {
                    }

                    public function __invoke(Request $request): Either
                    {
                        ++$this->calls;

                        if ($this->firstUrl) {
                            $this->test->assertSame($this->firstUrl, $request->url());
                            $this->firstUrl = null;
                        } else {
                            $this->test->assertNotSame($this->firstUrl, $request->url());
                        }

                        return $this->expected;
                    }
                };
                $fulfill = FollowRedirections::of($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
                $this->assertSame(6, $inner->calls);
            });
    }

    public function testDoesntRedirectWhenNoLocationHeader()
    {
        $this
            ->forAll(
                FUrl::any(),
                Set\Elements::of(...Method::cases()),
                Set\Elements::of(
                    StatusCode::movedPermanently,
                    StatusCode::found,
                    StatusCode::seeOther,
                    StatusCode::temporaryRedirect,
                    StatusCode::permanentlyRedirect,
                ),
                Set\Elements::of(
                    ProtocolVersion::v10,
                    ProtocolVersion::v11,
                    ProtocolVersion::v20,
                ),
            )
            ->then(function($firstUrl, $method, $statusCode, $protocol) {
                $start = Request::of(
                    $firstUrl,
                    $method,
                    $protocol,
                );
                $expected = Either::left(new Redirection(
                    $start,
                    Response::of(
                        $statusCode,
                        $protocol,
                    ),
                ));
                $inner = new class($expected) implements Transport {
                    public function __construct(
                        private $expected,
                    ) {
                    }

                    public function __invoke(Request $request): Either
                    {
                        return $this->expected;
                    }
                };
                $fulfill = FollowRedirections::of($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
            });
    }

    public function testRedirectSeeOther()
    {
        $this
            ->forAll(
                FUrl::any()
                    ->filter(static fn($url) => !$url->authority()->equals(Authority::none()))
                    ->filter(static fn($url) => $url->path()->absolute()),
                FUrl::any(),
                Set\Elements::of(...Method::cases()),
                Set\Elements::of(
                    ProtocolVersion::v10,
                    ProtocolVersion::v11,
                    ProtocolVersion::v20,
                ),
                Set\Unicode::strings(),
            )
            ->then(function($firstUrl, $newUrl, $method, $protocol, $body) {
                $start = Request::of(
                    $firstUrl,
                    $method,
                    $protocol,
                    null,
                    Content::ofString($body),
                );
                $expected = Either::right(new Success(
                    clone $start,
                    Response::of(
                        StatusCode::ok,
                        $protocol,
                    ),
                ));
                $inner = new class($this, $start, $newUrl, $protocol, $expected) implements Transport {
                    public function __construct(
                        private $test,
                        private $start,
                        private $newUrl,
                        private $protocol,
                        private $expected,
                        public int $calls = 0,
                    ) {
                    }

                    public function __invoke(Request $request): Either
                    {
                        ++$this->calls;

                        if ($this->calls === 1) {
                            $this->test->assertSame($this->start, $request);

                            return Either::left(new Redirection(
                                $this->start,
                                Response::of(
                                    StatusCode::seeOther,
                                    $this->protocol,
                                    Headers::of(
                                        Location::of($this->newUrl),
                                    ),
                                ),
                            ));
                        }

                        $this->test->assertSame(Method::get, $request->method());
                        $this->test->assertFalse($request->url()->authority()->equals(Authority::none()));
                        $this->test->assertTrue($request->url()->path()->absolute());
                        // not a direct comparison as new url might be a relative path
                        $this->test->assertStringEndsWith(
                            $this->newUrl->path()->toString(),
                            $request->url()->path()->toString(),
                        );
                        $this->test->assertSame($this->newUrl->query(), $request->url()->query());
                        $this->test->assertSame($this->newUrl->fragment(), $request->url()->fragment());
                        $this->test->assertSame($this->start->headers(), $request->headers());
                        $this->test->assertSame('', $request->body()->toString());

                        return $this->expected;
                    }
                };
                $fulfill = FollowRedirections::of($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
                $this->assertSame(2, $inner->calls);
            });
    }

    public function testRedirect()
    {
        $this
            ->forAll(
                FUrl::any()
                    ->filter(static fn($url) => !$url->authority()->equals(Authority::none()))
                    ->filter(static fn($url) => $url->path()->absolute()),
                FUrl::any(),
                Set\Elements::of(Method::get, Method::head), // unsafe methods are not redirected
                Set\Elements::of(
                    StatusCode::movedPermanently,
                    StatusCode::found,
                    StatusCode::temporaryRedirect,
                    StatusCode::permanentlyRedirect,
                ),
                Set\Elements::of(
                    ProtocolVersion::v10,
                    ProtocolVersion::v11,
                    ProtocolVersion::v20,
                ),
                Set\Unicode::strings(),
            )
            ->then(function($firstUrl, $newUrl, $method, $statusCode, $protocol, $body) {
                $start = Request::of(
                    $firstUrl,
                    $method,
                    $protocol,
                    null,
                    Content::ofString($body),
                );
                $expected = Either::right(new Success(
                    clone $start,
                    Response::of(
                        StatusCode::ok,
                        $protocol,
                    ),
                ));
                $inner = new class($this, $start, $newUrl, $statusCode, $protocol, $expected) implements Transport {
                    public function __construct(
                        private $test,
                        private $start,
                        private $newUrl,
                        private $statusCode,
                        private $protocol,
                        private $expected,
                        public int $calls = 0,
                    ) {
                    }

                    public function __invoke(Request $request): Either
                    {
                        ++$this->calls;

                        if ($this->calls === 1) {
                            $this->test->assertSame($this->start, $request);

                            return Either::left(new Redirection(
                                $this->start,
                                Response::of(
                                    $this->statusCode,
                                    $this->protocol,
                                    Headers::of(
                                        Location::of($this->newUrl),
                                    ),
                                ),
                            ));
                        }

                        $this->test->assertSame($this->start->method(), $request->method());
                        $this->test->assertFalse($request->url()->authority()->equals(Authority::none()));
                        $this->test->assertTrue($request->url()->path()->absolute());
                        // not a direct comparison as new url might be a relative path
                        $this->test->assertStringEndsWith(
                            $this->newUrl->path()->toString(),
                            $request->url()->path()->toString(),
                        );
                        $this->test->assertSame($this->newUrl->query(), $request->url()->query());
                        $this->test->assertSame($this->newUrl->fragment(), $request->url()->fragment());
                        $this->test->assertSame($this->start->headers(), $request->headers());
                        $this->test->assertSame($this->start->body(), $request->body());

                        return $this->expected;
                    }
                };
                $fulfill = FollowRedirections::of($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
                $this->assertSame(2, $inner->calls);
            });
    }

    public function testDoesntRedirectUnsafeMethods()
    {
        $this
            ->forAll(
                FUrl::any(),
                FUrl::any(),
                Set\Elements::of(...Method::cases())->filter(
                    static fn($method) => $method !== Method::get && $method !== Method::head,
                ),
                Set\Elements::of(
                    StatusCode::movedPermanently,
                    StatusCode::found,
                    StatusCode::temporaryRedirect,
                    StatusCode::permanentlyRedirect,
                ),
                Set\Elements::of(
                    ProtocolVersion::v10,
                    ProtocolVersion::v11,
                    ProtocolVersion::v20,
                ),
                Set\Unicode::strings(),
            )
            ->then(function($firstUrl, $newUrl, $method, $statusCode, $protocol, $body) {
                $start = Request::of(
                    $firstUrl,
                    $method,
                    $protocol,
                    null,
                    Content::ofString($body),
                );
                $expected = Either::left(new Redirection(
                    $start,
                    Response::of(
                        $statusCode,
                        $protocol,
                        Headers::of(
                            Location::of($newUrl),
                        ),
                    ),
                ));
                $inner = new class($expected) implements Transport {
                    public function __construct(
                        private $expected,
                    ) {
                    }

                    public function __invoke(Request $request): Either
                    {
                        return $this->expected;
                    }
                };
                $fulfill = FollowRedirections::of($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
            });
    }
}
