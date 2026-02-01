<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
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
use Innmind\BlackBox\{
    PHPUnit\Framework\TestCase,
    PHPUnit\BlackBox,
    Set,
};
use Fixtures\Innmind\Url\Url as FUrl;

class FollowRedirectionsTest extends TestCase
{
    use BlackBox;

    public function testDoesntModifyNonRedirectionResults(): BlackBox\Proof
    {
        $request = Request::of(
            Url::of('/'),
            Method::get,
            ProtocolVersion::v11,
        );

        return $this
            ->forAll(Set::of(
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
            ->prove(function($result) use ($request) {
                $inner = Transport::via(static fn() => $result);
                $fulfill = Transport::followRedirections($inner);

                $this->assertEquals($result, $fulfill($request));
            });
    }

    public function testRedirectMaximum5Times(): BlackBox\Proof
    {
        return $this
            ->forAll(
                FUrl::any(),
                FUrl::any(),
                Set::of(Method::get, Method::head), // unsafe methods are not redirected
                Set::of(
                    StatusCode::movedPermanently,
                    StatusCode::found,
                    StatusCode::seeOther,
                    StatusCode::temporaryRedirect,
                    StatusCode::permanentlyRedirect,
                ),
                Set::of(
                    ProtocolVersion::v10,
                    ProtocolVersion::v11,
                    ProtocolVersion::v20,
                ),
            )
            ->prove(function($firstUrl, $newUrl, $method, $statusCode, $protocol) {
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
                $calls = 0;
                $inner = Transport::via(function($request) use (&$calls, $firstUrl, $expected) {
                    ++$calls;

                    if ($calls === 1) {
                        $this->assertSame($firstUrl, $request->url());
                    } else {
                        $this->assertNotSame($firstUrl, $request->url());
                    }

                    return $expected;
                });
                $fulfill = Transport::followRedirections($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
                $this->assertSame(6, $calls);
            });
    }

    public function testDoesntRedirectWhenNoLocationHeader(): BlackBox\Proof
    {
        return $this
            ->forAll(
                FUrl::any(),
                Set::of(...Method::cases()),
                Set::of(
                    StatusCode::movedPermanently,
                    StatusCode::found,
                    StatusCode::seeOther,
                    StatusCode::temporaryRedirect,
                    StatusCode::permanentlyRedirect,
                ),
                Set::of(
                    ProtocolVersion::v10,
                    ProtocolVersion::v11,
                    ProtocolVersion::v20,
                ),
            )
            ->prove(function($firstUrl, $method, $statusCode, $protocol) {
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
                $inner = Transport::via(static fn() => $expected);
                $fulfill = Transport::followRedirections($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
            });
    }

    public function testRedirectSeeOther(): BlackBox\Proof
    {
        return $this
            ->forAll(
                FUrl::any()
                    ->filter(static fn($url) => !$url->authority()->equals(Authority::none()))
                    ->filter(static fn($url) => $url->path()->absolute()),
                FUrl::any(),
                Set::of(...Method::cases()),
                Set::of(
                    ProtocolVersion::v10,
                    ProtocolVersion::v11,
                    ProtocolVersion::v20,
                ),
                Set::strings()->unicode(),
            )
            ->prove(function($firstUrl, $newUrl, $method, $protocol, $body) {
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
                $calls = 0;
                $inner = Transport::via(function($request) use (&$calls, $start, $newUrl, $protocol, $expected) {
                    ++$calls;

                    if ($calls === 1) {
                        $this->assertSame($start, $request);

                        return Either::left(new Redirection(
                            $start,
                            Response::of(
                                StatusCode::seeOther,
                                $protocol,
                                Headers::of(
                                    Location::of($newUrl),
                                ),
                            ),
                        ));
                    }

                    $this->assertSame(Method::get, $request->method());
                    $this->assertFalse($request->url()->authority()->equals(Authority::none()));
                    $this->assertTrue($request->url()->path()->absolute());
                    // not a direct comparison as new url might be a relative path
                    $this->assertStringEndsWith(
                        $newUrl->path()->toString(),
                        $request->url()->path()->toString(),
                    );
                    $this->assertSame($newUrl->query(), $request->url()->query());
                    $this->assertSame($newUrl->fragment(), $request->url()->fragment());
                    $this->assertSame($start->headers(), $request->headers());
                    $this->assertSame('', $request->body()->toString());

                    return $expected;
                });
                $fulfill = Transport::followRedirections($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
                $this->assertSame(2, $calls);
            });
    }

    public function testRedirect(): BlackBox\Proof
    {
        return $this
            ->forAll(
                FUrl::any()
                    ->filter(static fn($url) => !$url->authority()->equals(Authority::none()))
                    ->filter(static fn($url) => $url->path()->absolute()),
                FUrl::any(),
                Set::of(Method::get, Method::head), // unsafe methods are not redirected
                Set::of(
                    StatusCode::movedPermanently,
                    StatusCode::found,
                    StatusCode::temporaryRedirect,
                    StatusCode::permanentlyRedirect,
                ),
                Set::of(
                    ProtocolVersion::v10,
                    ProtocolVersion::v11,
                    ProtocolVersion::v20,
                ),
                Set::strings()->unicode(),
            )
            ->prove(function($firstUrl, $newUrl, $method, $statusCode, $protocol, $body) {
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
                $calls = 0;
                $inner = Transport::via(function($request) use (&$calls, $start, $newUrl, $statusCode, $protocol, $expected) {
                    ++$calls;

                    if ($calls === 1) {
                        $this->assertSame($start, $request);

                        return Either::left(new Redirection(
                            $start,
                            Response::of(
                                $statusCode,
                                $protocol,
                                Headers::of(
                                    Location::of($newUrl),
                                ),
                            ),
                        ));
                    }

                    $this->assertSame($start->method(), $request->method());
                    $this->assertFalse($request->url()->authority()->equals(Authority::none()));
                    $this->assertTrue($request->url()->path()->absolute());
                    // not a direct comparison as new url might be a relative path
                    $this->assertStringEndsWith(
                        $newUrl->path()->toString(),
                        $request->url()->path()->toString(),
                    );
                    $this->assertSame($newUrl->query(), $request->url()->query());
                    $this->assertSame($newUrl->fragment(), $request->url()->fragment());
                    $this->assertSame($start->headers(), $request->headers());
                    $this->assertSame($start->body(), $request->body());

                    return $expected;
                });
                $fulfill = Transport::followRedirections($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
                $this->assertSame(2, $calls);
            });
    }

    public function testDoesntRedirectUnsafeMethods(): BlackBox\Proof
    {
        return $this
            ->forAll(
                FUrl::any(),
                FUrl::any(),
                Set::of(...Method::cases())->filter(
                    static fn($method) => $method !== Method::get && $method !== Method::head,
                ),
                Set::of(
                    StatusCode::movedPermanently,
                    StatusCode::found,
                    StatusCode::temporaryRedirect,
                    StatusCode::permanentlyRedirect,
                ),
                Set::of(
                    ProtocolVersion::v10,
                    ProtocolVersion::v11,
                    ProtocolVersion::v20,
                ),
                Set::strings()->unicode(),
            )
            ->prove(function($firstUrl, $newUrl, $method, $statusCode, $protocol, $body) {
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
                $inner = Transport::via(static fn() => $expected);
                $fulfill = Transport::followRedirections($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
            });
    }
}
