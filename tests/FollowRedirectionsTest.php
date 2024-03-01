<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\{
    FollowRedirections,
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
            FollowRedirections::of($this->createMock(Transport::class)),
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
                $inner = $this->createMock(Transport::class);
                $inner
                    ->expects($this->once())
                    ->method('__invoke')
                    ->with($request)
                    ->willReturn($result);
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
                $inner = $this->createMock(Transport::class);
                $inner
                    ->expects($matcher = $this->exactly(6))
                    ->method('__invoke')
                    ->willReturnCallback(function($request) use ($matcher, $start, $newUrl, $expected) {
                        match ($matcher->numberOfInvocations()) {
                            1 => $this->assertSame($start, $request),
                            default => $this->assertSame($newUrl, $request->url()),
                        };

                        return $expected;
                    });
                $fulfill = FollowRedirections::of($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
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
                $inner = $this->createMock(Transport::class);
                $inner
                    ->expects($this->once())
                    ->method('__invoke')
                    ->with($start)
                    ->willReturn($expected = Either::left(new Redirection(
                        $start,
                        Response::of(
                            $statusCode,
                            $protocol,
                        ),
                    )));
                $fulfill = FollowRedirections::of($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
            });
    }

    public function testRedirectSeeOther()
    {
        $this
            ->forAll(
                FUrl::any(),
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
                $inner = $this->createMock(Transport::class);
                $inner
                    ->expects($matcher = $this->exactly(2))
                    ->method('__invoke')
                    ->willReturnCallback(function($request) use ($matcher, $start, $newUrl, $protocol, $expected) {
                        if ($matcher->numberOfInvocations() === 1) {
                            $this->assertSame($start, $request);
                        } else {
                            $this->assertSame(Method::get, $request->method());
                            $this->assertSame($newUrl, $request->url());
                            $this->assertSame($start->headers(), $request->headers());
                            $this->assertSame('', $request->body()->toString());
                        }

                        return match ($matcher->numberOfInvocations()) {
                            1 => Either::left(new Redirection(
                                $start,
                                Response::of(
                                    StatusCode::seeOther,
                                    $protocol,
                                    Headers::of(
                                        Location::of($newUrl),
                                    ),
                                ),
                            )),
                            2 => $expected,
                        };
                    });
                $fulfill = FollowRedirections::of($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
            });
    }

    public function testRedirect()
    {
        $this
            ->forAll(
                FUrl::any()->filter(static fn($url) => !$url->authority()->equals(Authority::none())),
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
                $inner = $this->createMock(Transport::class);
                $inner
                    ->expects($matcher = $this->exactly(2))
                    ->method('__invoke')
                    ->willReturnCallback(function($request) use ($matcher, $start, $newUrl, $statusCode, $protocol, $expected) {
                        if ($matcher->numberOfInvocations() === 1) {
                            $this->assertSame($start, $request);
                        } else {
                            $this->assertSame($start->method(), $request->method());
                            $this->assertSame($newUrl, $request->url());
                            $this->assertSame($start->headers(), $request->headers());
                            $this->assertSame($start->body(), $request->body());
                        }

                        return match ($matcher->numberOfInvocations()) {
                            1 => Either::left(new Redirection(
                                $start,
                                Response::of(
                                    $statusCode,
                                    $protocol,
                                    Headers::of(
                                        Location::of($newUrl),
                                    ),
                                ),
                            )),
                            2 => $expected,
                        };
                    });
                $fulfill = FollowRedirections::of($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
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
                $inner = $this->createMock(Transport::class);
                $inner
                    ->expects($this->once())
                    ->method('__invoke')
                    ->with($start)
                    ->willReturn($expected = Either::left(new Redirection(
                        $start,
                        Response::of(
                            $statusCode,
                            $protocol,
                            Headers::of(
                                Location::of($newUrl),
                            ),
                        ),
                    )));
                $fulfill = FollowRedirections::of($inner);

                $result = $fulfill($start);

                $this->assertEquals($expected, $result);
            });
    }
}
