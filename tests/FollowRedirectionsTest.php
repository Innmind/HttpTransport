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
    Message\Request,
    Message\Response,
    Message\StatusCode,
    Message\Method,
    ProtocolVersion,
    Headers,
    Header\Location,
};
use Innmind\Filesystem\File\Content;
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
        $this
            ->forAll(Set\Elements::of(
                Either::right(new Success(
                    $this->createMock(Request::class),
                    new Response\Response(
                        StatusCode::ok,
                        ProtocolVersion::v11,
                    ),
                )),
                Either::left(new Information(
                    $this->createMock(Request::class),
                    new Response\Response(
                        StatusCode::continue,
                        ProtocolVersion::v11,
                    ),
                )),
                Either::left(new ClientError(
                    $this->createMock(Request::class),
                    new Response\Response(
                        StatusCode::badRequest,
                        ProtocolVersion::v11,
                    ),
                )),
                Either::left(new ServerError(
                    $this->createMock(Request::class),
                    new Response\Response(
                        StatusCode::internalServerError,
                        ProtocolVersion::v11,
                    ),
                )),
                Either::left(new MalformedResponse(
                    $this->createMock(Request::class),
                )),
                Either::left(new ConnectionFailed(
                    $this->createMock(Request::class),
                    '',
                )),
                Either::left(new Failure(
                    $this->createMock(Request::class),
                    '',
                )),
            ))
            ->then(function($result) {
                $request = $this->createMock(Request::class);
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
                $start = new Request\Request(
                    $firstUrl,
                    $method,
                    $protocol,
                );
                $expected = Either::left(new Redirection(
                    $start,
                    new Response\Response(
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
                $start = new Request\Request(
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
                        new Response\Response(
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
                $start = new Request\Request(
                    $firstUrl,
                    $method,
                    $protocol,
                    null,
                    Content\Lines::ofContent($body),
                );
                $expected = Either::right(new Success(
                    $this->createMock(Request::class),
                    new Response\Response(
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
                                new Response\Response(
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
                FUrl::any(),
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
                $start = new Request\Request(
                    $firstUrl,
                    $method,
                    $protocol,
                    null,
                    Content\Lines::ofContent($body),
                );
                $expected = Either::right(new Success(
                    $this->createMock(Request::class),
                    new Response\Response(
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
                                new Response\Response(
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
                $start = new Request\Request(
                    $firstUrl,
                    $method,
                    $protocol,
                    null,
                    Content\Lines::ofContent($body),
                );
                $inner = $this->createMock(Transport::class);
                $inner
                    ->expects($this->once())
                    ->method('__invoke')
                    ->with($start)
                    ->willReturn($expected = Either::left(new Redirection(
                        $start,
                        new Response\Response(
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
