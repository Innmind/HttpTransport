<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\Redirection;
use Innmind\Http\{
    Request,
    Response,
    Method,
    ProtocolVersion,
    Response\StatusCode,
};
use Innmind\Url\Url;
use Innmind\Immutable\Sequence;
use PHPUnit\Framework\TestCase;

class RedirectionTest extends TestCase
{
    public function testAcceptRedirectionfulResponses()
    {
        Sequence::of(...StatusCode::cases())
            ->filter(static fn($code) => $code->range() === StatusCode\Range::redirection)
            ->foreach(function($code) {
                $request = Request::of(
                    Url::of('/'),
                    Method::get,
                    ProtocolVersion::v11,
                );
                $response = Response::of(
                    $code,
                    $request->protocolVersion(),
                );

                $redirection = new Redirection($request, $response);
                $this->assertSame($request, $redirection->request());
                $this->assertSame($response, $redirection->response());
            });
    }

    public function testRejectOtherKindOfResponse()
    {
        Sequence::of(...StatusCode::cases())
            ->filter(static fn($code) => $code->range() !== StatusCode\Range::redirection)
            ->foreach(function($code) {
                $request = Request::of(
                    Url::of('/'),
                    Method::get,
                    ProtocolVersion::v11,
                );
                $response = Response::of(
                    $code,
                    $request->protocolVersion(),
                );

                try {
                    new Redirection($request, $response);
                    $this->fail('it should throw');
                } catch (\Throwable $e) {
                    $this->assertInstanceOf(\LogicException::class, $e);
                }
            });
    }
}
